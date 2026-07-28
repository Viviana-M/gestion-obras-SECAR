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
        ]);

        @set_time_limit(0);

        // El período se toma del NOMBRE del archivo (patrón AAAA_MM, ej. "2026_06.xlsx").
        $nombre  = $request->file('archivo')->getClientOriginalName();
        $periodo = $this->periodoDesdeNombre($nombre);
        if (! $periodo) {
            return back()->with('error',
                'El nombre del archivo debe incluir el período con el patrón AAAA_MM (ej. "2026_06.xlsx"). '.
                "Recibí \"{$nombre}\". Renómbralo y vuelve a subir.");
        }
        [$mesArchivo, $anioArchivo] = $periodo;

        // Reemplazar la planilla del mismo período (borrar e insertar).
        AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->delete();

        Excel::import(new AutoliquidacionImport($mesArchivo, $anioArchivo), $request->file('archivo'));

        $base     = AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo);
        $filas    = (clone $base)->count();
        $personas = (clone $base)->distinct()->count('cedula');
        $empresa  = (float) (clone $base)->sum('aporte_empresa');
        $totalFmt = '$'.number_format($empresa, 0, ',', '.');

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $mesArchivo, 'anio' => $anioArchivo])
            ->with('success', "Planilla {$mesArchivo}/{$anioArchivo}: {$filas} filas, {$personas} personas, aporte empresa {$totalFmt}.");
    }

    /**
     * Extrae [mes, anio] del nombre del archivo con patrón AAAA_MM (ej. "2026_06.xlsx",
     * "PILA_2026_06_final.xlsx"). Exige año 20xx y mes 01-12. Null si no cumple.
     */
    private function periodoDesdeNombre(string $nombre): ?array
    {
        if (! preg_match('/(20\d{2})[_-](0[1-9]|1[0-2])/', $nombre, $m)) {
            return null;
        }

        return [(int) $m[2], (int) $m[1]]; // [mes, anio]
    }
}
