<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\ObraEstado;
use App\Models\ObservacionRevision;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

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

        return view('operativo.obras-revision', [
            'obras'       => $obras,
            'puedeEditar' => $request->user()->puedeEditarModulo('operacion'),
        ]);
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
