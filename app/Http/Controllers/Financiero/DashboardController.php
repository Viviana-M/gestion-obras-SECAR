<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $anio     = $request->get('anio', date('Y'));
        $mes      = $request->get('mes', date('n'));
        $modo     = $request->get('modo', 'acumulado');
        $proyecto = $request->get('proyecto', '');

        // Excluir proyectos cerrados
        $proyectosCerrados = ProyectoCerrado::pluck('codigo_proyecto')->toArray();

        $periodos = RegistroFinanciero::selectRaw('anio, mes')
            ->groupBy('anio', 'mes')
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->get();

        // Solo proyectos activos en el filtro
        $proyectos = RegistroFinanciero::selectRaw('codigo_proyecto, nombre_proyecto')
            ->whereNotIn('codigo_proyecto', $proyectosCerrados)
            ->groupBy('codigo_proyecto', 'nombre_proyecto')
            ->orderBy('codigo_proyecto')
            ->get();

        // Proyectos que NUNCA han tenido ingreso en toda la historia
        $conIngreso = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->selectRaw('DISTINCT codigo_proyecto')
            ->pluck('codigo_proyecto')
            ->toArray();

        // Query base — acumulado histórico hasta el período filtrado
        $query = RegistroFinanciero::query()
            ->whereNotIn('codigo_proyecto', $proyectosCerrados)
            ->where(function($q) use ($anio, $mes, $modo) {
                if ($modo === 'mes') {
                    $q->where('anio', $anio)->where('mes', $mes);
                } else {
                    $q->where('anio', '<', $anio)
                      ->orWhere(function($q2) use ($anio, $mes) {
                          $q2->where('anio', $anio)->where('mes', '<=', $mes);
                      });
                }
            });

        if ($proyecto) {
            $query->where('codigo_proyecto', $proyecto);
        }

        $datos = $query->selectRaw('
                codigo_proyecto,
                nombre_proyecto,
                cuenta_mayor,
                SUM(estado_er) as total_er
            ')
            ->groupBy('codigo_proyecto', 'nombre_proyecto', 'cuenta_mayor')
            ->orderBy('codigo_proyecto')
            ->orderBy('cuenta_mayor')
            ->get();

        $proyectosData = [];
        foreach ($datos as $fila) {
            $cod = $fila->codigo_proyecto;
            if (!isset($proyectosData[$cod])) {
                $proyectosData[$cod] = [
                    'codigo'            => $cod,
                    'nombre'            => $fila->nombre_proyecto,
                    'ingreso'           => 0,
                    'costo_aplicado'    => 0,
                    'costo_por_aplicar' => 0,
                    'gasto'             => 0,
                ];
            }
            match($fila->cuenta_mayor) {
                'Ingreso'            => $proyectosData[$cod]['ingreso']           += $fila->total_er,
                'Costos aplicados'   => $proyectosData[$cod]['costo_aplicado']    += $fila->total_er,
                'Costos por aplicar' => $proyectosData[$cod]['costo_por_aplicar'] += $fila->total_er,
                'Gasto'              => $proyectosData[$cod]['gasto']             += $fila->total_er,
                default              => null,
            };
        }

        foreach ($proyectosData as $cod => &$p) {
            $p['utilidad'] = $p['ingreso'] - abs($p['costo_aplicado']) - abs($p['costo_por_aplicar']);
            $p['margen_pct'] = $p['ingreso'] != 0
                ? round(($p['utilidad'] / $p['ingreso']) * 100, 2)
                : null;
            $esGarantia = str_starts_with($cod, 'GM');
            $p['alerta'] = !$esGarantia && !in_array($cod, $conIngreso) &&
                ($p['costo_aplicado'] != 0 || $p['costo_por_aplicar'] != 0);
        }
        unset($p);

        // ── Cruce con el maestro comercial (por código exacto) ──
        $maestro = FichaProyecto::get(['codigo_proyecto', 'margen_ofertado', 'responsable_obra'])
            ->keyBy('codigo_proyecto');

        foreach ($proyectosData as $cod => &$p) {
            $m = $maestro->get($cod);
            $p['mc_ofertado']      = $m->margen_ofertado ?? null;
            $p['responsable_obra'] = $m->responsable_obra ?? null;
            if ($p['mc_ofertado'] !== null && $p['margen_pct'] !== null) {
                $p['cumple'] = $p['margen_pct'] >= $p['mc_ofertado']; // real ≥ ofertado
            } else {
                $p['cumple'] = null; // sin con qué comparar
            }
        }
        unset($p);
        // ────────────────────────────────────────────────────────

        // Proyectos en alerta: código + nombre + costo, ordenados de mayor a menor
        $proyectosAlerta = collect($proyectosData)
            ->filter(fn($p) => $p['alerta'])
            ->map(fn($p) => [
                'codigo'      => $p['codigo'],
                'nombre'      => $p['nombre'],
                'costo_total' => abs($p['costo_aplicado']) + abs($p['costo_por_aplicar']),
            ])
            ->sortByDesc('costo_total')
            ->values();

        $totalAlerta = $proyectosAlerta->sum('costo_total');

        $totalIngreso         = array_sum(array_column($proyectosData, 'ingreso'));
        $totalCostoAplicado   = array_sum(array_column($proyectosData, 'costo_aplicado'));
        $totalCostoPorAplicar = array_sum(array_column($proyectosData, 'costo_por_aplicar'));
        $totalUtilidad        = $totalIngreso - abs($totalCostoAplicado) - abs($totalCostoPorAplicar);
        $totalMargen          = $totalIngreso != 0
            ? round(($totalUtilidad / $totalIngreso) * 100, 2)
            : null;

        return view('financiero.dashboard', compact(
            'proyectosData', 'periodos', 'proyectos',
            'anio', 'mes', 'modo', 'proyecto',
            'totalIngreso', 'totalCostoAplicado',
            'totalCostoPorAplicar', 'totalUtilidad', 'totalMargen',
            'proyectosAlerta', 'totalAlerta'
        ));
    }

    public function detalle(Request $request)
    {
        $codigo      = $request->get('codigo');
        $cuentaMayor = $request->get('cuenta_mayor');
        $anio        = $request->get('anio', date('Y'));
        $mes         = $request->get('mes', date('n'));
        $modo        = $request->get('modo', 'acumulado');

        $query = RegistroFinanciero::where('codigo_proyecto', $codigo)
            ->where('cuenta_mayor', $cuentaMayor);

        if ($modo === 'mes') {
            $query->where('anio', $anio)->where('mes', $mes);
        } else {
            // Acumulado histórico hasta el período filtrado: mismo criterio que la
            // tarjeta del dashboard (index), para que el detalle reconcilie con ella.
            $query->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            });
        }

        $detalle = $query->selectRaw('
                cuenta_contable,
                descripcion,
                SUM(valor_debito) as total_debito,
                SUM(valor_credito) as total_credito,
                SUM(estado_er) as total_er
            ')
            ->groupBy('cuenta_contable', 'descripcion')
            ->orderByDesc('total_er')
            ->get();

        return response()->json([
            'codigo'       => $codigo,
            'cuenta_mayor' => $cuentaMayor,
            'detalle'      => $detalle,
        ]);
    }

    public function detalleCuenta(Request $request)
    {
        $codigo  = $request->get('codigo');
        $cuenta  = $request->get('cuenta');
        $anio    = $request->get('anio', date('Y'));
        $mes     = $request->get('mes', date('n'));
        $modo    = $request->get('modo', 'acumulado');

        $query = RegistroFinanciero::where('codigo_proyecto', $codigo)
            ->where('cuenta_contable', $cuenta);

        if ($modo === 'mes') {
            $query->where('anio', $anio)->where('mes', $mes);
        } else {
            // Acumulado histórico hasta el período filtrado: mismo criterio que la
            // tarjeta del dashboard (index), para que el detalle reconcilie con ella.
            $query->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            });
        }

        $periodos = $query->selectRaw('
                mes, anio,
                SUM(valor_debito) as total_debito,
                SUM(valor_credito) as total_credito,
                SUM(estado_er) as total_er
            ')
            ->groupBy('mes', 'anio')
            ->orderBy('anio')
            ->orderBy('mes')
            ->get();

        return response()->json(['periodos' => $periodos]);
    }

    // Comparativo ofertado vs real ACUMULADO (toda la vida del proyecto)
    public function comparativo(Request $request)
    {
        $codigo = $request->get('codigo');

        $agg = RegistroFinanciero::where('codigo_proyecto', $codigo)
            ->whereIn('cuenta_mayor', ['Ingreso', 'Costos aplicados', 'Costos por aplicar'])
            ->selectRaw('cuenta_mayor, SUM(estado_er) as total')
            ->groupBy('cuenta_mayor')
            ->pluck('total', 'cuenta_mayor');

        $ingreso  = (float) ($agg['Ingreso'] ?? 0);
        $costo    = abs((float) ($agg['Costos aplicados'] ?? 0)) + abs((float) ($agg['Costos por aplicar'] ?? 0));
        $utilidad = $ingreso - $costo;
        $margen   = $ingreso != 0 ? round($utilidad / $ingreso * 100, 2) : null;

        $f = FichaProyecto::where('codigo_proyecto', $codigo)->first();

        return response()->json([
            'codigo'   => $codigo,
            'nombre'   => $f->nombre_obra ?? null,
            'real'     => [
                'ingreso'  => $ingreso,
                'costo'    => $costo,
                'utilidad' => $utilidad,
                'margen'   => $margen,
            ],
            'ofertado' => $f ? [
                'ingreso'  => $f->valor_contratado,
                'costo'    => $f->costo_estimado,
                'utilidad' => $f->utilidad_ofertada,
                'margen'   => $f->margen_ofertado,
            ] : null,
        ]);
    }
}