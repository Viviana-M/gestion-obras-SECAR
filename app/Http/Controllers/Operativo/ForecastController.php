<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\ForecastOperativo;
use App\Models\MovimientoContable;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function index(Request $request)
    {
        $mes  = $request->get('mes', date('n'));
        $anio = $request->get('anio', date('Y'));

       $cuentas14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
    ->selectRaw('cuenta_contable, descripcion')
    ->groupBy('cuenta_contable', 'descripcion')
    ->orderBy('cuenta_contable')
    ->get();

$saldos = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
    ->selectRaw('codigo_proyecto, nombre_proyecto, cuenta_contable, SUM(estado_er) as saldo')
    ->groupBy('codigo_proyecto', 'nombre_proyecto', 'cuenta_contable')
    ->havingRaw('ABS(SUM(estado_er)) > 0')
    ->get();

        $costosAplicados = RegistroFinanciero::where('cuenta_mayor', 'Costos aplicados')
            ->where(function($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            })
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')
            ->pluck('total', 'codigo_proyecto');

        $ingresosMes = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')
            ->pluck('total', 'codigo_proyecto');

        $ingresosAcumulado = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->where(function($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            })
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')
            ->pluck('total', 'codigo_proyecto');

        $forecastGuardado = ForecastOperativo::where('mes', $mes)
    ->where('anio', $anio)
    ->get()
    ->keyBy(function ($f) {
        return $f->codigo_proyecto . '_' . $f->cuenta_contable;
    });

        $estadosObra = ForecastOperativo::where('anio', $anio)
            ->selectRaw('codigo_proyecto, estado_obra, avance_pct')
            ->groupBy('codigo_proyecto', 'estado_obra', 'avance_pct')
            ->get()
            ->keyBy('codigo_proyecto');

        $todosProyectos = RegistroFinanciero::selectRaw('codigo_proyecto, nombre_proyecto')
            ->groupBy('codigo_proyecto', 'nombre_proyecto')
            ->orderBy('codigo_proyecto')
            ->get();

        $matriz = [];
        foreach ($saldos as $s) {
            $cod = $s->codigo_proyecto;
            if (!isset($matriz[$cod])) {
                $ingresoAcum   = $ingresosAcumulado[$cod] ?? 0;
                $ingresoMes    = $ingresosMes[$cod] ?? 0;
                $costoAplicado = abs($costosAplicados[$cod] ?? 0);
                $estadoObra    = $estadosObra[$cod] ?? null;

                $matriz[$cod] = [
                    'codigo'            => $cod,
                    'nombre'            => $s->nombre_proyecto,
                    'ingreso_mes'       => $ingresoMes,
                    'ingreso_acum'      => $ingresoAcum,
                    'costo_aplicado'    => $costoAplicado,
                    'cuentas'           => [],
                    'margen_minimo'     => 0,
                    'estado_obra'       => $estadoObra ? $estadoObra->estado_obra : 'abierta',
                    'avance_pct'        => $estadoObra ? $estadoObra->avance_pct : 0,
                    'tiene_ingreso_mes' => $ingresoMes != 0,
                    'costo_transito'    => false,
                ];
            }

            $key = $cod . '_' . $s->cuenta_contable;
            $forecast = $forecastGuardado[$key] ?? null;

             {
                $matriz[$cod]['cuentas'][$s->cuenta_contable] = [
    'descripcion' => $s->descripcion,
    'saldo'       => $s->saldo,
    'monto_mover' => $forecast ? $forecast->monto_a_mover : 0,
];
            }

            if ($forecast) {
                $matriz[$cod]['margen_minimo'] = $forecast->margen_minimo_pct;
            }
        }

        $matriz = array_filter($matriz, function($p) {
    $saldo14 = 0;
    foreach ($p['cuentas'] as $cuenta) {
        $saldo14 += $cuenta['saldo'];
    }
    return $saldo14 != 0;
});

        return view('operativo.forecast', compact(
            'matriz', 'cuentas14', 'mes', 'anio', 'todosProyectos'
        ));
    }

    public function guardar(Request $request)
    {
        $mes  = $request->mes;
        $anio = $request->anio;
        $data = $request->movimientos ?? [];

        foreach ($data as $cod => $cuentas) {
            $margenMinimo   = $request->margen_minimo[$cod] ?? 0;
            $nombreProyecto = $request->nombre_proyecto[$cod] ?? '';
            $estadoObra     = $request->estado_obra[$cod] ?? 'abierta';
            $avancePct      = $request->avance_pct[$cod] ?? 0;

            foreach ($cuentas as $cuenta => $monto) {
                ForecastOperativo::updateOrCreate(
                    [
                        'mes'             => $mes,
                        'anio'            => $anio,
                        'codigo_proyecto' => $cod,
                        'cuenta_contable' => $cuenta,
                    ],
                    [
                        'nombre_proyecto'   => $nombreProyecto,
                        'monto_a_mover'     => floatval($monto) ?? 0,
                        'margen_minimo_pct' => floatval($margenMinimo),
                        'estado_obra'       => $estadoObra,
                        'avance_pct'        => floatval($avancePct),
                        'estado'            => 'borrador',
                        'estado'            => 'borrador',
                        'user_id'           => $request->user()?->id,
                    ]
                );
            }
        }

        return back()->with('success', 'Forecast guardado correctamente.');
    }

    public function enviar(Request $request)
    {
        $mes  = $request->mes;
        $anio = $request->anio;

        ForecastOperativo::where('mes', $mes)
            ->where('anio', $anio)
            ->where('monto_a_mover', '>', 0)
            ->update(['estado' => 'enviado']);

        return back()->with('success', 'Forecast enviado a contabilidad.');
    }
}