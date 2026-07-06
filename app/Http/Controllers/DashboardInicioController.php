<?php

namespace App\Http\Controllers;

use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class DashboardInicioController extends Controller
{
    public function index(Request $request)
    {
        $anio1 = $request->get('anio1', date('Y') - 1);
        $anio2 = $request->get('anio2', date('Y'));

        $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        // Último mes con datos en cada año
        $ultimoMes2 = RegistroFinanciero::where('anio', $anio2)->max('mes') ?? 12;
        $ultimoMes1 = RegistroFinanciero::where('anio', $anio1)->max('mes') ?? 12;

        // Mes de corte — mínimo de ambos para comparar igual período
        $mesCorte = min($ultimoMes1, $ultimoMes2);

        // Datos para TARJETAS KPI — con corte del mismo período
        $datos1 = $this->datosPorMes($anio1, $mesCorte);
        $datos2 = $this->datosPorMes($anio2, $mesCorte);

        // Datos para GRÁFICO — sin corte, todos los meses disponibles
        $datosGrafico1 = $this->datosPorMes($anio1, 12);
        $datosGrafico2 = $this->datosPorMes($anio2, 12);

        // Totales KPI
        $totalIngreso1    = array_sum(array_column($datos1, 'ingreso'));
        $totalIngreso2    = array_sum(array_column($datos2, 'ingreso'));
        $totalCosto1      = array_sum(array_column($datos1, 'costo'));
        $totalCosto2      = array_sum(array_column($datos2, 'costo'));
        $totalPorAplicar1 = array_sum(array_column($datos1, 'por_aplicar'));
        $totalPorAplicar2 = array_sum(array_column($datos2, 'por_aplicar'));
        $totalUtilidad1   = $totalIngreso1 - $totalCosto1 - $totalPorAplicar1;
        $totalUtilidad2   = $totalIngreso2 - $totalCosto2 - $totalPorAplicar2;
        $margen1          = $totalIngreso1 > 0 ? round($totalUtilidad1 / $totalIngreso1 * 100, 1) : 0;
        $margen2          = $totalIngreso2 > 0 ? round($totalUtilidad2 / $totalIngreso2 * 100, 1) : 0;

        // ── PANEL DE PROYECTOS EN RIESGO (año analizado = $anio2) ──────────
        // Obras cerradas totales: ya no necesitan acción, se excluyen.
        $cerradosTotales = ProyectoCerrado::where('tipo_cierre', 'total')
            ->pluck('codigo_proyecto')
            ->toArray();

        // Prefijos de garantía/internos: no disparan alerta "sin ingreso".
        // 'MO' cubre MOA, MOB y MOC.
        $prefijosGarantia = ['GM', 'GI', 'MO'];
        $esGarantia = function ($cod) use ($prefijosGarantia) {
            foreach ($prefijosGarantia as $p) {
                if (str_starts_with($cod, $p)) return true;
            }
            return false;
        };

        // Agregado por proyecto del año analizado
        $riesgoRaw = RegistroFinanciero::where('anio', $anio2)
            ->whereIn('cuenta_mayor', ['Ingreso', 'Costos aplicados', 'Costos por aplicar'])
            ->selectRaw('codigo_proyecto, nombre_proyecto, cuenta_mayor, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto', 'nombre_proyecto', 'cuenta_mayor')
            ->get();

        $proyAnio2 = [];
        foreach ($riesgoRaw as $r) {
            $cod = $r->codigo_proyecto;
            if (!isset($proyAnio2[$cod])) {
                $proyAnio2[$cod] = [
                    'codigo'      => $cod,
                    'nombre'      => $r->nombre_proyecto,
                    'ingreso'     => 0,
                    'costo'       => 0,
                    'por_aplicar' => 0,
                ];
            }
            match ($r->cuenta_mayor) {
                'Ingreso'            => $proyAnio2[$cod]['ingreso']     += $r->total,
                'Costos aplicados'   => $proyAnio2[$cod]['costo']       += abs($r->total),
                'Costos por aplicar' => $proyAnio2[$cod]['por_aplicar'] += abs($r->total),
                default              => null,
            };
        }

        $base = collect(array_values($proyAnio2))
            ->reject(fn($p) => in_array($p['codigo'], $cerradosTotales));

        // Grupo 1 — costo sin ingreso (excluye garantías/internos)
        $sinIngresoCol = $base
            ->reject(fn($p) => $esGarantia($p['codigo']))
            ->filter(fn($p) => $p['ingreso'] == 0 && ($p['costo'] + $p['por_aplicar']) > 0)
            ->map(fn($p) => [
                'codigo'      => $p['codigo'],
                'nombre'      => $p['nombre'],
                'costo_total' => $p['costo'] + $p['por_aplicar'],
            ])
            ->sortByDesc('costo_total')
            ->values();

        $sinIngresoCount = $sinIngresoCol->count();
        $sinIngresoTotal = $sinIngresoCol->sum('costo_total');
        $sinIngreso      = $sinIngresoCol->take(8)->values();

        // Grupo 2 — margen negativo (facturó pero pierde plata)
        $margenNegCol = $base
            ->filter(fn($p) => $p['ingreso'] > 0)
            ->map(function ($p) {
                $util = $p['ingreso'] - $p['costo'] - $p['por_aplicar'];
                return [
                    'codigo'   => $p['codigo'],
                    'nombre'   => $p['nombre'],
                    'ingreso'  => $p['ingreso'],
                    'utilidad' => $util,
                    'margen'   => $p['ingreso'] > 0 ? round($util / $p['ingreso'] * 100, 1) : null,
                ];
            })
            ->filter(fn($p) => $p['utilidad'] < 0)
            ->sortBy('margen') // el más negativo primero
            ->values();

        $margenNegCount = $margenNegCol->count();
        $margenNegativo = $margenNegCol->take(8)->values();
        // ───────────────────────────────────────────────────────────────

        $aniosDisponibles = RegistroFinanciero::selectRaw('DISTINCT anio')
            ->orderByDesc('anio')
            ->pluck('anio');

        $nombreMesCorte = $meses[$mesCorte - 1];

        return view('inicio', compact(
            'anio1', 'anio2', 'meses',
            'datos1', 'datos2',
            'datosGrafico1', 'datosGrafico2',
            'totalIngreso1', 'totalIngreso2',
            'totalCosto1', 'totalCosto2',
            'totalPorAplicar1', 'totalPorAplicar2',
            'totalUtilidad1', 'totalUtilidad2',
            'margen1', 'margen2',
            'sinIngreso', 'sinIngresoCount', 'sinIngresoTotal',
            'margenNegativo', 'margenNegCount',
            'aniosDisponibles',
            'mesCorte', 'nombreMesCorte'
        ));
    }

    private function datosPorMes($anio, $mesCorte = 12)
    {
        $raw = RegistroFinanciero::where('anio', $anio)
            ->where('mes', '<=', $mesCorte)
            ->whereIn('cuenta_mayor', ['Ingreso', 'Costos aplicados', 'Costos por aplicar'])
            ->selectRaw('mes, cuenta_mayor, SUM(estado_er) as total')
            ->groupBy('mes', 'cuenta_mayor')
            ->get();

        $datos = [];
        for ($m = 1; $m <= 12; $m++) {
            $datos[$m] = ['ingreso' => 0, 'costo' => 0, 'por_aplicar' => 0];
        }

        foreach ($raw as $r) {
            match ($r->cuenta_mayor) {
                'Ingreso'            => $datos[$r->mes]['ingreso']     += $r->total,
                'Costos aplicados'   => $datos[$r->mes]['costo']       += abs($r->total),
                'Costos por aplicar' => $datos[$r->mes]['por_aplicar'] += abs($r->total),
                default              => null,
            };
        }

        return $datos;
    }
}