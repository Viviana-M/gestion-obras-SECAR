<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use App\Models\Homologacion;
use App\Models\FichaProyecto;
use App\Models\ProyectoCerrado;
use App\Models\ForecastOperativo;
use App\Models\ObraEstado;
use App\Models\UnBolsa;
use App\Models\User;
use Illuminate\Http\Request;

class DistribucionAreasController extends Controller
{
    private array $categorias = [
        'EQU-MAT-SUM' => 'Equipos y materiales',
        'MOI'         => 'M.O. interna',
        'MOE'         => 'M.O. externa',
        'OTROS COSTO' => 'Otros costos',
        'MOFIJAOPER'  => 'M.O. fija (supervisores)',
    ];

    public function index(Request $request)
    {
        $mes  = (int) $request->get('mes', date('n'));
        $anio = (int) $request->get('anio', date('Y'));

        // Departamento del usuario (mismo criterio que en distribución normal)
        $usuario     = $request->user();
        $depUsuario  = $usuario?->departamentoUnico();
        $depElegido  = $request->get('departamento');
        $depEfectivo = $depUsuario ?: ($depElegido ?: null);

        // Bolsas activas del departamento (o todas si admin/director sin elegir)
        $bolsasQuery = UnBolsa::where('activo', true);
        if ($depEfectivo) {
            $bolsasQuery->where('departamento', $depEfectivo);
        }
        $bolsas = $bolsasQuery->orderBy('codigo')->get();

        // Bolsa seleccionada
        $bolsaSel = $request->get('bolsa');
        $saldoBolsa = [];
        if ($bolsaSel) {
            $saldoBolsa = $this->saldoBolsaPorCuenta($bolsaSel);
        }

        // OT del departamento (proyectos reales, excluyendo las bolsas)
        $proyectos = $depEfectivo ? $this->proyectosDelDepto($depEfectivo, $mes, $anio) : [];

        return view('operativo.distribucion-areas', [
            'mes'         => $mes,
            'anio'        => $anio,
            'depUsuario'  => $depUsuario,
            'depEfectivo' => $depEfectivo,
            'bolsas'      => $bolsas,
            'bolsaSel'    => $bolsaSel,
            'saldoBolsa'  => $saldoBolsa,
            'proyectos'   => $proyectos,
            'categorias'  => $this->categorias,
        ]);
    }

    // Saldo de una bolsa desglosado por cuenta 14 (con su cuenta 61 destino)
    // Saldo de una bolsa desglosado por cuenta 14 (con su cuenta 61 destino).
    // En las bolsas de área: saldo POSITIVO = hay costo por repartir.
    // Saldo NEGATIVO = se reversó de más -> alerta.
    private function saldoBolsaPorCuenta(string $bolsa): array
    {
        $homol = Homologacion::get(['cuenta_14', 'cuenta_61', 'nombre', 'estructura'])
            ->keyBy(fn($h) => (string) $h->cuenta_14);

        $saldos = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where('codigo_proyecto', $bolsa)
            ->selectRaw('cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as saldo')
            ->groupBy('cuenta_contable')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5')
            ->get();

        $lineas = [];
        foreach ($saldos as $s) {
            $saldo = round((float) $s->saldo, 2);
            $porRepartir = $saldo > 0 ? $saldo : 0;         // positivo = por repartir
            $alerta      = $saldo < 0 ? abs($saldo) : 0;    // negativo = alerta (se reversó de más)

            if ($porRepartir <= 0 && $alerta <= 0) continue;

            $h = $homol[(string) $s->cuenta_contable] ?? null;
            $lineas[] = [
                'cuenta_14'  => (string) $s->cuenta_contable,
                'cuenta_61'  => (string) ($h->cuenta_61 ?? 'SIN HOMOLOGAR'),
                'nombre'     => $h->nombre ?? $s->descripcion,
                'estructura' => $h->estructura ?? 'OTROS COSTO',
                'pendiente'  => $porRepartir,
                'alerta'     => $alerta,
            ];
        }
        return $lineas;
    }
    // Proyectos reales del departamento, con sus márgenes (reutiliza la lógica de distribución)
    private function proyectosDelDepto(string $departamento, int $mes, int $anio): array
    {
        $prefijos = User::prefijosDeDepartamento($departamento);
        $bolsasCodigos = UnBolsa::codigos();   // para excluir las bolsas

        // Proyectos con saldo en cuenta 14 o con ingreso/costo en el mes
        $codigos = RegistroFinanciero::where(function ($q) use ($prefijos) {
                $q->where(function ($q2) use ($prefijos) {
                    foreach ($prefijos as $p) $q2->orWhere('codigo_proyecto', 'like', $p.'%');
                });
            })
            ->whereNotIn('codigo_proyecto', $bolsasCodigos)
            ->distinct()->pluck('codigo_proyecto')->all();

        if (empty($codigos)) return [];

        $ingresoMes   = $this->sumaMes('Ingreso', $anio, $mes, $codigos);
        $ingresoAcum  = $this->sumaAcum('Ingreso', $anio, $mes, $codigos);
        $costoAplMes  = $this->sumaMes('Costos aplicados', $anio, $mes, $codigos);
        $costoAplAcum = $this->sumaAcum('Costos aplicados', $anio, $mes, $codigos);

        $inventario14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->selectRaw('codigo_proyecto, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto')->pluck('saldo', 'codigo_proyecto');

        $fichas = FichaProyecto::whereIn('codigo_proyecto', $codigos)
            ->get(['codigo_proyecto', 'margen_ofertado', 'valor_contratado', 'costo_estimado'])
            ->keyBy('codigo_proyecto');

        $nombres = RegistroFinanciero::whereIn('codigo_proyecto', $codigos)
            ->selectRaw('codigo_proyecto, MAX(nombre_proyecto) as nombre')
            ->groupBy('codigo_proyecto')->pluck('nombre', 'codigo_proyecto');

        $cerradas    = ProyectoCerrado::pluck('codigo_proyecto')->flip();
        $estadoManual = ObraEstado::pluck('estado', 'codigo_proyecto');

        $proyectos = [];
        foreach ($codigos as $cod) {
            // Solo proyectos que tengan algo de actividad (ingreso, costo o saldo 14)
            $tieneActividad = ($ingresoAcum[$cod] ?? 0) != 0
                || ($costoAplAcum[$cod] ?? 0) != 0
                || ($inventario14[$cod] ?? 0) != 0;
            if (!$tieneActividad) continue;

            $o = [
                'codigo'         => $cod,
                'nombre'         => $nombres[$cod] ?? '',
                'estado'         => $estadoManual[$cod] ?? (isset($cerradas[$cod]) ? 'cerrada' : 'abierta'),
                'ingreso_mes'    => (float) ($ingresoMes[$cod] ?? 0),
                'ingreso_acum'   => (float) ($ingresoAcum[$cod] ?? 0),
                'costo_apl_mes'  => abs((float) ($costoAplMes[$cod] ?? 0)),
                'costo_apl_acum' => abs((float) ($costoAplAcum[$cod] ?? 0)),
                'ofertado'       => $this->normalizarMargen($fichas[$cod]->margen_ofertado ?? null),
                'valor_oferta'   => (float) ($fichas[$cod]->valor_contratado ?? 0),
                'costo_presup'   => (float) ($fichas[$cod]->costo_estimado ?? 0),
                'sum_aplicar'    => 0,
                'sum_prov'       => 0,
            ];
            $saldoInv14 = (float) ($inventario14[$cod] ?? 0);
            $o['inventario_obra']    = $saldoInv14 < 0 ? abs($saldoInv14) : 0;
            $o['inventario_almacen'] = 0;

            $this->calcularMargenes($o);
            $proyectos[$cod] = $o;
        }

        // Ordenar por semáforo (los en riesgo primero) y luego por nombre
        uasort($proyectos, fn($a, $b) => ($a['orden_sem'] <=> $b['orden_sem']) ?: strcmp($a['codigo'], $b['codigo']));

        return $proyectos;
    }

    // ===== Métodos reutilizados de la distribución normal =====

    private function calcularMargenes(array &$o): void
    {
        $ingMes  = $o['ingreso_mes'];
        $ingAcum = $o['ingreso_acum'];

        $o['margen_acum'] = $ingAcum != 0
            ? round(($ingAcum - $o['costo_apl_acum']) / $ingAcum * 100, 1) : null;
        $o['margen_proy'] = $ingAcum != 0
            ? round(($ingAcum - ($o['costo_apl_acum'] + $o['sum_aplicar'] + $o['sum_prov'])) / $ingAcum * 100, 1) : null;

        // Estado de avance (acumulado al mes anterior)
        $o['fact_acum_rec']     = $ingAcum - $ingMes;
        $o['costo_acum_rec']    = $o['costo_apl_acum'] - $o['costo_apl_mes'];
        $o['margen_acum_pesos'] = $o['fact_acum_rec'] - $o['costo_acum_rec'];
        $o['mc_pct_acum']       = $o['fact_acum_rec'] != 0
            ? round((1 - $o['costo_acum_rec'] / $o['fact_acum_rec']) * 100, 1) : null;

        // Rentabilidad del mes
        $o['costo_mes_c6'] = $o['costo_apl_mes'];
        $aplicadoIni       = $o['sum_aplicar'] + $o['sum_prov'];
        $o['aplicado_mes'] = $aplicadoIni;
        $costoMesTotal     = $o['costo_apl_mes'] + $aplicadoIni;
        $o['mc_mes_pesos'] = $ingMes - $costoMesTotal;
        $o['mc_mes_pct']   = $ingMes != 0 ? round($o['mc_mes_pesos'] / $ingMes * 100, 1) : null;

        // Proyección
        $valorOferta  = $o['valor_oferta'];
        $costoPresup  = $o['costo_presup'];
        $factTotal    = $ingAcum;
        $costoAcumTot = $o['costo_apl_acum'];
        $costoTotal   = $costoAcumTot + $o['inventario_obra'] + $o['inventario_almacen'];

        $o['pr_valor_oferta'] = $valorOferta;
        $o['pr_dif_facturar'] = $valorOferta - $factTotal;
        $o['pr_avance_fact']  = $valorOferta != 0 ? round($factTotal / $valorOferta * 100, 1) : null;
        $o['pr_inv_obra']     = $o['inventario_obra'];
        $o['pr_inv_almacen']  = $o['inventario_almacen'];
        $o['pr_costo_total']  = $costoTotal;
        $o['pr_mc_ofertado']  = $o['ofertado'];
        $o['pr_mc_proy']      = $valorOferta != 0 ? round(($valorOferta - $costoTotal) / $valorOferta * 100, 1) : null;
        $o['pr_costo_presup'] = $costoPresup;
        $o['pr_avance_ejec']  = $costoPresup != 0 ? round($costoTotal / $costoPresup * 100, 1) : null;

        $of = $o['ofertado'];
        if ($of === null || $o['margen_acum'] === null) {
            $o['semaforo'] = 'gris';  $o['orden_sem'] = 3;
        } elseif ($o['margen_acum'] < $of) {
            $o['semaforo'] = 'rojo';  $o['orden_sem'] = 0;
        } elseif ($o['margen_proy'] !== null && $o['margen_proy'] < $of) {
            $o['semaforo'] = 'ambar'; $o['orden_sem'] = 1;
        } else {
            $o['semaforo'] = 'verde'; $o['orden_sem'] = 2;
        }
    }

    private function sumaMes(string $cm, int $anio, int $mes, array $codigos)
    {
        return RegistroFinanciero::where('cuenta_mayor', $cm)
            ->where('anio', $anio)->where('mes', $mes)
            ->whereIn('codigo_proyecto', $codigos)
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')->pluck('total', 'codigo_proyecto');
    }

    private function sumaAcum(string $cm, int $anio, int $mes, array $codigos)
    {
        return RegistroFinanciero::where('cuenta_mayor', $cm)
            ->whereIn('codigo_proyecto', $codigos)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            })
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')->pluck('total', 'codigo_proyecto');
    }

    private function normalizarMargen($v): ?float
    {
        if ($v === null || $v === '') return null;
        $v = (float) $v;
        if (abs($v) <= 1.5) $v = $v * 100;
        return round($v, 1);
    }
}