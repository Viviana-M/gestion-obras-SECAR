<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class EstadosFinancierosController extends Controller
{
    public function index(Request $request)
    {
        $anio = $request->get('anio', date('Y'));
        $mes  = $request->get('mes', date('n'));
        $modo = $request->get('modo', 'acumulado');

        // Query base
        $query = RegistroFinanciero::query()
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

        // P&G — agrupar por cuenta mayor
        $pyg = $query->clone()
            ->selectRaw('cuenta_mayor, SUM(estado_er) as total_er')
            ->groupBy('cuenta_mayor')
            ->get()
            ->keyBy('cuenta_mayor');

        $ingresos           = $pyg['Ingreso']->total_er ?? 0;
        $costosAplicados    = $pyg['Costos aplicados']->total_er ?? 0;
        $costosPorAplicar   = $pyg['Costos por aplicar']->total_er ?? 0;
        $gastos             = $pyg['Gasto']->total_er ?? 0;

        $utilidadBruta      = $ingresos - abs($costosAplicados) - abs($costosPorAplicar);
        $utilidadNeta       = $utilidadBruta - abs($gastos);
        $margenBruto        = $ingresos != 0 ? round(($utilidadBruta / $ingresos) * 100, 2) : null;
        $margenNeto         = $ingresos != 0 ? round(($utilidadNeta / $ingresos) * 100, 2) : null;

        // Top proyectos por ingreso
        $topProyectos = $query->clone()
            ->where('cuenta_mayor', 'Ingreso')
            ->selectRaw('codigo_proyecto, nombre_proyecto, SUM(estado_er) as total_ingreso')
            ->groupBy('codigo_proyecto', 'nombre_proyecto')
            ->orderByDesc('total_ingreso')
            ->limit(10)
            ->get();

        return view('financiero.estados-financieros', compact(
            'anio', 'mes', 'modo',
            'ingresos', 'costosAplicados', 'costosPorAplicar', 'gastos',
            'utilidadBruta', 'utilidadNeta', 'margenBruto', 'margenNeto',
            'topProyectos'
        ));
    }
}