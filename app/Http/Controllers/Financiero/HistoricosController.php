<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class HistoricosController extends Controller
{
    public function index(Request $request)
    {
        $anio     = $request->get('anio', date('Y'));
        $proyecto = $request->get('proyecto', '');

        // Proyectos cerrados
        $proyectosCerrados = ProyectoCerrado::pluck('codigo_proyecto')->toArray();

        // Proyectos disponibles (solo cerrados)
        $proyectos = RegistroFinanciero::selectRaw('codigo_proyecto, nombre_proyecto')
            ->whereIn('codigo_proyecto', $proyectosCerrados)
            ->groupBy('codigo_proyecto', 'nombre_proyecto')
            ->orderBy('codigo_proyecto')
            ->get();

        // Query base — histórico completo de proyectos cerrados
        $query = RegistroFinanciero::whereIn('codigo_proyecto', $proyectosCerrados);

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
            // Fecha de cierre
            $cierre = ProyectoCerrado::where('codigo_proyecto', $cod)->first();
            $p['fecha_cierre'] = $cierre ? $cierre->fecha_cierre->format('d/m/Y') : '—';
            $p['tipo_cierre']  = $cierre ? $cierre->tipo_cierre : '—';
        }

        $totalIngreso         = array_sum(array_column($proyectosData, 'ingreso'));
        $totalCostoAplicado   = array_sum(array_column($proyectosData, 'costo_aplicado'));
        $totalCostoPorAplicar = array_sum(array_column($proyectosData, 'costo_por_aplicar'));
        $totalUtilidad        = $totalIngreso - abs($totalCostoAplicado) - abs($totalCostoPorAplicar);
        $totalMargen          = $totalIngreso != 0
            ? round(($totalUtilidad / $totalIngreso) * 100, 2)
            : null;

        return view('financiero.historicos', compact(
            'proyectosData', 'proyectos',
            'proyecto', 'anio',
            'totalIngreso', 'totalCostoAplicado',
            'totalCostoPorAplicar', 'totalUtilidad', 'totalMargen'
        ));
    }
}