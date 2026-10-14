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
use App\Services\DistribucionService;
use Illuminate\Http\Request;

class DistribucionAreasController extends Controller
{
    private array $categorias = DistribucionService::CATEGORIAS;

    private DistribucionService $svc;

    public function __construct()
    {
        $this->svc = new DistribucionService();
    }

    public function index(Request $request)
    {
        $mes  = (int) $request->get('mes', date('n'));
        $anio = (int) $request->get('anio', date('Y'));

        // Período contable en formato AAAAMM: define QUÉ VERSIÓN de la homologación aplica.
        $periodo = Homologacion::periodo($anio, $mes);

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
            $saldoBolsa = $this->saldoBolsaPorCuenta($bolsaSel, $periodo, $anio, $mes);
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

    // Saldo de una bolsa desglosado por cuenta 14 (con su cuenta 61 destino).
    // En las bolsas de área: saldo POSITIVO = hay costo por repartir.
    // Saldo NEGATIVO = se reversó de más -> alerta.
    //
    // La cuenta 61 destino y la estructura se toman de la homologación VIGENTE EN EL
    // PERÍODO que se está distribuyendo, no de la de hoy. Así, si contabilidad cambió
    // una cuenta, un período anterior sigue mostrando la cuenta que le correspondía.
    private function saldoBolsaPorCuenta(string $bolsa, int $periodo, int $anio, int $mes): array
    {
        $homol = Homologacion::mapaEn($periodo);

        // Saldo ACUMULADO AL MES FILTRADO (mismo corte que sumaAcum): no se cuentan los
        // movimientos de meses posteriores al seleccionado.
        $saldos = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where('codigo_proyecto', $bolsa)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            })
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
            ->get(['codigo_proyecto', 'nombre_obra', 'cliente', 'margen_ofertado', 'valor_contratado', 'costo_estimado'])
            ->keyBy('codigo_proyecto');

        $nombres = RegistroFinanciero::whereIn('codigo_proyecto', $codigos)
            ->selectRaw('codigo_proyecto, MAX(nombre_proyecto) as nombre')
            ->groupBy('codigo_proyecto')->pluck('nombre', 'codigo_proyecto');

        $cerradas     = ProyectoCerrado::pluck('codigo_proyecto')->flip();
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
                // Nombre del proyecto de la ficha; si no hay, el de los movimientos.
                'nombre'         => ($fichas[$cod]->nombre_obra ?? null) ?: ($nombres[$cod] ?? ''),
                'cliente'        => $fichas[$cod]->cliente ?? null,
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

        // Ordenar por semáforo (los en riesgo primero) y luego por código
        uasort($proyectos, fn($a, $b) => ($a['orden_sem'] <=> $b['orden_sem']) ?: strcmp($a['codigo'], $b['codigo']));

        return $proyectos;
    }

    // ===== Cálculos delegados al DistribucionService =====

    private function calcularMargenes(array &$o): void
    {
        $this->svc->calcularMargenes($o);
    }

    private function sumaMes(string $cm, int $anio, int $mes, array $codigos)
    {
        return $this->svc->sumaMes($cm, $anio, $mes, $codigos);
    }

    private function sumaAcum(string $cm, int $anio, int $mes, array $codigos)
    {
        return $this->svc->sumaAcum($cm, $anio, $mes, $codigos);
    }

    private function normalizarMargen($v): ?float
    {
        return $this->svc->normalizarMargen($v);
    }
}