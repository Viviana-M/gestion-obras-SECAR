<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\ObraEstado;
use App\Models\ObservacionRevision;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Obras ABIERTAS que están costando (costo reconocido en cuenta 6) pero YA NO
 * tienen saldo en la cuenta 14 (neto ≈ 0). No hay nada que distribuir; hay que
 * revisar si se cierran. Es una lista de revisión, complemento de Distribución.
 */
class ObrasRevisionController extends Controller
{
    private const UMBRAL = 0.5;

    public function index(Request $request)
    {
        return view('operativo.obras-revision', [
            'obras'       => $this->datosObras(),
            'puedeEditar' => $request->user()->puedeEditarModulo('operacion'),
        ]);
    }

    /** Descarga a Excel de las obras en revisión (mismos datos que la pantalla). */
    public function exportarExcel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403,
            'No tienes permiso para ver Operación.');

        $obras = $this->datosObras();

        $fmtPct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';
        $head = ['Código', 'Proyecto', 'Cliente', 'Estado', 'Ingreso', 'Costo total',
            'Margen ($)', 'Margen (%)', 'Observación', 'Observado por', 'Fecha observación'];
        $filas = [$head];

        $tIng = 0.0; $tCosto = 0.0; $tMargen = 0.0;
        foreach ($obras as $o) {
            $filas[] = [
                $o['codigo'], $o['nombre'] ?? '', $o['cliente'] ?? '', ucfirst($o['estado']),
                round($o['ingreso']), round($o['costo_total']), round($o['margen_pesos']),
                $fmtPct($o['margen_pct']),
                $o['observacion'] ?? '', $o['obs_user'] ?? '',
                $o['obs_fecha'] ? $o['obs_fecha']->format('Y-m-d') : '',
            ];
            $tIng += $o['ingreso']; $tCosto += $o['costo_total']; $tMargen += $o['margen_pesos'];
        }
        $filas[] = ['TOTAL', '', '', '', round($tIng), round($tCosto), round($tMargen), '', '', '', ''];

        return Excel::download(
            new \App\Exports\ObrasRevisionExport($filas),
            'Obras_en_revision_'.date('Ymd').'.xlsx'
        );
    }

    /** Arma la lista de obras en revisión (compartida por la pantalla y el Excel). */
    private function datosObras(): array
    {
        // Sumas acumuladas por proyecto (toda la historia).
        $costo6 = RegistroFinanciero::where('cuenta_mayor', 'Costos aplicados')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as t')->groupBy('codigo_proyecto')->pluck('t', 'codigo_proyecto');
        $saldo14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as t')->groupBy('codigo_proyecto')->pluck('t', 'codigo_proyecto');
        $ingreso = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as t')->groupBy('codigo_proyecto')->pluck('t', 'codigo_proyecto');
        $nombres = RegistroFinanciero::selectRaw('codigo_proyecto, MAX(nombre_proyecto) as n')
            ->groupBy('codigo_proyecto')->pluck('n', 'codigo_proyecto');

        $cerradas      = ProyectoCerrado::pluck('codigo_proyecto')->flip();
        $estadoManual  = ObraEstado::pluck('estado', 'codigo_proyecto');
        $fichas        = FichaProyecto::get(['codigo_proyecto', 'cliente'])->keyBy('codigo_proyecto');
        $observaciones = ObservacionRevision::with('usuario')->get()->keyBy('codigo_proyecto');

        $obras = [];
        foreach ($costo6 as $cod => $c6) {
            $costoTotal = abs((float) $c6);
            if ($costoTotal <= self::UMBRAL) continue;                          // sin costo reconocido
            if (abs((float) ($saldo14[$cod] ?? 0)) > self::UMBRAL) continue;    // aún tiene saldo en 14 → Distribución
            if (isset($cerradas[$cod])) continue;                              // ya cerrada (cierre contable)

            $estado = $estadoManual[$cod] ?? 'abierta';
            if ($estado === 'cerrada') continue;                              // marcada como cerrada

            $ing        = (float) ($ingreso[$cod] ?? 0);
            $margen     = $ing - $costoTotal;
            $margenPct  = abs($ing) > self::UMBRAL ? round($margen / $ing * 100, 1) : null;
            $obs        = $observaciones[$cod] ?? null;

            $obras[] = [
                'codigo'       => $cod,
                'cliente'      => $fichas[$cod]->cliente ?? null,
                'nombre'       => $nombres[$cod] ?? null,
                'costo_total'  => $costoTotal,
                'ingreso'      => $ing,
                'margen_pesos' => $margen,
                'margen_pct'   => $margenPct,
                'estado'       => $estado,
                'observacion'  => $obs?->observacion,
                'obs_user'     => $obs?->usuario?->name,
                'obs_fecha'    => $obs?->updated_at,
            ];
        }

        // Urgentes primero: margen más negativo arriba.
        usort($obras, fn ($a, $b) => $a['margen_pesos'] <=> $b['margen_pesos']);

        return $obras;
    }

    /** Guarda una observación/justificación para dejar la obra abierta. */
    public function observar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $datos = $request->validate([
            'codigo_proyecto' => 'required|string|max:255',
            'observacion'     => 'required|string|max:2000',
        ], [], ['observacion' => 'observación']);

        ObservacionRevision::updateOrCreate(
            ['codigo_proyecto' => trim($datos['codigo_proyecto'])],
            ['observacion' => $datos['observacion'], 'user_id' => $request->user()->id]
        );

        return back()->with('success', 'Observación guardada.');
    }
}
