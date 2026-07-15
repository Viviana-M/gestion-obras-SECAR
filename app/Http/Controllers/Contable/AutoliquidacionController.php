<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AutoliquidacionController extends Controller
{
    public function index(Request $request)
    {
        // Períodos disponibles (para el selector).
        $periodos = AutoliquidacionAporte::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        $mes  = (int) $request->get('mes', $periodos->first()->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $periodos->first()->anio ?? (int) date('Y'));

        $base = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio);

        $resumen = [
            'personas'        => (clone $base)->distinct()->count('cedula'),
            'filas'           => (clone $base)->count(),
            'aporte_empresa'  => (float) (clone $base)->sum('aporte_empresa'),
            'aporte_empleado' => (float) (clone $base)->sum('aporte_empleado'),
            'real_descontado' => (float) (clone $base)->sum('real_descontado'),
        ];

        $porUN = (clone $base)
            ->selectRaw('un_codigo, MAX(un_descripcion) as un_descripcion,
                COUNT(DISTINCT cedula) as personas,
                SUM(aporte_empresa) as aporte_empresa,
                SUM(aporte_empleado) as aporte_empleado,
                SUM(real_descontado) as real_descontado')
            ->groupBy('un_codigo')
            ->orderByDesc('aporte_empresa')
            ->get();

        $porConcepto = (clone $base)
            ->selectRaw('concepto_pila,
                SUM(aporte_empresa) as aporte_empresa,
                SUM(aporte_empleado) as aporte_empleado,
                SUM(real_descontado) as real_descontado')
            ->groupBy('concepto_pila')
            ->orderByDesc('aporte_empresa')
            ->get();

        return view('contable.autoliquidacion', compact(
            'periodos', 'mes', 'anio', 'resumen', 'porUN', 'porConcepto'
        ));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:51200',
            'mes'     => 'nullable|integer|between:1,12',
            'anio'    => 'nullable|integer|min:2020',
        ]);

        $ruta = $request->file('archivo')->getRealPath();

        // El período se deriva de la columna Fecha (primera fila de datos).
        $periodo = $this->derivarPeriodo($ruta);
        if (! $periodo) {
            return back()->with('error', 'No pude leer la fecha del archivo para determinar el período. Verifica la columna Fecha.');
        }
        [$mesArchivo, $anioArchivo] = $periodo;

        // Si el usuario eligió mes/año, debe coincidir con el del archivo.
        if ($request->filled('mes') && $request->filled('anio')) {
            if ((int) $request->mes !== $mesArchivo || (int) $request->anio !== $anioArchivo) {
                return back()->with('error',
                    "El archivo corresponde a {$mesArchivo}/{$anioArchivo}, pero seleccionaste {$request->mes}/{$request->anio}. No se cargó.");
            }
        }

        // Reemplazar la planilla del mismo período (borrar e insertar).
        AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->delete();

        Excel::import(new AutoliquidacionImport($mesArchivo, $anioArchivo), $request->file('archivo'));

        $filas = AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->count();

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $mesArchivo, 'anio' => $anioArchivo])
            ->with('success', "Planilla cargada: {$filas} filas para el período {$mesArchivo}/{$anioArchivo}.");
    }

    /** Lee la fecha de la primera fila de datos (F2) y devuelve [mes, anio]. */
    private function derivarPeriodo(string $ruta): ?array
    {
        try {
            $reader = IOFactory::createReaderForFile($ruta);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row <= 2; // solo encabezado + primera fila de datos
                }
            });
            $sheet = $reader->load($ruta)->getActiveSheet();
            $valor = $sheet->getCell('F2')->getValue();
        } catch (\Throwable $e) {
            return null;
        }

        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            if (is_numeric($valor)) {
                $fecha = ExcelDate::excelToDateTimeObject((float) $valor);
            } else {
                $fecha = new \DateTime(trim((string) $valor));
            }
        } catch (\Throwable $e) {
            return null;
        }

        return [(int) $fecha->format('n'), (int) $fecha->format('Y')];
    }
}
