<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use App\Models\Homologacion;
use App\Models\FichaProyecto;
use App\Models\ProyectoCerrado;
use App\Models\ForecastOperativo;
use App\Models\AplicacionCosto;
use App\Models\ObraEstado;
use App\Models\ObservacionObra;
use App\Models\Distribucion;
use App\Models\AutorizacionDistribucion;
use App\Models\BolsaAsignacion;
use App\Models\ItemDistribucion;
use App\Models\ReasignacionItem;
use App\Models\CierrePeriodo;
use App\Models\BolsaMonto;
use App\Models\UnBolsa;
use App\Models\User;
use App\Services\DistribucionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Exports\ResumenDistribucionExport;
use App\Exports\FacturadoTipoExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\DistribucionVersion;
use Barryvdh\DomPDF\Facade\Pdf;

class DistribucionCostosController extends Controller
{
    private array $categorias = DistribucionService::CATEGORIAS;

    private DistribucionService $svc;

    public function __construct()
    {
        $this->svc = new DistribucionService();
    }

    public function consultas(Request $request)
    {
        $dists = Distribucion::orderByDesc('created_at')->get();

        $totales = AplicacionCosto::selectRaw('distribucion_id,
                SUM(CASE WHEN es_provision = 0 THEN monto_aplicar ELSE 0 END) as aplicado,
                SUM(CASE WHEN es_provision = 1 THEN monto_aplicar ELSE 0 END) as provision,
                COUNT(DISTINCT codigo_proyecto) as obras')
            ->groupBy('distribucion_id')->get()->keyBy('distribucion_id');

        $usuarios = User::pluck('name', 'id');

        // Foto CONGELADA del envío: la última versión con evento enviado/reenviado por
        // distribución. Es el registro oficial que no cambia aunque cambien los datos.
        $finales = DistribucionVersion::whereIn('evento', ['enviado', 'reenviado'])
            ->orderByDesc('id')->get(['id', 'distribucion_id'])
            ->unique('distribucion_id')->keyBy('distribucion_id');

        $filas = $dists->map(function ($d) use ($totales, $usuarios, $finales) {
            $t = $totales[$d->id] ?? null;
            return [
                'id'           => $d->id,
                'mes'          => $d->mes,
                'anio'         => $d->anio,
                'departamento' => $d->departamento,
                'version'      => $d->version,
                'estado'       => $d->estado,
                'habilitada'   => $d->edicion_habilitada,
                'aplicado'     => (float) ($t->aplicado ?? 0),
                'provision'    => (float) ($t->provision ?? 0),
                'obras'        => (int) ($t->obras ?? 0),
                'guardado_at'  => $d->updated_at,
                'guardado_por' => $usuarios[$d->guardado_por] ?? '—',
                'enviado_at'   => $d->enviado_at,
                'version_final_id' => $finales[$d->id]->id ?? null,
            ];
        });

        return view('operativo.distribucion-consultas', ['filas' => $filas]);
    }

    public function index(Request $request)
    {
        $distId       = $request->get('dist');
        $distribucion = $distId ? Distribucion::find($distId) : null;

        if ($distribucion) {
            $mes  = (int) $distribucion->mes;
            $anio = (int) $distribucion->anio;
        } else {
            // Por defecto (sin filtro explícito) se muestra el ÚLTIMO período con información
            // cargada, no el mes en curso: si lo último fue junio, arranca en junio.
            [$defMes, $defAnio] = $this->ultimoPeriodoConDatos();
            $mes  = (int) $request->get('mes', $defMes);
            $anio = (int) $request->get('anio', $defAnio);
        }
        $tipo         = $request->get('tipo', 'todos');
        $estadoFiltro = $request->get('estado', 'todos');
        $vista        = $request->get('vista', 'todo');

        // Período contable (AAAAMM). Define QUÉ VERSIÓN de la homologación aplica:
        // si contabilidad cambió una cuenta, un período anterior sigue usando la suya.
        $periodo = Homologacion::periodo($anio, $mes);

        $homol = Homologacion::mapaEn($periodo);

        // Corte ACUMULADO al mes filtrado (mismo criterio que sumaAcum y que el
        // saldo de las bolsas): todos los períodos anteriores + el mes actual.
        // Así, al filtrar un mes histórico, la cuenta 14 refleja su estado real
        // a esa fecha, sin contaminarse con reclasificaciones de meses posteriores.
        $corteAcum = function ($q) use ($anio, $mes) {
            $q->where(function ($sub) use ($anio, $mes) {
                $sub->where('anio', '<', $anio)
                    ->orWhere(function ($s) use ($anio, $mes) {
                        $s->where('anio', $anio)->where('mes', '<=', $mes);
                    });
            });
        };

        $saldos14Query = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, nombre_proyecto, cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'nombre_proyecto', 'cuenta_contable')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5');

        if ($vista === 'mes') {
            $saldos14Query->where('anio', $anio)->where('mes', $mes);
        } else {
            $corteAcum($saldos14Query);
        }

        $saldos14 = $saldos14Query->get();

        // Solo obras con saldo NETO real en la cuenta 14 (por proyecto), según la vista.
        // Evita mostrar obras cuyos movimientos de cuenta 14 se cancelan entre sí
        // (neto ≈ 0): no hay nada que distribuir y solo hacen ruido.
        $netoQuery = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as neto')
            ->groupBy('codigo_proyecto')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5');
        if ($vista === 'mes') {
            $netoQuery->where('anio', $anio)->where('mes', $mes);
        } else {
            $corteAcum($netoQuery);
        }
        $proyectosConSaldoNeto = $netoQuery->pluck('codigo_proyecto')->flip();

        $ingresoMes   = $this->sumaMes('Ingreso', $anio, $mes);
        $ingresoAcum  = $this->sumaAcum('Ingreso', $anio, $mes);
        $costoAplMes  = $this->sumaMes('Costos aplicados', $anio, $mes);
        $costoAplAcum = $this->sumaAcum('Costos aplicados', $anio, $mes);

        // Antigüedad de cada cuenta 14 (período más antiguo con pendiente), para el
        // reparto FIFO topado al facturado del mes. El reparto en sí se calcula más
        // abajo, sobre el saldo NETO abierto de cada cuenta (no el bruto por período).
        $periodoQuery = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, cuenta_contable, MIN(anio*100+mes) as periodo')
            ->groupBy('codigo_proyecto', 'cuenta_contable');
        if ($vista === 'mes') {
            $periodoQuery->where('anio', $anio)->where('mes', $mes);
        } else {
            $corteAcum($periodoQuery);
        }
        $periodoCuenta = [];  // [cod|cuenta_14] => período más antiguo (anio*100+mes)
        foreach ($periodoQuery->get() as $r) {
            $periodoCuenta[$r->codigo_proyecto.'|'.$r->cuenta_contable] = (int) $r->periodo;
        }

        // Inventario en obra = saldo de cuenta 14 acumulado AL MES FILTRADO (es un stock,
        // no un flujo), para la proyección. Mismo corte que el saldo mostrado, para que
        // un mes histórico no se contamine con movimientos posteriores.
        $inventario14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where($corteAcum)
            ->selectRaw('codigo_proyecto, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto')
            ->pluck('saldo', 'codigo_proyecto');

        // Datos comerciales de la ficha: nombre del proyecto, cliente, valor de oferta
        // y costo presupuestado.
        $fichas = FichaProyecto::get(['codigo_proyecto', 'nombre_obra', 'cliente', 'margen_ofertado', 'valor_contratado', 'costo_estimado'])
            ->keyBy('codigo_proyecto');

        $cerradas = ProyectoCerrado::pluck('codigo_proyecto')->flip();
        $estadosObra = ForecastOperativo::where('anio', $anio)
            ->selectRaw('codigo_proyecto, MAX(estado_obra) as estado_obra')
            ->groupBy('codigo_proyecto')
            ->pluck('estado_obra', 'codigo_proyecto');

        $estadoManual = ObraEstado::pluck('estado', 'codigo_proyecto');

        // Observaciones del coordinador para este mes/año
        $observaciones = ObservacionObra::where('anio', $anio)->where('mes', $mes)
            ->pluck('observacion', 'codigo_proyecto');

        // Autorizaciones de gerencia para este mes/año (para proyectos sin ingreso).
        $autorizaciones = AutorizacionDistribucion::where('mes', $mes)->where('anio', $anio)
            ->get()->keyBy('codigo_proyecto');

        // Líneas guardadas DE ESTE borrador (si estoy editando uno). Se separan las
        // aplicaciones propias de la obra (origen_bolsa null) de las que vienen de una
        // bolsa (origen_bolsa) para no repoblar por error los inputs de la obra.
        $guardado = $distribucion
            ? AplicacionCosto::where('distribucion_id', $distribucion->id)
                ->whereNull('origen_bolsa')->get()->groupBy('codigo_proyecto')
            : collect();

        // Asignaciones de bolsa de ESTE borrador (para pintar los chips y descontar
        // el disponible de cada bolsa).
        $asignBolsa = $distribucion
            ? BolsaAsignacion::where('distribucion_id', $distribucion->id)->get()
            : collect();
        $asignPorObra  = $asignBolsa->groupBy('codigo_proyecto');
        $asignPorBolsa = $asignBolsa->groupBy('bolsa_codigo')->map(fn ($g) => (float) $g->sum('monto'));

        $obras = [];
        foreach ($saldos14 as $s) {
            $cod = $s->codigo_proyecto;
            if (!isset($obras[$cod])) {
                $obras[$cod] = $this->nuevaObra(
                    $cod, $s->nombre_proyecto, $cerradas, $estadosObra, $fichas,
                    $ingresoMes, $ingresoAcum, $costoAplMes, $costoAplAcum
                );
            }

            $saldo = round((float) $s->saldo, 2);
            $h     = $homol[(string) $s->cuenta_contable] ?? null;
            $estructura = $h->estructura ?? 'OTROS COSTO';
            if (!isset($this->categorias[$estructura])) $estructura = 'OTROS COSTO';

            $pendiente = $saldo < 0 ? abs($saldo) : 0;
            $reversado = $saldo > 0 ? $saldo : 0;

            $obras[$cod]['cat'][$estructura]['pendiente'] += $pendiente;
            $obras[$cod]['cat'][$estructura]['reversado'] += $reversado;
            $obras[$cod]['cat'][$estructura]['subs'][] = [
                'cuenta_14' => (string) $s->cuenta_contable,
                'cuenta_61' => (string) ($h->cuenta_61 ?? 'SIN HOMOLOGAR'),
                'nombre'    => $h->nombre ?? $s->descripcion,
                'pendiente' => $pendiente,
                'reversado' => $reversado,
                'aplicar'   => $pendiente,
            ];
            $obras[$cod]['total_pendiente'] += $pendiente;
            $obras[$cod]['total_reversado'] += $reversado;
        }

        foreach ($obras as $cod => &$o) {
            $o['provisiones'] = [];
            $g = $guardado[$cod] ?? collect();
            $savedAplicar = $g->where('es_provision', false)->keyBy('cuenta_14');

            // Proyecto sin ingreso en el mes: arranca en 0 (no se propone el pendiente),
            // salvo que ya tenga un valor guardado en el borrador.
            $sinIngreso = abs((float) $o['ingreso_mes']) < 0.5;

            // Reparto FIFO topado al facturado del mes, sobre el SALDO NETO abierto de
            // cada cuenta 14 (respeta el pendiente mostrado) y aplicando cada saldo
            // COMPLETO por antigüedad (sin dejar poquitos). Solo proyectos con ingreso.
            $topeProy = [];
            if (!$sinIngreso) {
                $lineasFifo = [];
                foreach ($o['cat'] as $c) {
                    foreach ($c['subs'] as $sub) {
                        if ($sub['pendiente'] > 0.5) {
                            $lineasFifo[] = [
                                'cuenta_14' => $sub['cuenta_14'],
                                'periodo'   => $periodoCuenta[$cod.'|'.$sub['cuenta_14']] ?? 0,
                                'monto'     => (float) $sub['pendiente'],
                            ];
                        }
                    }
                }
                $cap = max(0.0, (float) $o['ingreso_mes'] - abs((float) $o['costo_apl_mes']));
                $topeProy = $this->repartoFifo($lineasFifo, $cap);
            }

            foreach ($o['cat'] as $k => &$c) {
                foreach ($c['subs'] as &$sub) {
                    // Datos para el reparto FIFO topado al facturado del mes.
                    $sub['periodo'] = $periodoCuenta[$cod.'|'.$sub['cuenta_14']] ?? 0;
                    $sub['tope']    = $topeProy[$sub['cuenta_14']] ?? 0;

                    if (isset($savedAplicar[$sub['cuenta_14']])) {
                        $sub['aplicar'] = (float) $savedAplicar[$sub['cuenta_14']]->monto_aplicar;
                    } elseif ($distribucion || $sinIngreso) {
                        $sub['aplicar'] = 0;
                    } else {
                        // Nuevo borrador con ingreso: proponer el monto topado FIFO
                        // (saldo completo por cuenta, sin exceder el facturado del mes).
                        $sub['aplicar'] = $sub['tope'];
                    }
                }
                unset($sub);
            }
            unset($c);

            foreach ($g->where('es_provision', true) as $p) {
                $o['provisiones'][] = [
                    'cuenta_14'   => $p->cuenta_14,
                    'cuenta_61'   => $p->cuenta_61,
                    'nombre'      => $p->nombre,
                    'monto'       => (float) $p->monto_aplicar,
                    'descripcion' => $p->descripcion,
                ];
            }

            $sumA = 0;
            foreach ($o['cat'] as $c) foreach ($c['subs'] as $s) $sumA += $s['aplicar'];
            $o['sum_aplicar'] = $sumA;
            $o['sum_prov']    = array_sum(array_column($o['provisiones'], 'monto'));

            // Asignaciones de bolsa guardadas para esta obra: cuentan como costo del mes.
            // Se arrastra el detalle (cuentas 14 y períodos) para mostrar trazabilidad.
            $o['asignaciones_bolsa'] = ($asignPorObra[$cod] ?? collect())
                ->map(fn ($a) => [
                    'bolsa'   => $a->bolsa_codigo,
                    'monto'   => (float) $a->monto,
                    'detalle' => is_array($a->detalle) ? $a->detalle : [],
                ])
                ->values()->all();
            $o['sum_bolsa'] = array_sum(array_column($o['asignaciones_bolsa'], 'monto'));

            if (isset($estadoManual[$cod])) {
                $o['estado'] = $estadoManual[$cod];
            }

            // Inventario en obra (cuenta 14 total) e inventario de almacén (0 por ahora)
            $saldoInv14 = (float) ($inventario14[$cod] ?? 0);
            $o['inventario_obra']    = $saldoInv14 < 0 ? abs($saldoInv14) : 0;
            $o['inventario_almacen'] = 0; // en actualización: pendiente módulo de almacén

            $this->calcularMargenes($o);
            $o['tipo']   = $this->tipoObra($cod);
            $o['metodo'] = $o['estado'] === 'abierta'
                ? 'Reclasificar OT áreas → OT operación' : 'Cuenta 14 → 61';
            $o['observacion'] = (string) ($observaciones[$cod] ?? '');

            // Bloqueo por falta de ingreso: si el proyecto no tuvo ingreso en el mes,
            // no se le puede distribuir costo salvo autorización de gerencia aprobada.
            $aut = $autorizaciones[$cod] ?? null;
            $o['requiere_autorizacion'] = abs((float) $o['ingreso_mes']) < 0.5;
            $o['autorizado']            = $aut && $aut->estado === AutorizacionDistribucion::APROBADA;
            $o['autorizacion_estado']   = $aut->estado ?? null; // pendiente|aprobada|rechazada|null
            $o['autorizacion_motivo']   = $aut->motivo ?? null;
            $o['autorizacion_coment']   = $aut->comentario_gerencia ?? null;
            $o['autorizacion_monto']    = $aut->monto_a_distribuir ?? null;
            // Bloqueado en la UI = requiere autorización y aún no está aprobado.
            $o['bloqueado_ingreso']     = $o['requiere_autorizacion'] && ! $o['autorizado'];
        }
        unset($o);

        // Filtro por departamento. El supervisor ve solo su departamento.
        // El director/admin ve todo, salvo que elija un departamento en el filtro.
        $usuario = $request->user();
        $depUsuario = $usuario?->departamentoUnico();               // 'mantenimiento' | 'instalaciones' | null
        $depElegido = $request->get('departamento');               // lo que elige el director/admin

        // Departamento efectivo: el del supervisor manda; si no, el elegido por el director.
        $depEfectivo = $depUsuario ?: ($depElegido ?: null);

        if ($depEfectivo) {
            $prefijosDepto = \App\Models\User::prefijosDeDepartamento($depEfectivo);
        } elseif ($usuario && $usuario->tieneFiltroDepartamento()) {
            // Tiene ambos deptos y no eligió: por seguridad ve solo sus prefijos permitidos
            $prefijosDepto = $usuario->departamentosPermitidos();
        } else {
            $prefijosDepto = null; // admin sin elegir = ve todo
        }

        // Las bolsas de área (UN) son el ORIGEN del costo, no un destino: nunca deben
        // aparecer como una obra en la lista (sí en el panel superior).
        $bolsaCodigos = array_flip(UnBolsa::codigos());

        $obras = array_filter($obras, function ($o) use ($tipo, $estadoFiltro, $prefijosDepto, $proyectosConSaldoNeto, $bolsaCodigos) {
            if (isset($bolsaCodigos[$o['codigo']])) return false;
            // Solo obras con saldo neto real en cuenta 14 (por proyecto).
            if (!isset($proyectosConSaldoNeto[$o['codigo']])) return false;
            if ($tipo !== 'todos' && $o['tipo'] !== $tipo) return false;
            if ($estadoFiltro !== 'todos' && $o['estado'] !== $estadoFiltro) return false;

            // Filtro por departamento: la obra debe empezar por uno de los prefijos permitidos
            if ($prefijosDepto !== null) {
                $cod = strtoupper($o['codigo']);
                $ok = false;
                foreach ($prefijosDepto as $pref) {
                    if (str_starts_with($cod, $pref)) { $ok = true; break; }
                }
                if (!$ok) return false;
            }

            return true;
        });

        uasort($obras, fn($a, $b) =>
            ($a['orden_sem'] <=> $b['orden_sem']) ?: ($b['total_pendiente'] <=> $a['total_pendiente'])
        );

        // Detalle de ítems por cuenta (Fase B/C): los ítems del período de cada obra,
        // agrupados por cuenta de costo, con su neto (salidas − reintegros).
        $this->adjuntarItemsPorCuenta($obras, $mes, $anio);

        // Catálogo para el desplegable de provisiones: las cuentas vigentes EN ESTE PERÍODO.
        $catalogo = Homologacion::vigentesEn($periodo)
            ->orderBy('cuenta_14')
            ->get(['cuenta_14', 'cuenta_61', 'nombre', 'estructura']);

        $bloqueado = $distribucion && $distribucion->estado === 'enviado' && !$distribucion->edicion_habilitada;

        // Panel: DOS bolsas grandes (Mantenimiento e Instalaciones). Cada una con su total
        // (saldo de todas sus UN), su "a distribuir" (suma de montos editados en el cierre) y
        // el disponible = a_distribuir − lo ya asignado en este borrador.
        $bolsas = $this->svc->bolsasGrandes($depEfectivo, $periodo, $anio, $mes);
        foreach ($bolsas as &$bp) {
            $bp['asignado']   = (float) ($asignPorBolsa[$bp['codigo']] ?? 0);
            $bp['disponible'] = max(0.0, round($bp['a_distribuir'] - $bp['asignado'], 2));

            // Pool RESTANTE por (UN|cuenta) para el consumo FIFO en vivo del front: se parte
            // del "a distribuir" y se descuenta lo ya consumido en asignaciones previas del borrador.
            $rest = [];
            foreach ($bp['lineas'] as $l) {
                $k = $l['un_codigo'].'|'.$l['cuenta_14'];
                $rest[$k] = [
                    'un_codigo' => $l['un_codigo'], 'cuenta_14' => $l['cuenta_14'], 'cuenta_61' => $l['cuenta_61'],
                    'periodo'   => $l['periodo'],   'pendiente' => (float) $l['monto_distribuir'],
                ];
            }
            foreach ($asignBolsa->where('bolsa_codigo', $bp['codigo']) as $a) {
                foreach ((array) (is_array($a->detalle) ? $a->detalle : []) as $d) {
                    $k = ($d['un_codigo'] ?? '').'|'.($d['cuenta_14'] ?? '');
                    if (isset($rest[$k])) {
                        $rest[$k]['pendiente'] -= (float) $d['monto'];
                    }
                }
            }
            $bp['pool'] = array_values(array_filter($rest, fn ($r) => $r['pendiente'] > 0.5));
        }
        unset($bp);
        unset($bp);

        // Candidatos destino para reasignar ítems (búsqueda por código/nombre/cliente).
        $destinos = FichaProyecto::get(['codigo_proyecto', 'nombre_obra', 'cliente'])
            ->map(fn ($f) => ['codigo' => $f->codigo_proyecto, 'nombre' => (string) $f->nombre_obra, 'cliente' => (string) $f->cliente])
            ->values();

        return view('operativo.distribucion', [
            'obras'        => $obras,
            'bolsas'       => $bolsas,
            'destinos'     => $destinos,
            'categorias'   => $this->categorias,
            'catalogo'     => $catalogo,
            'mes'          => $mes,
            'anio'         => $anio,
            'tipo'         => $tipo,
            'estadoFiltro' => $estadoFiltro,
            'vista'        => $vista,
            'depUsuario'   => $depUsuario,
            'depEfectivo'  => $depEfectivo,
            'envio'        => $distribucion,
            'distId'       => $distribucion?->id,
            'bloqueado'    => $bloqueado,
            'edicionAbierta' => CierrePeriodo::estaAbierto($mes, $anio),
            'kpiPendiente' => array_sum(array_column($obras, 'total_pendiente')),
            'kpiObras'     => count($obras),
            'kpiAlertas'   => count(array_filter($obras, fn($o) => $o['semaforo'] === 'rojo')),
        ]);
    }

    /** Último período (mes, año) con información cargada en RegistroFinanciero (BIABLE). */
    private function ultimoPeriodoConDatos(): array
    {
        $row = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first(['anio', 'mes']);

        return $row ? [(int) $row->mes, (int) $row->anio] : [(int) date('n'), (int) date('Y')];
    }

    /**
     * Punto 2: guardar los "montos a distribuir" (y observaciones) por cuenta de las
     * bolsas grandes, en el cierre. El disponible de la bolsa = suma de estos montos.
     * Solo con el cierre del período abierto.
     */
    public function guardarBolsaMontos(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $mes  = (int) $request->input('mes');
        $anio = (int) $request->input('anio');
        if (! CierrePeriodo::estaAbierto($mes, $anio)) {
            return back()->with('error',
                'El cierre de '.$mes.'/'.$anio.' no está abierto: no puedes editar los montos a distribuir.');
        }

        $montos = (array) $request->input('monto', []); // ["UN|cuenta" => valor]
        $obs    = (array) $request->input('obs', []);
        $n = 0;
        foreach ($montos as $key => $val) {
            [$un, $cuenta] = array_pad(explode('|', (string) $key, 2), 2, '');
            if ($un === '' || $cuenta === '') continue;
            BolsaMonto::updateOrCreate(
                ['mes' => $mes, 'anio' => $anio, 'un_codigo' => $un, 'cuenta_14' => $cuenta],
                [
                    // El input llega con formato de dinero ("1.500.000"): dejamos solo dígitos.
                    'monto_distribuir' => max(0.0, (float) preg_replace('/[^\d]/', '', (string) $val)),
                    'observaciones'    => trim((string) ($obs[$key] ?? '')) ?: null,
                    'user_id'          => $request->user()?->id,
                ]
            );
            $n++;
        }

        return back()->with('success', "Montos a distribuir actualizados ({$n} cuentas). El disponible de la bolsa quedó en la suma de lo editado.");
    }

    public function guardar(Request $request)
{
    abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
        'No tienes permiso para editar en Operación.');

        $accion = $request->input('accion', 'guardar');
        $distId = $request->input('dist');
        $mes    = (int) $request->mes;
        $anio   = (int) $request->anio;

        // Autoguardado (AJAX): responde JSON en vez de redirigir y nunca "envía"
        // (siempre queda como borrador). Es la red de seguridad que persiste en la BD.
        $esAuto = $request->boolean('auto');
        if ($esAuto) {
            $accion = 'guardar';
        }
        $responderError = fn (string $m) => $esAuto
            ? response()->json(['ok' => false, 'error' => $m], 422)
            : back()->with('error', $m);

        // Refuerzo de solo lectura: solo se puede editar si Contabilidad abrió el cierre
        // de ese mes. Impide guardar/enviar/asignar manipulando el formulario.
        if (! CierrePeriodo::estaAbierto($mes, $anio)) {
            return $responderError(
                'El cierre de '.$mes.'/'.$anio.' no está abierto. La distribución es de solo lectura hasta que Contabilidad abra el cierre de ese mes.');
        }

        $aplicar    = $request->aplicar ?? [];
        $provision  = $request->provision ?? [];
        $estadoObra = $request->estado_obra ?? [];

        $distribucion = $distId ? Distribucion::find($distId) : null;

        if ($distribucion && $distribucion->estado === 'enviado' && !$distribucion->edicion_habilitada) {
            return $responderError('Este borrador ya fue enviado a contabilidad. Pídele a contabilidad que habilite la edición.');
        }

        // Determinar el departamento del plano:
        // - supervisor: su propio departamento
        // - director/admin: el que venga del formulario (campo 'departamento')
        $usuario = $request->user();
        $departamento = $usuario?->departamentoUnico() ?: $request->input('departamento');

        if (!$distribucion && !in_array($departamento, ['mantenimiento', 'instalaciones'])) {
            return $esAuto
                ? response()->json(['ok' => false, 'error' => 'Falta el departamento del plano.'], 422)
                : back()->with('error', 'Debes indicar el departamento del plano (mantenimiento o instalaciones).')->withInput();
        }

        // Refuerzo del bloqueo por falta de ingreso: un proyecto sin ingreso en el mes
        // NO puede recibir costos salvo autorización de gerencia aprobada. Esto impide
        // saltarse el bloqueo manipulando el formulario desde el navegador.
        $ingresoMesG = $this->sumaMes('Ingreso', $anio, $mes);
        $aprobados   = AutorizacionDistribucion::aprobadosEn($mes, $anio);
        $requiereAut = function ($cod) use ($ingresoMesG, $aprobados) {
            $sinIngreso = abs((float) ($ingresoMesG[$cod] ?? 0)) < 0.5;
            return $sinIngreso && ! in_array((string) $cod, $aprobados, true);
        };

        // Refuerzo para proyectos CON ingreso: (1) no aplicar más que el saldo abierto
        // de cada cuenta 14, y (2) el total aplicado del mes no supera el facturado del
        // mes (tope), consumiendo por antigüedad y aplicando saldos completos (FIFO).
        $costoAplMesG = $this->sumaMes('Costos aplicados', $anio, $mes);
        $saldos14G = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            // Corte acumulado al mes distribuido: no se puede reclasificar costo de
            // períodos posteriores al que se está distribuyendo (mismo criterio que la vista).
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                    ->orWhere(function ($s) use ($anio, $mes) {
                        $s->where('anio', $anio)->where('mes', '<=', $mes);
                    });
            })
            ->selectRaw('codigo_proyecto, cuenta_contable, SUM(estado_er) as saldo, MIN(anio*100+mes) as periodo')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->get();
        $pendNetoG = [];   // saldo abierto (neto) por cuenta
        $periodoCuentaG = []; // antigüedad por cuenta
        foreach ($saldos14G as $r) {
            $key = $r->codigo_proyecto.'|'.$r->cuenta_contable;
            $neto = (float) $r->saldo;
            $pendNetoG[$key]      = $neto < 0 ? abs($neto) : 0.0;
            $periodoCuentaG[$key] = (int) $r->periodo;
        }
        foreach ($aplicar as $cod => $cuentas) {
            $ing = (float) ($ingresoMesG[$cod] ?? 0);
            if (abs($ing) < 0.5) continue; // sin ingreso: lo maneja el bloqueo de autorización
            $cap = max(0.0, $ing - abs((float) ($costoAplMesG[$cod] ?? 0)));
            $lineas = [];
            foreach ((array) $cuentas as $c14 => $monto) {
                // No aplicar más que el saldo abierto de la cuenta.
                $monto = min((float) $monto, $pendNetoG[$cod.'|'.$c14] ?? 0.0);
                if ($monto <= 0.5) continue;
                $lineas[] = [
                    'cuenta_14' => (string) $c14,
                    'periodo'   => $periodoCuentaG[$cod.'|'.$c14] ?? PHP_INT_MAX,
                    'monto'     => $monto,
                ];
            }
            // Reparto FIFO (saldos completos por antigüedad) sin exceder el tope.
            $aplicar[$cod] = $this->repartoFifo($lineas, $cap);
        }

        // ── Asignaciones desde bolsas de área (origen del costo) ──
        // Formato del form: asignacion_bolsa[cod][idx] = ['bolsa'=>..., 'monto'=>...].
        // Se agregan por (obra, bolsa) y se validan contra el saldo real de la bolsa:
        // no se puede asignar más que su disponible (refuerzo de servidor del tope).
        // El "bolsa" del form ahora es el DEPARTAMENTO (bolsa grande: mantenimiento/instalaciones).
        $asignInput = $request->input('asignacion_bolsa', []);
        $asignPorObraBolsa = [];
        foreach ((array) $asignInput as $cod => $items) {
            if ($requiereAut($cod)) continue; // sin ingreso y sin autorización: no recibe costo
            foreach ((array) $items as $it) {
                $bolsa = trim((string) ($it['bolsa'] ?? ''));
                $monto = (float) ($it['monto'] ?? 0);
                if ($bolsa === '' || $monto <= 0.5) continue;
                $asignPorObraBolsa[$cod][$bolsa] = ($asignPorObraBolsa[$cod][$bolsa] ?? 0) + $monto;
            }
        }

        $periodoG = Homologacion::periodo($anio, $mes);
        // Bolsas grandes (por departamento): sus líneas UN+cuenta ya vienen capadas al
        // "monto a distribuir" editado en el cierre; el disponible = suma de esos montos.
        $grandes = collect($this->svc->bolsasGrandes(null, $periodoG, $anio, $mes))->keyBy('codigo');
        $poolBolsa   = []; // [depto] => [ {un_codigo,cuenta_14,periodo,monto} ]  (mutable, se drena FIFO)
        $infoGrande  = []; // [depto][un|cuenta] => línea (cuenta_61, estructura, nombre)
        $dispBolsaAD = []; // [depto] => a_distribuir (disponible)
        foreach ($grandes as $d => $g) {
            $dispBolsaAD[$d] = (float) $g['a_distribuir'];
            foreach ($g['lineas'] as $l) {
                $poolBolsa[$d][] = [
                    'un_codigo' => $l['un_codigo'], 'cuenta_14' => $l['cuenta_14'],
                    'periodo' => $l['periodo'], 'monto' => (float) $l['monto_distribuir'],
                ];
                $infoGrande[$d][$l['un_codigo'].'|'.$l['cuenta_14']] = $l;
            }
        }

        // Tope por bolsa grande: recortar lo que exceda el disponible (suma de montos a distribuir).
        $restanteBolsa = $dispBolsaAD;
        $asignFinal = [];
        $recortes = [];
        foreach ($asignPorObraBolsa as $cod => $porBolsa) {
            foreach ($porBolsa as $bolsa => $monto) {
                $disp = $restanteBolsa[$bolsa] ?? 0;
                $usar = min($monto, max(0.0, $disp));
                if ($usar <= 0.5) { $recortes[$bolsa] = true; continue; }
                if ($usar < $monto - 0.5) $recortes[$bolsa] = true;
                $asignFinal[$cod][$bolsa] = round($usar, 2);
                $restanteBolsa[$bolsa]    = $disp - $usar;
            }
        }

        // Todo el guardado (crear/actualizar el borrador, estados de obra, borrar y
        // reinsertar las líneas de AplicacionCosto, observaciones y la versión) va en
        // una sola transacción: si algo falla a mitad, no queda un plano parcial.
        $noCerradas = [];
        $msg = '';

        DB::transaction(function () use (
            &$distribucion, &$noCerradas, &$msg,
            $departamento, $mes, $anio, $estadoObra, $aplicar, $provision, $accion, $request, $requiereAut,
            $asignFinal, $poolBolsa, $infoGrande, $esAuto
        ) {
            if (!$distribucion) {
                // Numeración separada por departamento
                $version = (Distribucion::where('mes', $mes)->where('anio', $anio)
                            ->where('departamento', $departamento)->max('version') ?? 0) + 1;
                $distribucion = Distribucion::create([
                    'mes' => $mes, 'anio' => $anio, 'departamento' => $departamento,
                    'version' => $version, 'estado' => 'borrador',
                    'edicion_habilitada' => false, 'guardado_por' => $request->user()?->id,
                ]);
            } else {
                $distribucion->guardado_por = $request->user()?->id;
            }

            $cerrar = array_keys(array_filter($estadoObra, fn($e) => $e === 'cerrada'));
            $pendientePorObra = collect();
            if (!empty($cerrar)) {
                $pendientePorObra = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
                    ->whereIn('codigo_proyecto', $cerrar)
                    ->selectRaw('codigo_proyecto, SUM(estado_er) as saldo')
                    ->groupBy('codigo_proyecto')
                    ->pluck('saldo', 'codigo_proyecto');
            }

            foreach ($estadoObra as $cod => $est) {
                if (!in_array($est, ['abierta', 'parcial', 'cerrada'])) continue;
                if ($est === 'cerrada') {
                    $saldo     = (float) ($pendientePorObra[$cod] ?? 0);
                    $pendAbs   = $saldo < 0 ? abs($saldo) : 0;
                    $reversado = $saldo > 0 ? $saldo : 0;
                    $aplicado  = array_sum(array_map('floatval', $aplicar[$cod] ?? []));
                    if ($reversado > 0.5 || $aplicado < $pendAbs - 0.5) {
                        $est = 'parcial';
                        $noCerradas[] = $cod;
                    }
                }
                ObraEstado::updateOrCreate(
                    ['codigo_proyecto' => $cod],
                    ['estado' => $est, 'user_id' => $request->user()?->id]
                );
            }

            // IMPORTANTE: la cuenta 61 y la estructura que se copian a AplicacionCosto quedan
            // CONGELADAS. Deben ser las del período que se está distribuyendo, no las de hoy.
            $periodo = Homologacion::periodo($anio, $mes);
            $homol   = Homologacion::mapaEn($periodo);

            AplicacionCosto::where('distribucion_id', $distribucion->id)->delete();

            foreach ($aplicar as $cod => $cuentas) {
                if ($requiereAut($cod)) continue; // proyecto sin ingreso y sin autorización aprobada
                foreach ($cuentas as $c14 => $monto) {
                    $monto = (float) $monto;
                    if ($monto <= 0) continue;
                    $h = $homol[(string) $c14] ?? null;
                    AplicacionCosto::create([
                        'distribucion_id' => $distribucion->id,
                        'mes' => $mes, 'anio' => $anio, 'codigo_proyecto' => $cod,
                        'cuenta_14' => $c14, 'cuenta_61' => $h->cuenta_61 ?? 'SIN HOMOLOGAR',
                        'categoria' => $h->estructura ?? null, 'nombre' => $h->nombre ?? null,
                        'monto_aplicar' => $monto, 'es_provision' => false,
                        'estado' => 'borrador', 'user_id' => $request->user()?->id,
                    ]);
                }
            }

            foreach ($provision as $cod => $items) {
                if ($requiereAut($cod)) continue; // proyecto sin ingreso y sin autorización aprobada
                foreach ($items as $p) {
                    $monto = (float) ($p['monto'] ?? 0);
                    $c14   = $p['cuenta'] ?? null;
                    if ($monto <= 0 || !$c14) continue;
                    $h = $homol[(string) $c14] ?? null;
                    AplicacionCosto::create([
                        'distribucion_id' => $distribucion->id,
                        'mes' => $mes, 'anio' => $anio, 'codigo_proyecto' => $cod,
                        'cuenta_14' => $c14, 'cuenta_61' => $h->cuenta_61 ?? 'SIN HOMOLOGAR',
                        'categoria' => $h->estructura ?? null, 'nombre' => $h->nombre ?? null,
                        'monto_aplicar' => $monto, 'es_provision' => true,
                        'descripcion' => $p['desc'] ?? null,
                        'estado' => 'borrador', 'user_id' => $request->user()?->id,
                    ]);
                }
            }

            // ── Asignaciones de bolsa: persistir y reflejar en el costo del proyecto ──
            // (las líneas origen_bolsa de aplicaciones_costo ya se borraron con el delete
            //  general de arriba). Cada asignación se baja a las cuentas 14 reales de la
            //  bolsa (FIFO, drenando el pool) para que el plano acredite las cuentas
            //  correctas y el resumen la cuente como costo del mes de la obra.
            BolsaAsignacion::where('distribucion_id', $distribucion->id)->delete();

            foreach ($asignFinal as $cod => $porBolsa) {
                foreach ($porBolsa as $bolsa => $monto) { // $bolsa = departamento (bolsa grande)
                    $info = $infoGrande[$bolsa] ?? [];
                    // Consumo FIFO real (más antiguo primero) sobre el pool de la bolsa grande:
                    // devuelve porciones por (UN, cuenta) — la UN real de origen.
                    $porciones = $this->svc->drenarBolsaGrande($poolBolsa[$bolsa], (float) $monto);

                    $detalle = [];
                    foreach ($porciones as $p) {
                        if ($p['monto'] <= 0.005) continue;
                        $un  = $p['un_codigo'];
                        $c14 = $p['cuenta_14'];
                        $l   = $info[$un.'|'.$c14] ?? [];
                        $c61 = $l['cuenta_61'] ?? 'SIN HOMOLOGAR';

                        // Trazabilidad: de qué UN, cuenta 14 y período salió cada porción.
                        $detalle[] = [
                            'un_codigo' => (string) $un,
                            'cuenta_14' => (string) $c14,
                            'cuenta_61' => (string) $c61,
                            'periodo'   => (int) ($l['periodo'] ?? 0),
                            'monto'     => round($p['monto'], 2),
                        ];

                        // El plano acredita la cuenta 14 de la UN REAL de origen: origen_bolsa = UN.
                        AplicacionCosto::create([
                            'distribucion_id' => $distribucion->id,
                            'mes' => $mes, 'anio' => $anio, 'codigo_proyecto' => $cod,
                            'cuenta_14' => $c14, 'origen_bolsa' => $un,
                            'cuenta_61' => $c61,
                            'categoria' => $l['estructura'] ?? null,
                            'nombre'    => $l['nombre'] ?? null,
                            'monto_aplicar' => round($p['monto'], 2), 'es_provision' => false,
                            'descripcion' => 'Desde bolsa '.(\App\Services\DistribucionService::DEPARTAMENTOS[$bolsa] ?? $bolsa).' · UN '.$un,
                            'estado' => 'borrador', 'user_id' => $request->user()?->id,
                        ]);
                    }

                    BolsaAsignacion::create([
                        'distribucion_id' => $distribucion->id,
                        'mes' => $mes, 'anio' => $anio, 'departamento' => $departamento,
                        'bolsa_codigo' => $bolsa, 'codigo_proyecto' => $cod, // bolsa_codigo = departamento
                        'monto' => round((float) $monto, 2), 'detalle' => $detalle,
                        'user_id' => $request->user()?->id,
                    ]);
                }
            }

            // Conciliación FIFO: marcar reconocidos los ítems comerciales que cubre esta
            // reclasificación 14→61 (los más antiguos primero, por obra y cuenta).
            $this->reconocerItemsFifo($distribucion, $mes, $anio);

            // Guardar observaciones del coordinador (por obra, mes y año)
            $observacionesInput = $request->input('observacion', []);
            foreach ($observacionesInput as $cod => $texto) {
                $texto = trim((string) $texto);
                ObservacionObra::updateOrCreate(
                    ['codigo_proyecto' => $cod, 'mes' => $mes, 'anio' => $anio],
                    ['observacion' => $texto !== '' ? $texto : null, 'user_id' => $request->user()?->id]
                );
            }

            if ($accion === 'enviar') {
                $distribucion->estado = 'enviado';
                $distribucion->reemplazada = false; // esta pasa a ser la vigente
                $distribucion->edicion_habilitada = false;
                $distribucion->enviado_at = now();
                $distribucion->enviado_por = $request->user()?->id;
                $msg = 'Borrador enviado a contabilidad. Queda en solo lectura.';
            } else {
                $msg = 'Borrador guardado.';
            }
            $distribucion->save();

            // Reenvío: cualquier OTRA versión enviada del mismo mes/año/departamento queda
            // reemplazada, para que el plano de contabilidad no tenga dudas de cuál es la
            // vigente (evita que un reenvío se lea contra una versión anterior).
            if ($accion === 'enviar') {
                Distribucion::where('mes', $distribucion->mes)
                    ->where('anio', $distribucion->anio)
                    ->where('departamento', $distribucion->departamento)
                    ->where('id', '!=', $distribucion->id)
                    ->where('estado', 'enviado')
                    ->update(['reemplazada' => true]);
            }

            // Registrar la versión en la bitácora (foto congelada de este momento).
            // En autoguardado NO se registra, para no llenar la bitácora de snapshots.
            if (! $esAuto) {
                $evento = $accion === 'enviar' ? 'enviado' : 'guardado';
                $this->registrarVersion($distribucion, $evento, $request);
            }
        });

        if (!empty($noCerradas)) {
            $msg .= ' Nota: ' . implode(', ', $noCerradas) . ' no se pudieron cerrar (saldo abierto en cuenta 14); quedaron en parcial.';
        }
        if (!empty($recortes)) {
            $msg .= ' Nota: se recortaron asignaciones que superaban el saldo disponible de la(s) bolsa(s): ' . implode(', ', array_keys($recortes)) . '.';
        }

        if ($esAuto) {
            return response()->json([
                'ok'   => true,
                'dist' => $distribucion->id,
                'hora' => now()->format('h:i a'),
            ]);
        }

        return redirect()->route('operativo.distribucion', ['dist' => $distribucion->id])->with('success', $msg);
    }

   public function eliminar(Distribucion $distribucion)
{
    abort_unless(request()->user()->puedeEditarModulo('operacion'), 403,
        'No tienes permiso para editar en Operación.');

    if ($distribucion->estado === 'enviado') {
        return back()->with('error', 'No puedes eliminar un borrador ya enviado a contabilidad.');
    }
    AplicacionCosto::where('distribucion_id', $distribucion->id)->delete();
    $distribucion->delete();
    return back()->with('success', 'Borrador eliminado.');
}

    /**
     * Fase D: reasignar un ítem de una obra a otra. Mueve el ítem (cambia codigo_obra:
     * baja el costo en el origen y sube en el destino), deja trazabilidad y se refleja
     * en el plano como una reclasificación 14→14 (misma cuenta, cambia la UN).
     */
    public function reasignarItem(Request $request, ItemDistribucion $item)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $datos = $request->validate([
            'destino' => 'required|string|max:60',
            'motivo'  => 'nullable|string|max:500',
        ]);

        $origen  = $item->codigo_obra;
        $destino = trim($datos['destino']);
        if ($destino === '' || strcasecmp($destino, $origen) === 0) {
            return back()->with('error', 'La obra destino debe ser distinta de la de origen.');
        }

        DB::transaction(function () use ($item, $origen, $destino, $datos, $request) {
            ReasignacionItem::create([
                'item_distribucion_id' => $item->id,
                'mes' => $item->mes, 'anio' => $item->anio,
                'codigo_obra_origen' => $origen, 'codigo_obra_destino' => $destino,
                'cuenta' => $item->cuenta, 'item' => $item->item,
                'costo' => abs((float) $item->costo), 'naturaleza' => $item->naturaleza,
                'motivo' => $datos['motivo'] ?? null,
                'user_id' => $request->user()?->id, 'user_nombre' => $request->user()?->name,
            ]);

            // Mover el ítem a la obra destino. Se reinicia el reconocimiento: ahora
            // pertenece a otra obra y deberá reconocerse con la distribución del destino.
            $item->codigo_obra      = $destino;
            $item->reconocido       = false;
            $item->monto_reconocido = 0;
            $item->reconocido_at    = null;
            $item->distribucion_id  = null;
            $item->save();
        });

        return redirect()->route('operativo.distribucion', array_filter([
            'mes' => $item->mes, 'anio' => $item->anio, 'dist' => $request->input('dist'),
        ]))->with('success', "Ítem reasignado de {$origen} a {$destino}. Reclasificación de UN (14→14) reflejada en el plano.");
    }

    public function resumen(Request $request)
    {
        $mes  = (int) $request->input('mes', date('n'));
        $anio = (int) $request->input('anio', date('Y'));

        // Departamento del informe: del supervisor, o el elegido por el director/admin
        $usuario = $request->user();
        $departamento = $usuario?->departamentoUnico() ?: $request->input('departamento', 'mantenimiento');
        if (!in_array($departamento, ['mantenimiento', 'instalaciones'])) {
            $departamento = 'mantenimiento';
        }

        // El resumen (pantalla y Excel) se arma con EXACTAMENTE los mismos datos: lo que
        // el operador escribió en "aplicar" y las asignaciones de bolsa. El botón de
        // descarga reenvía este mismo payload (ver los hidden en la vista), así el Excel
        // no recalcula con datos distintos.
        $aplicar    = $request->input('aplicar', []);
        $asignBolsa = $this->agruparAsignBolsa($request->input('asignacion_bolsa', []));

        $datos = $this->construirResumen($mes, $anio, $departamento, $aplicar, $asignBolsa);

        $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        $periodo = ($nombresMes[$mes] ?? '').' '.$anio;

        if ($request->input('descargar') == '1') {
            $archivo = 'Resumen_'.ucfirst($departamento).'_'.str_replace(' ', '_', $periodo).'.xlsx';
            return Excel::download(new ResumenDistribucionExport(
                $datos['tabla'], $datos['todo'], $datos['tipos'], $datos['categorias'], $periodo
            ), $archivo);
        }

        return view('operativo.distribucion-resumen', array_merge($datos, [
            'mes'          => $mes,
            'anio'         => $anio,
            'departamento' => $departamento,
            'periodo'      => $periodo,
            'descargar'    => false,
            'aplicar'      => $aplicar,     // para reenviar el mismo payload en la descarga
            'asignBolsa'   => $asignBolsa,
        ]));
    }

    /**
     * Informe: total FACTURADO del período agrupado por tipo de obra (según tipoObra()).
     * Filtrable por mes/año y (para director/admin) por departamento. Descargable en Excel.
     */
    public function facturado(Request $request)
    {
        $mes  = (int) $request->input('mes', date('n'));
        $anio = (int) $request->input('anio', date('Y'));

        $usuario     = $request->user();
        $depUsuario  = $usuario?->departamentoUnico();
        $depElegido  = $request->input('departamento');
        $depEfectivo = $depUsuario ?: ($depElegido ?: null);
        $prefijos    = $depEfectivo ? User::prefijosDeDepartamento($depEfectivo) : null;

        $labels = [
            'obras'      => 'Obras',
            'contrato'   => 'Contratos',
            'reparacion' => 'Reparaciones',
            'garantia'   => 'Garantías',
            'otro'       => 'Otros',
        ];
        $porTipo = array_fill_keys(array_keys($labels), 0.0);
        $total   = 0.0;

        $bolsaCodigos = array_flip(UnBolsa::codigos()); // las bolsas no son facturación
        $ingresoMes   = $this->sumaMes('Ingreso', $anio, $mes);

        foreach ($ingresoMes as $cod => $val) {
            if (isset($bolsaCodigos[$cod])) continue;
            if ($prefijos !== null) {
                $c = strtoupper((string) $cod); $ok = false;
                foreach ($prefijos as $p) if (str_starts_with($c, $p)) { $ok = true; break; }
                if (!$ok) continue;
            }
            $tk = $this->tipoObra((string) $cod);
            if (!isset($porTipo[$tk])) $porTipo[$tk] = 0.0;
            $porTipo[$tk] += (float) $val;
            $total += (float) $val;
        }

        // Filas para la vista/Excel: solo los tipos con facturación (orden del menú).
        $filas = [];
        foreach ($labels as $tk => $label) {
            if (abs($porTipo[$tk]) > 0.5) {
                $filas[] = ['tipo' => $tk, 'label' => $label, 'total' => $porTipo[$tk]];
            }
        }

        $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        $periodo = ($nombresMes[$mes] ?? '').' '.$anio;

        if ($request->input('descargar') == '1') {
            $suf = $depEfectivo ? '_'.ucfirst($depEfectivo) : '';
            $archivo = 'Facturado_por_tipo'.$suf.'_'.str_replace(' ', '_', $periodo).'.xlsx';
            return Excel::download(new FacturadoTipoExport($filas, $total, $periodo), $archivo);
        }

        return view('operativo.facturado', [
            'mes'         => $mes,
            'anio'        => $anio,
            'periodo'     => $periodo,
            'depUsuario'  => $depUsuario,
            'depEfectivo' => $depEfectivo,
            'filas'       => $filas,
            'total'       => $total,
        ]);
    }

    /**
     * Adjunta a cada obra el detalle de ítems por cuenta (vista de CONCILIACIÓN, no
     * aditiva): el costo ya está en la cuenta; aquí solo se listan los ítems que la
     * componen y se contrasta la suma de ítems (salidas − reintegros) contra el total
     * real de la cuenta (costo del período ya en la cuenta 6). La diferencia detecta
     * ítems faltantes o sin cruzar.
     * $o['items_por_cuenta'] = [cuenta => ['suma_items','total_cuenta','diferencia','cuadra','items'=>[]]].
     */
    private function adjuntarItemsPorCuenta(array &$obras, int $mes, int $anio): void
    {
        foreach ($obras as $cod => &$o) {
            $o['items_por_cuenta'] = [];
        }
        unset($o);

        if (empty($obras)) {
            return;
        }
        $codigos = array_keys($obras);

        // Saldo real por (obra, cuenta 14): la conciliación es contra la CUENTA 14
        // ('Costos por aplicar'), NO contra la cuenta 6. Los ítems se agrupan por su
        // cuenta 14 (la de la llave), así que el total debe salir de la misma cuenta 14
        // para que las llaves coincidan. Mismo corte acumulado al mes que el saldo de la obra.
        $totales = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                    ->orWhere(function ($s) use ($anio, $mes) {
                        $s->where('anio', $anio)->where('mes', '<=', $mes);
                    });
            })
            ->selectRaw('codigo_proyecto, cuenta_contable, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->get();
        foreach ($totales as $t) {
            $cod = $t->codigo_proyecto;
            if (!isset($obras[$cod])) continue;
            $cta = (string) $t->cuenta_contable;
            $obras[$cod]['items_por_cuenta'][$cta] = ['suma_items' => 0.0, 'total_cuenta' => abs((float) $t->total), 'reconocido_total' => 0.0, 'items' => []];
        }

        // Ítems ACUMULADOS al mes filtrado por (obra, cuenta): mismo corte que el saldo
        // de la cuenta 14, para que la suma de ítems pendientes cuadre con el saldo
        // acumulado de la cuenta (no solo con el movimiento del propio mes).
        $items = ItemDistribucion::whereIn('codigo_obra', $codigos)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                    ->orWhere(function ($s) use ($anio, $mes) {
                        $s->where('anio', $anio)->where('mes', '<=', $mes);
                    });
            })
            ->orderBy('cuenta')->orderBy('fecha')->orderBy('id')
            ->get();
        foreach ($items as $it) {
            $cod = $it->codigo_obra;
            if (!isset($obras[$cod])) continue;
            $cta = (string) $it->cuenta;
            if ($cta === '') continue; // ítem sin cuenta (no cruzó la llave): se reporta en el cargue, no en la conciliación
            if (!isset($obras[$cod]['items_por_cuenta'][$cta])) {
                $obras[$cod]['items_por_cuenta'][$cta] = ['suma_items' => 0.0, 'total_cuenta' => 0.0, 'reconocido_total' => 0.0, 'items' => []];
            }
            $obras[$cod]['items_por_cuenta'][$cta]['suma_items'] += $it->costoNeto();
            $obras[$cod]['items_por_cuenta'][$cta]['items'][] = [
                'id'               => $it->id,
                'item'             => (string) $it->item,
                'tipo_inventario'  => (string) $it->tipo_inventario,
                'movimiento'       => trim((string) $it->codigo_movimiento . ' ' . (string) $it->tipo_movimiento),
                'tercero'          => (string) $it->tercero,
                'cantidad'         => $it->cantidad,
                'fecha'            => $it->fecha ? $it->fecha->format('d/m/Y') : '',
                'numero_documento' => (string) $it->numero_documento,
                'costo'            => abs((float) $it->costo),
                'reintegro'        => $it->esReintegro(),
                'reconocido'       => (bool) $it->reconocido,
                'monto_reconocido' => (float) $it->monto_reconocido,
                'pendiente'        => $it->pendiente(),
            ];
        }

        // Conciliación contra la CUENTA 14 usando el ESTADO CONTABLE REAL.
        // El "reconocido" (14→61 ya reclasificado) NO se toma del reconocimiento propio de la
        // app —contabilidad lo hace en BIABLE—, sino del hecho de que el saldo de la cuenta 14
        // ya bajó: Reconocido = suma de ítems − saldo pendiente de la cuenta 14. Se reparte
        // FIFO (los ítems más antiguos quedan reconocidos; los más nuevos, que suman el saldo
        // de la cuenta 14, quedan pendientes). Así Pendiente = saldo cuenta 14 → cuadra.
        foreach ($obras as $cod => &$o) {
            if (empty($o['items_por_cuenta'])) continue;
            ksort($o['items_por_cuenta']);
            foreach ($o['items_por_cuenta'] as $cta => &$g) {
                $g['suma_items']       = round($g['suma_items'], 2);
                $g['reconocido_total'] = round(max(0.0, $g['suma_items'] - $g['total_cuenta']), 2);
                $g['pendiente_total']  = round($g['suma_items'] - $g['reconocido_total'], 2);
                $g['diferencia']       = round($g['total_cuenta'] - $g['pendiente_total'], 2);
                $g['cuadra']           = abs($g['diferencia']) <= 0.5;

                // FIFO por fecha: marcar reconocidos los ítems más antiguos hasta cubrir el
                // monto reconocido; el resto (los más nuevos) quedan pendientes.
                $rem = $g['reconocido_total'];
                foreach ($g['items'] as &$it) {
                    $c = (float) $it['costo'];
                    if ($rem >= $c - 0.005) {
                        $it['reconocido'] = true;  $it['monto_reconocido'] = $c;              $it['pendiente'] = 0.0;
                        $rem -= $c;
                    } elseif ($rem > 0.005) {
                        $it['reconocido'] = false; $it['monto_reconocido'] = round($rem, 2);  $it['pendiente'] = round($c - $rem, 2);
                        $rem = 0.0;
                    } else {
                        $it['reconocido'] = false; $it['monto_reconocido'] = 0.0;             $it['pendiente'] = $c;
                    }
                }
                unset($it);
            }
            unset($g);
        }
        unset($o);
    }

    /**
     * Conciliación FIFO: al reclasificar 14→61, marca reconocidos los ítems comerciales
     * pendientes de cada (obra, cuenta) del período, del más antiguo al más nuevo, hasta
     * cubrir el monto aplicado. Reconocimiento parcial vía monto_reconocido. Idempotente:
     * al re-guardar/reenviar deshace primero lo que esta distribución había reconocido.
     */
    private function reconocerItemsFifo(Distribucion $distribucion, int $mes, int $anio): void
    {
        ItemDistribucion::where('distribucion_id', $distribucion->id)->update([
            'reconocido' => false, 'monto_reconocido' => 0, 'reconocido_at' => null, 'distribucion_id' => null,
        ]);

        // Monto reclasificado (14→61) por (obra, cuenta 14): solo aplicaciones PROPIAS de
        // la obra (no provisiones ni costo de bolsas, que no salen del inventario de ítems).
        // Los ítems se agrupan por su cuenta 14 (la de la llave), así que el reconocimiento
        // consume los ítems de esa misma cuenta 14.
        $aplicado = AplicacionCosto::where('distribucion_id', $distribucion->id)
            ->where('es_provision', false)->whereNull('origen_bolsa')
            ->selectRaw('codigo_proyecto, cuenta_14, SUM(monto_aplicar) as total')
            ->groupBy('codigo_proyecto', 'cuenta_14')
            ->get();

        foreach ($aplicado as $ap) {
            $restante = (float) $ap->total;
            if ($restante <= 0.5) continue;

            $items = ItemDistribucion::where('codigo_obra', $ap->codigo_proyecto)
                ->where('cuenta', $ap->cuenta_14)
                ->where('mes', $mes)->where('anio', $anio)
                ->orderBy('fecha')->orderBy('id')
                ->get();

            foreach ($items as $it) {
                if ($restante <= 0.005) break;
                $pendiente = abs((float) $it->costo) - (float) $it->monto_reconocido;
                if ($pendiente <= 0.005) continue; // ya reconocido (por otra distribución)
                $usar = min($pendiente, $restante);
                $it->monto_reconocido = (float) $it->monto_reconocido + $usar;
                $it->reconocido       = $it->monto_reconocido >= abs((float) $it->costo) - 0.005;
                $it->reconocido_at    = now();
                $it->distribucion_id  = $distribucion->id;
                $it->save();
                $restante -= $usar;
            }
        }
    }

    /** Convierte el input del form (asignacion_bolsa[cod][idx]=['bolsa','monto']) a [cod => [bolsa => monto]]. */
    private function agruparAsignBolsa($input): array
    {
        $out = [];
        foreach ((array) $input as $cod => $items) {
            foreach ((array) $items as $it) {
                $bolsa = trim((string) ($it['bolsa'] ?? ''));
                $monto = (float) ($it['monto'] ?? 0);
                if ($bolsa === '' || $monto <= 0.5) continue;
                $out[$cod][$bolsa] = ($out[$cod][$bolsa] ?? 0) + $monto;
            }
        }
        return $out;
    }

    // Lógica compartida: arma la tabla del resumen para un departamento concreto.
    // $asignBolsa = [cod => [bolsa => monto]] (costo asignado desde bolsas de área).
    private function construirResumen(int $mes, int $anio, string $departamento, array $aplicar = [], array $asignBolsa = []): array
    {
        if ($departamento === 'instalaciones') {
            $tipos = ['obras' => 'Obras', 'garantia' => 'Garantías'];
            $prefijos = ['GI', 'O'];
        } else {
            $tipos = ['obras' => 'Obras', 'contrato' => 'Contratos', 'reparacion' => 'Reparaciones', 'garantia' => 'Garantías'];
            $prefijos = ['C', 'R', 'MO', 'GM'];
        }
        $categorias = $this->categorias;

        $esDelDepto = function ($cod) use ($prefijos) {
            $c = strtoupper((string) $cod);
            foreach ($prefijos as $p) if (str_starts_with($c, $p)) return true;
            return false;
        };

        // CLAVE: el resumen de un mes pasado debe clasificar los costos con la homologación
        // que estaba vigente ENTONCES. Si usáramos la de hoy, cambiar una cuenta reclasificaría
        // retroactivamente informes ya entregados.
        $periodoContable = Homologacion::periodo($anio, $mes);

        $homolAll = Homologacion::vigentesEn($periodoContable)
            ->get(['cuenta_14', 'cuenta_61', 'estructura']);

        $mapa14 = $homolAll->keyBy(fn($h) => (string) $h->cuenta_14);

        $extraer14 = function ($desc) {
            if (!$desc) return null;
            if (preg_match('/(\d{6,})\s*$/', trim($desc), $m)) return $m[1];
            return null;
        };

        $ingresoMes = $this->sumaMes('Ingreso', $anio, $mes);

        // Costo ya en cuenta 6: traemos proyecto, cuenta_contable (la 61 real) y descripción (trae la 14)
        $costoC6Cat = RegistroFinanciero::where('cuenta_mayor', 'Costos aplicados')
            ->where('anio', $anio)->where('mes', $mes)
            ->selectRaw('codigo_proyecto, cuenta_contable, descripcion, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto', 'cuenta_contable', 'descripcion')
            ->get();

        $tabla = [];
        foreach ($tipos as $tk => $tl) {
            $tabla[$tk] = ['ingreso' => 0.0, 'cat' => [], 'detalle' => []];
            foreach ($categorias as $ck => $cl) {
                $tabla[$tk]['cat'][$ck] = 0.0;
                $tabla[$tk]['detalle'][$ck] = [];   // aquí van las líneas de cada celda
            }
        }

        // 1) Ingreso del mes por tipo
        foreach ($ingresoMes as $cod => $val) {
            if (!$esDelDepto($cod)) continue;
            $tk = $this->tipoObra((string) $cod);
            if (!isset($tabla[$tk])) continue;
            $tabla[$tk]['ingreso'] += (float) $val;
        }

        // 2) Costo ya en cuenta 6 (triangulando por la 14 de la descripción)
        foreach ($costoC6Cat as $r) {
            if (!$esDelDepto($r->codigo_proyecto)) continue;
            $tk = $this->tipoObra((string) $r->codigo_proyecto);
            if (!isset($tabla[$tk])) continue;
            $c14 = $extraer14($r->descripcion);
            $h   = $c14 ? ($mapa14[$c14] ?? null) : null;
            $ck  = $h->estructura ?? 'OTROS COSTO';
            if (!isset($categorias[$ck])) $ck = 'OTROS COSTO';
            $monto = abs((float) $r->total);
            $tabla[$tk]['cat'][$ck] += $monto;
            $tabla[$tk]['detalle'][$ck][] = [
                'proyecto' => (string) $r->codigo_proyecto,
                'cuenta_14' => $c14 ?: '—',
                'cuenta_61' => (string) $r->cuenta_contable,
                'monto'    => $monto,
                'origen'   => 'ya6',   // ya estaba en la cuenta 6
            ];
        }

        // 3) Costo que se aplica ahora (14 -> 6)
        foreach ($aplicar as $cod => $cuentas) {
            if (!$esDelDepto($cod)) continue;
            $tk = $this->tipoObra((string) $cod);
            if (!isset($tabla[$tk])) continue;
            foreach ($cuentas as $c14 => $monto) {
                $monto = (float) $monto;
                if ($monto <= 0) continue;
                $h  = $mapa14[(string) $c14] ?? null;
                $ck = $h->estructura ?? 'OTROS COSTO';
                if (!isset($categorias[$ck])) $ck = 'OTROS COSTO';
                $tabla[$tk]['cat'][$ck] += $monto;
                $tabla[$tk]['detalle'][$ck][] = [
                    'proyecto' => (string) $cod,
                    'cuenta_14' => (string) $c14,
                    'cuenta_61' => (string) ($h->cuenta_61 ?? 'SIN HOMOLOGAR'),
                    'monto'    => $monto,
                    'origen'   => 'aplic',  // se aplica ahora
                ];
            }
        }

        // 4) Costo asignado desde bolsas de área (14 -> 6), repartido a las cuentas 14
        //    reales de la bolsa por FIFO (mismo criterio que al guardar) y clasificado
        //    por su estructura. Así el resumen incluye lo que se ve en la pantalla.
        if (!empty($asignBolsa)) {
            // El "bolsa" es ahora el departamento (bolsa grande). Se drena su pool de
            // líneas UN+cuenta (capadas al monto a distribuir) en FIFO, igual que al guardar.
            $grandes = collect($this->svc->bolsasGrandes(null, $periodoContable, $anio, $mes))->keyBy('codigo');
            $pool = [];
            foreach ($grandes as $d => $g) {
                $pool[$d] = array_map(fn ($l) => [
                    'un_codigo' => $l['un_codigo'], 'cuenta_14' => $l['cuenta_14'],
                    'periodo' => $l['periodo'], 'monto' => (float) $l['monto_distribuir'],
                ], $g['lineas']);
            }
            foreach ($asignBolsa as $cod => $porBolsa) {
                if (!$esDelDepto($cod)) continue;
                $tk = $this->tipoObra((string) $cod);
                if (!isset($tabla[$tk])) continue;
                foreach ($porBolsa as $bolsa => $monto) {
                    if (!isset($pool[$bolsa])) continue;
                    foreach ($this->svc->drenarBolsaGrande($pool[$bolsa], (float) $monto) as $p) {
                        $m = $p['monto'];
                        if ($m <= 0.005) continue;
                        $c14 = $p['cuenta_14'];
                        $h  = $mapa14[(string) $c14] ?? null;
                        $ck = $h->estructura ?? 'OTROS COSTO';
                        if (!isset($categorias[$ck])) $ck = 'OTROS COSTO';
                        $tabla[$tk]['cat'][$ck] += $m;
                        $tabla[$tk]['detalle'][$ck][] = [
                            'proyecto'  => (string) $cod,
                            'cuenta_14' => (string) $c14,
                            'cuenta_61' => (string) ($h->cuenta_61 ?? 'SIN HOMOLOGAR'),
                            'monto'     => $m,
                            'origen'    => 'bolsa',  // asignado desde una bolsa de área
                        ];
                    }
                }
            }
        }

        foreach ($tabla as $tk => &$t) {
            $t['costo_total'] = array_sum($t['cat']);
            $t['mc_pesos']    = $t['ingreso'] - $t['costo_total'];
            $t['mc_pct']      = $t['ingreso'] != 0 ? round($t['mc_pesos'] / $t['ingreso'] * 100, 1) : null;
        }
        unset($t);

        $todo = ['ingreso' => 0.0, 'cat' => [], 'costo_total' => 0.0];
        foreach ($categorias as $ck => $cl) $todo['cat'][$ck] = 0.0;
        foreach ($tabla as $t) {
            $todo['ingreso'] += $t['ingreso'];
            foreach ($t['cat'] as $ck => $v) $todo['cat'][$ck] += $v;
        }
        $todo['costo_total'] = array_sum($todo['cat']);
        $todo['mc_pesos']    = $todo['ingreso'] - $todo['costo_total'];
        $todo['mc_pct']      = $todo['ingreso'] != 0 ? round($todo['mc_pesos'] / $todo['ingreso'] * 100, 1) : null;

        $tabla = array_filter($tabla, fn($t) => $t['ingreso'] != 0 || $t['costo_total'] != 0);

        return [
            'tabla'      => $tabla,
            'todo'       => $todo,
            'tipos'      => $tipos,
            'categorias' => $categorias,
        ];
    }

    private function armarSnapshotResumen(Distribucion $distribucion): array
    {
        $mes  = (int) $distribucion->mes;
        $anio = (int) $distribucion->anio;
        $departamento = $distribucion->departamento ?: 'mantenimiento';

        $lineas = AplicacionCosto::where('distribucion_id', $distribucion->id)
            ->where('es_provision', false)
            ->selectRaw('codigo_proyecto, cuenta_14, SUM(monto_aplicar) as total')
            ->groupBy('codigo_proyecto', 'cuenta_14')
            ->get();

        $aplicar = [];
        foreach ($lineas as $l) {
            $aplicar[$l->codigo_proyecto][$l->cuenta_14] = (float) $l->total;
        }

        $datos = $this->construirResumen($mes, $anio, $departamento, $aplicar);
        $datos['mes']          = $mes;
        $datos['anio']         = $anio;
        $datos['departamento'] = $departamento;

        return $datos;
    }

    private function registrarVersion(Distribucion $distribucion, string $evento, Request $request): void
    {
        DistribucionVersion::create([
            'distribucion_id' => $distribucion->id,
            'evento'          => $evento,
            'user_id'         => $request->user()?->id,
            'user_nombre'     => $request->user()?->name,
            'snapshot'        => $this->armarSnapshotResumen($distribucion),
        ]);
    }

    public function trazabilidad(Distribucion $distribucion)
    {
        $versiones = $distribucion->versiones()->get();
        return view('operativo.distribucion-trazabilidad', [
            'distribucion' => $distribucion,
            'versiones'    => $versiones,
        ]);
    }

    public function verVersion(Request $request, DistribucionVersion $version)
    {
        $snap = $version->snapshot ?? [];
        $formato = $request->get('formato', 'html');

        $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        $periodo = ($nombresMes[$snap['mes'] ?? 0] ?? '').' '.($snap['anio'] ?? '');

        $datos = [
            'tabla'      => $snap['tabla'] ?? [],
            'todo'       => $snap['todo'] ?? ['ingreso'=>0,'cat'=>[],'costo_total'=>0,'mc_pesos'=>0,'mc_pct'=>null],
            'tipos'      => $snap['tipos'] ?? [],
            'categorias' => $snap['categorias'] ?? [],
            'mes'        => $snap['mes'] ?? 0,
            'anio'       => $snap['anio'] ?? '',
            'departamento' => $snap['departamento'] ?? '',
            'periodo'    => $periodo,
            'version'    => $version,
        ];

        if ($formato === 'excel') {
            $archivo = 'Resumen_'.str_replace(' ', '_', $periodo).'_v'.$version->id.'.xlsx';
            return Excel::download(new ResumenDistribucionExport(
                $datos['tabla'], $datos['todo'], $datos['tipos'], $datos['categorias'], $periodo
            ), $archivo);
        }

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('operativo.distribucion-resumen-pdf', $datos)->setPaper('a4', 'landscape');
            return $pdf->download('Resumen_'.str_replace(' ', '_', $periodo).'_v'.$version->id.'.pdf');
        }

        $datos['descargar'] = false;
        $datos['soloLectura'] = true;
        return view('operativo.distribucion-resumen', $datos);
    }

    private function nuevaObra($cod, $nombre, $cerradas, $estadosObra, $fichas,
                               $ingresoMes, $ingresoAcum, $costoAplMes, $costoAplAcum): array
    {
        if (isset($cerradas[$cod])) {
            $estado = 'cerrada';
        } else {
            $eo = $estadosObra[$cod] ?? 'abierta';
            $estado = $eo === 'cerrada_parcial' ? 'parcial'
                    : ($eo === 'cerrada_total' ? 'cerrada' : 'abierta');
        }

        $cat = [];
        foreach ($this->categorias as $k => $label) {
            $cat[$k] = ['pendiente' => 0, 'reversado' => 0, 'subs' => []];
        }

        return [
            // Nombre del proyecto de la ficha; si no hay, el que ya venía (razón social /
            // nombre_proyecto de los movimientos), para no dejarlo vacío.
            'codigo' => $cod, 'nombre' => ($fichas[$cod]->nombre_obra ?? null) ?: $nombre, 'estado' => $estado, 'cat' => $cat,
            'cliente' => $fichas[$cod]->cliente ?? null,
            'total_pendiente' => 0, 'total_reversado' => 0,
            'ingreso_mes'    => (float) ($ingresoMes[$cod] ?? 0),
            'ingreso_acum'   => (float) ($ingresoAcum[$cod] ?? 0),
            'costo_apl_mes'  => abs((float) ($costoAplMes[$cod] ?? 0)),
            'costo_apl_acum' => abs((float) ($costoAplAcum[$cod] ?? 0)),
            'ofertado'       => $this->normalizarMargen($fichas[$cod]->margen_ofertado ?? null),
            'valor_oferta'   => (float) ($fichas[$cod]->valor_contratado ?? 0),
            'costo_presup'   => (float) ($fichas[$cod]->costo_estimado ?? 0),
            'inventario_obra'    => 0,
            'inventario_almacen' => 0,
        ];
    }

    // ── Cálculos delegados al DistribucionService (lógica extraída del controlador) ──

    private function calcularMargenes(array &$o): void
    {
        $this->svc->calcularMargenes($o);
    }

    private function sumaMes(string $cm, int $anio, int $mes)
    {
        return $this->svc->sumaMes($cm, $anio, $mes);
    }

    private function sumaAcum(string $cm, int $anio, int $mes)
    {
        return $this->svc->sumaAcum($cm, $anio, $mes);
    }

    private function repartoFifo(array $lineas, float $tope): array
    {
        return $this->svc->repartoFifo($lineas, $tope);
    }

    private function normalizarMargen($v): ?float
    {
        return $this->svc->normalizarMargen($v);
    }

    private function tipoObra(string $cod): string
    {
        return $this->svc->tipoObra($cod);
    }
}