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
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005') // no mostrar valores en 0
            ->orderByDesc('aporte_empresa')
            ->get();

        $porConcepto = (clone $base)
            ->selectRaw('concepto_pila, SUM(aporte_empresa) as aporte_empresa')
            ->groupBy('concepto_pila')
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005') // no mostrar valores en 0
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
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005') // no mostrar personas con total en 0
            ->orderByDesc('total')
            ->get();

        $detalle = (clone $basePersona)
            ->selectRaw('cedula, concepto_pila, SUM(aporte_empresa) as aporte')
            ->groupBy('cedula', 'concepto_pila')
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005') // no mostrar conceptos en 0
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

    /**
     * Vista "Seguridad social por persona" (maestro-detalle): buscador + lista a la
     * izquierda y detalle con dona por concepto a la derecha. Vista para Contabilidad,
     * Nómina o admin.
     */
    public function personas(Request $request)
    {
        abort_unless($this->puedeVerSeguridad($request->user()), 403,
            'No tienes permiso para ver Seguridad social por persona.');

        $periodos = AutoliquidacionAporte::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        $mes  = (int) $request->get('mes', $periodos->first()->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $periodos->first()->anio ?? (int) date('Y'));
        $un   = trim((string) $request->get('un', '')) ?: null;

        $unidades = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)
            ->whereNotNull('un_codigo')->distinct()->orderBy('un_codigo')->pluck('un_codigo');

        ['personas' => $personas, 'total' => $total] = $this->datosSeguridadPersona($mes, $anio, $un);
        $numPersonas = $personas->count();
        $promedio    = $numPersonas ? $total / $numPersonas : 0.0;

        return view('contable.seguridad-social', compact(
            'periodos', 'mes', 'anio', 'un', 'unidades', 'personas', 'total', 'numPersonas', 'promedio'
        ));
    }

    /**
     * Descarga a Excel del costo de seguridad social por persona: persona, cédula, UN,
     * total Aporte empresa y una columna por cada concepto PILA.
     */
    public function personasExcel(Request $request)
    {
        abort_unless($this->puedeVerSeguridad($request->user()), 403,
            'No tienes permiso para ver Seguridad social por persona.');

        $mes  = (int) $request->get('mes', (int) date('n'));
        $anio = (int) $request->get('anio', (int) date('Y'));
        $un   = trim((string) $request->get('un', '')) ?: null;

        ['personas' => $personas, 'total' => $total] = $this->datosSeguridadPersona($mes, $anio, $un);

        $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        $periodo = ($nombresMes[$mes] ?? $mes).'_'.$anio.($un ? '_'.$un : '');

        return Excel::download(
            new \App\Exports\SeguridadSocialPersonaExport($personas->all(), $total),
            'Seguridad_social_por_persona_'.$periodo.'.xlsx'
        );
    }

    /** ¿Puede ver Seguridad social por persona? Contabilidad, Nómina o admin. */
    private function puedeVerSeguridad($user): bool
    {
        return $user->esAdmin()
            || $user->puedeVerModulo('contabilidad')
            || $user->puedeVerModulo('nomina');
    }

    /**
     * Arma el costo de seguridad social por persona (solo Aporte empresa) para un período
     * y UN opcional: total por persona, UN dominante y desglose por concepto PILA. Ordenado
     * de mayor a menor por total y sin valores en 0.
     *
     * @return array{personas: \Illuminate\Support\Collection, total: float}
     */
    private function datosSeguridadPersona(int $mes, int $anio, ?string $un): array
    {
        $base = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)
            ->when($un, fn ($q) => $q->where('un_codigo', $un));

        $totales = (clone $base)
            ->selectRaw('cedula, MAX(razon_social) as nombre, SUM(aporte_empresa) as total')
            ->groupBy('cedula')
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005')
            ->orderByDesc('total')
            ->get();

        $conceptos = (clone $base)
            ->selectRaw('cedula, concepto_pila, SUM(aporte_empresa) as aporte')
            ->groupBy('cedula', 'concepto_pila')
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005')
            ->orderByDesc('aporte')
            ->get()->groupBy('cedula');

        $unPorPersona = (clone $base)
            ->selectRaw('cedula, un_codigo, SUM(aporte_empresa) as apo')
            ->groupBy('cedula', 'un_codigo')
            ->orderByDesc('apo')
            ->get()->groupBy('cedula');

        $personas = $totales->map(fn ($p) => [
            'cedula'    => $p->cedula,
            'nombre'    => $p->nombre ?: '—',
            'un'        => optional(($unPorPersona[$p->cedula] ?? collect())->first())->un_codigo ?: '—',
            'total'     => (float) $p->total,
            'conceptos' => ($conceptos[$p->cedula] ?? collect())
                ->map(fn ($d) => ['concepto' => $d->concepto_pila ?: '—', 'aporte' => (float) $d->aporte])
                ->values()->all(),
        ])->values();

        return ['personas' => $personas, 'total' => (float) $totales->sum('total')];
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
