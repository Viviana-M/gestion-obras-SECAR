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
        $proyecto = $request->get('proyecto', '');

        // Proyectos cerrados
        $proyectosCerrados = ProyectoCerrado::with('usuario')
            ->orderByDesc('fecha_cierre')
            ->get();

        $codigosCerrados = $proyectosCerrados->pluck('codigo_proyecto')->toArray();

        // Solo mostrar en el filtro proyectos cerrados que tienen datos financieros
        $codigosConDatos = RegistroFinanciero::whereIn('codigo_proyecto', $codigosCerrados)
            ->selectRaw('DISTINCT codigo_proyecto')
            ->pluck('codigo_proyecto')
            ->toArray();

        $proyectos = ProyectoCerrado::whereIn('codigo_proyecto', $codigosConDatos)
            ->orderBy('codigo_proyecto')
            ->get();

        // Query base — histórico completo sin filtro de año
        $query = RegistroFinanciero::whereIn('codigo_proyecto', $codigosCerrados);

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

        // Saldos cuenta 14 por proyecto
        $saldos14 = RegistroFinanciero::whereIn('codigo_proyecto', $codigosCerrados)
            ->where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as saldo14')
            ->groupBy('codigo_proyecto')
            ->pluck('saldo14', 'codigo_proyecto');

        // Prefijos que por naturaleza no generan ingreso
        $prefijosNoFacturan = ['GM', 'MO', 'MOA', 'MOB', 'MOC', 'GI'];

        $proyectosData = [];
        foreach ($datos as $fila) {
            $cod = $fila->codigo_proyecto;
            if (!isset($proyectosData[$cod])) {
                $cierre  = $proyectosCerrados->firstWhere('codigo_proyecto', $cod);
                $saldo14 = $saldos14[$cod] ?? 0;

                // Verificar si el código pertenece a un prefijo no facturable
                $esNoFacturable = false;
                foreach ($prefijosNoFacturan as $prefijo) {
                    if (str_starts_with($cod, $prefijo)) {
                        $esNoFacturable = true;
                        break;
                    }
                }

                $proyectosData[$cod] = [
                    'codigo'                  => $cod,
                    'nombre'                  => $fila->nombre_proyecto,
                    'ingreso'                 => 0,
                    'costo_aplicado'          => 0,
                    'costo_por_aplicar'       => 0,
                    'gasto'                   => 0,
                    'fecha_cierre'            => $cierre && $cierre->fecha_cierre ? $cierre->fecha_cierre->format('d/m/Y') : '—',
                    'tipo_cierre'             => $cierre ? $cierre->tipo_cierre : '—',
                    'saldo14'                 => $saldo14,
                    'es_no_facturable'        => $esNoFacturable,
                    'alerta_saldo14_negativo' => $saldo14 < -1,
                    'alerta_saldo14_positivo' => $saldo14 > 1,
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
            // Alerta sin ingreso solo para proyectos facturables
            $p['alerta_sin_ingreso'] = !$p['es_no_facturable'] && $p['ingreso'] == 0 &&
                ($p['costo_aplicado'] != 0 || $p['costo_por_aplicar'] != 0);
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
            'proyecto',
            'totalIngreso', 'totalCostoAplicado',
            'totalCostoPorAplicar', 'totalUtilidad', 'totalMargen'
        ));
    }

    public function detalle(Request $request)
    {
        $codigo      = $request->get('codigo');
        $cuentaMayor = $request->get('cuenta_mayor');

        $detalle = RegistroFinanciero::where('codigo_proyecto', $codigo)
            ->where('cuenta_mayor', $cuentaMayor)
            ->selectRaw('
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
        $codigo = $request->get('codigo');
        $cuenta = $request->get('cuenta');

        $periodos = RegistroFinanciero::where('codigo_proyecto', $codigo)
            ->where('cuenta_contable', $cuenta)
            ->selectRaw('
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