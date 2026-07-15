<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CierreObrasController extends Controller
{
    public function index()
    {
        $proyectosCerrados = ProyectoCerrado::with('usuario')
            ->orderByDesc('fecha_cierre')
            ->get();

        $totalCerrados = $proyectosCerrados->count();

        return view('contable.cierre-obras', compact('proyectosCerrados', 'totalCerrados'));
    }

    public function cargarExcel(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $datos = Excel::toArray([], $request->file('archivo'))[0];
        $importados = 0;
        $omitidos   = 0;

        foreach ($datos as $i => $fila) {
            if ($i === 0) continue; // Saltar encabezado

            $codigo = trim($fila[0] ?? '');
            if (empty($codigo)) continue;

            $nombre     = trim($fila[1] ?? '');
            $fechaCierre = $this->normalizarFecha($fila[2] ?? null);
            $tipoCierre = trim($fila[3] ?? 'total');
            $observacion = trim($fila[4] ?? '');

            // Buscar nombre en registro_financieros si no viene en el Excel
            if (empty($nombre)) {
                $registro = RegistroFinanciero::where('codigo_proyecto', $codigo)
                    ->first();
                $nombre = $registro ? $registro->nombre_proyecto : $codigo;
            }

            $existe = ProyectoCerrado::where('codigo_proyecto', $codigo)->exists();
            if ($existe) {
                $omitidos++;
                continue;
            }

            ProyectoCerrado::create([
                'codigo_proyecto' => $codigo,
                'nombre_proyecto' => $nombre,
                'fecha_cierre'    => $fechaCierre,
                'tipo_cierre'     => in_array($tipoCierre, ['total', 'parcial']) ? $tipoCierre : 'total',
                'observacion'     => $observacion,
                'origen'          => 'excel',
                'user_id'         => auth()->id(),
            ]);
            $importados++;
        }

        return back()->with('success', "Importados: {$importados} proyectos. Omitidos (ya existían): {$omitidos}.");
    }

    public function cerrarManual(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $request->validate([
            'codigo_proyecto' => 'required|string',
            'fecha_cierre'    => 'required|date',
            'tipo_cierre'     => 'required|in:total,parcial',
        ]);

        $nombre = RegistroFinanciero::where('codigo_proyecto', $request->codigo_proyecto)
            ->value('nombre_proyecto') ?? $request->codigo_proyecto;

        ProyectoCerrado::updateOrCreate(
            ['codigo_proyecto' => $request->codigo_proyecto],
            [
                'nombre_proyecto' => $nombre,
                'fecha_cierre'    => $request->fecha_cierre,
                'tipo_cierre'     => $request->tipo_cierre,
                'observacion'     => $request->observacion,
                'origen'          => 'manual',
                'user_id'         => auth()->id(),
            ]
        );

        return back()->with('success', 'Proyecto cerrado registrado correctamente.');
    }

    public function destroy($id)
    {
        abort_unless(auth()->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        ProyectoCerrado::findOrFail($id)->delete();
        return back()->with('success', 'Registro eliminado correctamente.');
    }

    /**
     * Normaliza la fecha de cierre que llega desde el Excel. La celda puede venir
     * como serial de Excel (numérico), como texto d/m/Y, o como fecha ISO. Sin
     * esta normalización, el cast 'date' del modelo interpretaba el serial como
     * timestamp UNIX (1970) o parseaba d/m/Y como m/d (mes inválido).
     */
    private function normalizarFecha($valor): string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return now()->toDateString();
        }

        // Serial de fecha de Excel (número de días desde 1900).
        if (is_numeric($valor)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $valor)
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
                return now()->toDateString();
            }
        }

        $texto = trim((string) $valor);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'd/m/y'] as $formato) {
            $fecha  = \DateTime::createFromFormat($formato, $texto);
            $errores = \DateTime::getLastErrors();
            $sinErrores = $errores === false
                || (empty($errores['warning_count']) && empty($errores['error_count']));
            if ($fecha !== false && $sinErrores) {
                return $fecha->format('Y-m-d');
            }
        }

        return now()->toDateString();
    }
}