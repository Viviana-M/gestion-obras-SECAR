<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Filtros
        $anio     = $request->get('anio', date('Y'));
        $mes      = $request->get('mes', date('n'));
        $modo     = $request->get('modo', 'acumulado'); // mes o acumulado
        $proyecto = $request->get('proyecto', '');

        // Períodos disponibles
        $periodos = RegistroFinanciero::selectRaw('anio, mes')
            ->groupBy('anio', 'mes')
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->get();

        // Proyectos disponibles
        $proyectos = RegistroFinanciero::selectRaw('codigo_proyecto, nombre_proyecto')
            ->groupBy('codigo_proyecto', 'nombre_proyecto')
            ->orderBy('codigo_proyecto')
            ->get();

        // Query base
        $query = RegistroFinanciero::query()
            ->where('anio', $anio);

        if ($modo === 'mes') {
            $query->where('mes', $mes);
        } else {
            $query->where('mes', '<=', $mes);
        }

        if ($proyecto) {
            $query->where('codigo_proyecto', $proyecto);
        }

        // Agrupar por proyecto y cuenta mayor
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

        // Estructurar por proyecto
        $proyectosData = [];
        foreach ($datos as $fila) {
            $cod = $fila->codigo_proyecto;
            if (!isset($proyectosData[$cod])) {
                $proyectosData[$cod] = [
                    'codigo'   => $cod,
                    'nombre'   => $fila->nombre_proyecto,
                    'ingreso'  => 0,
                    'costo_aplicado'    => 0,
                    'costo_por_aplicar' => 0,
                    'gasto'    => 0,
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

        // Calcular margen por proyecto
        foreach ($proyectosData as &$p) {
            $p['utilidad'] = $p['ingreso'] - abs($p['costo_aplicado']) - abs($p['costo_por_aplicar']);
            $p['margen_pct'] = $p['ingreso'] != 0
                ? round(($p['utilidad'] / $p['ingreso']) * 100, 2)
                : null;
            // Alerta: costos sin ingreso
            $p['alerta'] = $p['ingreso'] == 0 &&
                ($p['costo_aplicado'] != 0 || $p['costo_por_aplicar'] != 0);
        }

        // Totales generales
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
            'totalCostoPorAplicar', 'totalUtilidad', 'totalMargen'
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
        ->where('cuenta_mayor', $cuentaMayor)
        ->where('anio', $anio);

    if ($modo === 'mes') {
        $query->where('mes', $mes);
    } else {
        $query->where('mes', '<=', $mes);
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
        ->where('cuenta_contable', $cuenta)
        ->where('anio', $anio);

    if ($modo === 'mes') {
        $query->where('mes', $mes);
    } else {
        $query->where('mes', '<=', $mes);
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
}