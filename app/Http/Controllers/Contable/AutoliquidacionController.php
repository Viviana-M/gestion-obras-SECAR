<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AutoliquidacionController extends Controller
{
    public function index(Request $request)
    {
        // Períodos disponibles (para el selector).
        $periodos = AutoliquidacionAporte::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        $mes  = (int) $request->get('mes', $periodos->first()->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $periodos->first()->anio ?? (int) date('Y'));
        $un   = trim((string) $request->get('un', '')) ?: null; // filtro opcional por UN

        $base = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio);

        $resumen = [
            'personas'       => (clone $base)->distinct()->count('cedula'),
            'filas'          => (clone $base)->count(),
            'aporte_empresa' => (float) (clone $base)->sum('aporte_empresa'),
        ];

        $porUN = (clone $base)
            ->selectRaw('un_codigo,
                COUNT(DISTINCT cedula) as personas,
                SUM(aporte_empresa) as aporte_empresa')
            ->groupBy('un_codigo')
            ->orderByDesc('aporte_empresa')
            ->get();

        $porConcepto = (clone $base)
            ->selectRaw('concepto_pila, SUM(aporte_empresa) as aporte_empresa')
            ->groupBy('concepto_pila')
            ->orderByDesc('aporte_empresa')
            ->get();

        // UNs disponibles en el período (para el filtro del costo por persona).
        $unidades = (clone $base)->whereNotNull('un_codigo')
            ->distinct()->orderBy('un_codigo')->pluck('un_codigo');

        // Costo de seguridad social POR PERSONA (solo Aporte empresa), con filtro opcional
        // por UN. Total por persona + desglose por concepto PILA. Ordenado de mayor a menor.
        $basePersona = (clone $base)->when($un, fn ($q) => $q->where('un_codigo', $un));

        $totales = (clone $basePersona)
            ->selectRaw('cedula, MAX(razon_social) as nombre, SUM(aporte_empresa) as total')
            ->groupBy('cedula')
            ->orderByDesc('total')
            ->get();

        $detalle = (clone $basePersona)
            ->selectRaw('cedula, concepto_pila, SUM(aporte_empresa) as aporte')
            ->groupBy('cedula', 'concepto_pila')
            ->orderByDesc('aporte')
            ->get()
            ->groupBy('cedula');

        $porPersona = $totales->map(fn ($p) => [
            'cedula'    => $p->cedula,
            'nombre'    => $p->nombre,
            'total'     => (float) $p->total,
            'conceptos' => ($detalle[$p->cedula] ?? collect())
                ->map(fn ($d) => ['concepto' => $d->concepto_pila, 'aporte' => (float) $d->aporte])
                ->values()->all(),
        ]);

        $totalPersonas = (float) $totales->sum('total');

        return view('contable.autoliquidacion', compact(
            'periodos', 'mes', 'anio', 'un', 'resumen', 'porUN', 'porConcepto',
            'unidades', 'porPersona', 'totalPersonas'
        ));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        @set_time_limit(0);

        $archivo = $request->file('archivo');

        // El período se toma de la columna FECHA (ej. 2026-04-30 → abril 2026). Se lee una
        // muestra del archivo y se usa la primera fecha válida encontrada.
        $periodo = $this->periodoDesdeFecha($archivo);
        if (! $periodo) {
            return back()->with('error',
                'No pude leer el período de la columna "Fecha". Revisa que el archivo tenga '.
                'las 13 columnas estándar y que la columna Fecha traiga fechas válidas (ej. 2026-04-30).');
        }
        [$mesArchivo, $anioArchivo] = $periodo;

        // Reemplazar la planilla del mismo período (borrar e insertar).
        AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->delete();

        Excel::import(new AutoliquidacionImport($mesArchivo, $anioArchivo), $archivo);

        $base     = AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo);
        $filas    = (clone $base)->count();
        $personas = (clone $base)->distinct()->count('cedula');
        $empresa  = (float) (clone $base)->sum('aporte_empresa');
        $totalFmt = '$'.number_format($empresa, 0, ',', '.');

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $mesArchivo, 'anio' => $anioArchivo])
            ->with('success', "Planilla {$mesArchivo}/{$anioArchivo}: {$filas} filas, {$personas} personas, aporte empresa {$totalFmt}.");
    }

    /**
     * Lee el período [mes, anio] de la columna Fecha (índice 5) de la planilla, tomando
     * la primera fila de datos con una fecha válida. Null si ninguna es legible.
     */
    private function periodoDesdeFecha(\Illuminate\Http\UploadedFile $archivo): ?array
    {
        $hojas = Excel::toArray(new class {}, $archivo);
        $filas = $hojas[0] ?? [];

        foreach ($filas as $i => $fila) {
            if ($i === 0) {
                continue; // encabezado
            }
            $fecha = AutoliquidacionImport::parsearFecha($fila[5] ?? null);
            if ($fecha) {
                return [(int) $fecha->month, (int) $fecha->year];
            }
        }

        return null;
    }
}
