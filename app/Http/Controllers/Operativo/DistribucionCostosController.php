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
use App\Models\User;
use Illuminate\Http\Request;
use App\Exports\ResumenDistribucionExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\DistribucionVersion;
use Barryvdh\DomPDF\Facade\Pdf;

class DistribucionCostosController extends Controller
{
    private array $categorias = [
        'EQU-MAT-SUM' => 'Equipos y materiales',
        'MOI'         => 'M.O. interna',
        'MOE'         => 'M.O. externa',
        'OTROS COSTO' => 'Otros costos',
        'MOFIJAOPER'  => 'M.O. fija (supervisores)',
    ];

    public function consultas(Request $request)
    {
        $dists = Distribucion::orderByDesc('created_at')->get();

        $totales = AplicacionCosto::selectRaw('distribucion_id,
                SUM(CASE WHEN es_provision = 0 THEN monto_aplicar ELSE 0 END) as aplicado,
                SUM(CASE WHEN es_provision = 1 THEN monto_aplicar ELSE 0 END) as provision,
                COUNT(DISTINCT codigo_proyecto) as obras')
            ->groupBy('distribucion_id')->get()->keyBy('distribucion_id');

        $usuarios = User::pluck('name', 'id');

        $filas = $dists->map(function ($d) use ($totales, $usuarios) {
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
            $mes  = (int) $request->get('mes', date('n'));
            $anio = (int) $request->get('anio', date('Y'));
        }
        $tipo         = $request->get('tipo', 'todos');
        $estadoFiltro = $request->get('estado', 'todos');
        $vista        = $request->get('vista', 'todo');

        $homol = Homologacion::get(['cuenta_14', 'cuenta_61', 'nombre', 'estructura'])
            ->keyBy(fn($h) => (string) $h->cuenta_14);

        $saldos14Query = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, nombre_proyecto, cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'nombre_proyecto', 'cuenta_contable')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5');

        if ($vista === 'mes') {
            $saldos14Query->where('anio', $anio)->where('mes', $mes);
        }

        $saldos14 = $saldos14Query->get();

        $ingresoMes   = $this->sumaMes('Ingreso', $anio, $mes);
        $ingresoAcum  = $this->sumaAcum('Ingreso', $anio, $mes);
        $costoAplMes  = $this->sumaMes('Costos aplicados', $anio, $mes);
        $costoAplAcum = $this->sumaAcum('Costos aplicados', $anio, $mes);

        // Inventario en obra = saldo TOTAL de cuenta 14 (todos los períodos), para la proyección.
        $inventario14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto')
            ->pluck('saldo', 'codigo_proyecto');

        // Datos comerciales de la ficha: valor de oferta y costo presupuestado.
        $fichas = FichaProyecto::get(['codigo_proyecto', 'margen_ofertado', 'valor_contratado', 'costo_estimado'])
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

        // Líneas guardadas DE ESTE borrador (si estoy editando uno)
        $guardado = $distribucion
            ? AplicacionCosto::where('distribucion_id', $distribucion->id)->get()->groupBy('codigo_proyecto')
            : collect();

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

            foreach ($o['cat'] as $k => &$c) {
                foreach ($c['subs'] as &$sub) {
                    if (isset($savedAplicar[$sub['cuenta_14']])) {
                        $sub['aplicar'] = (float) $savedAplicar[$sub['cuenta_14']]->monto_aplicar;
                    } elseif ($distribucion) {
                        $sub['aplicar'] = 0;
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

        $obras = array_filter($obras, function ($o) use ($tipo, $estadoFiltro, $prefijosDepto) {
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

        $catalogo = Homologacion::orderBy('cuenta_14')->get(['cuenta_14', 'cuenta_61', 'nombre', 'estructura']);

        $bloqueado = $distribucion && $distribucion->estado === 'enviado' && !$distribucion->edicion_habilitada;

        return view('operativo.distribucion', [
            'obras'        => $obras,
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
            'kpiPendiente' => array_sum(array_column($obras, 'total_pendiente')),
            'kpiObras'     => count($obras),
            'kpiAlertas'   => count(array_filter($obras, fn($o) => $o['semaforo'] === 'rojo')),
        ]);
    }

    public function guardar(Request $request)
    {
        $accion = $request->input('accion', 'guardar');
        $distId = $request->input('dist');
        $mes    = (int) $request->mes;
        $anio   = (int) $request->anio;

        $aplicar    = $request->aplicar ?? [];
        $provision  = $request->provision ?? [];
        $estadoObra = $request->estado_obra ?? [];

        $distribucion = $distId ? Distribucion::find($distId) : null;

        if ($distribucion && $distribucion->estado === 'enviado' && !$distribucion->edicion_habilitada) {
            return back()->with('error', 'Este borrador ya fue enviado a contabilidad. Pídele a contabilidad que habilite la edición.');
        }

        // Determinar el departamento del plano:
        // - supervisor: su propio departamento
        // - director/admin: el que venga del formulario (campo 'departamento')
        $usuario = $request->user();
        $departamento = $usuario?->departamentoUnico() ?: $request->input('departamento');

        if (!$distribucion) {
            if (!in_array($departamento, ['mantenimiento', 'instalaciones'])) {
                return back()->with('error', 'Debes indicar el departamento del plano (mantenimiento o instalaciones).')->withInput();
            }
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

        $noCerradas = [];
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

        $homol = Homologacion::get(['cuenta_14', 'cuenta_61', 'nombre', 'estructura'])
            ->keyBy(fn($h) => (string) $h->cuenta_14);

        AplicacionCosto::where('distribucion_id', $distribucion->id)->delete();

        foreach ($aplicar as $cod => $cuentas) {
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
            $distribucion->edicion_habilitada = false;
            $distribucion->enviado_at = now();
            $distribucion->enviado_por = $request->user()?->id;
            $msg = 'Borrador enviado a contabilidad. Queda en solo lectura.';
        } else {
            $msg = 'Borrador guardado.';
        }
        $distribucion->save();

        // Registrar la versión en la bitácora (foto congelada de este momento)
        $evento = $accion === 'enviar' ? 'enviado' : 'guardado';
        $this->registrarVersion($distribucion, $evento, $request);

        if (!empty($noCerradas)) {
            $msg .= ' Nota: ' . implode(', ', $noCerradas) . ' no se pudieron cerrar (saldo abierto en cuenta 14); quedaron en parcial.';
        }

        return redirect()->route('operativo.distribucion', ['dist' => $distribucion->id])->with('success', $msg);
    }

    public function eliminar(Distribucion $distribucion)
    {
        if ($distribucion->estado === 'enviado') {
            return back()->with('error', 'No puedes eliminar un borrador ya enviado a contabilidad.');
        }
        AplicacionCosto::where('distribucion_id', $distribucion->id)->delete();
        $distribucion->delete();
        return back()->with('success', 'Borrador eliminado.');
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

        $datos = $this->construirResumen($mes, $anio, $departamento, $request->input('aplicar', []));

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
        ]));
    }

    // Lógica compartida: arma la tabla del resumen para un departamento concreto.
    // Lógica compartida: arma la tabla del resumen para un departamento concreto.
    private function construirResumen(int $mes, int $anio, string $departamento, array $aplicar = []): array
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

        // Mapa 14 -> estructura y 14 -> cuenta 61 (para mostrar a qué 61 va)
        $homolAll = Homologacion::get(['cuenta_14', 'cuenta_61', 'estructura']);
        $mapa14    = $homolAll->keyBy(fn($h) => (string) $h->cuenta_14);

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
            'codigo' => $cod, 'nombre' => $nombre, 'estado' => $estado, 'cat' => $cat,
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

    private function calcularMargenes(array &$o): void
    {
        $ingMes  = $o['ingreso_mes'];
        $ingAcum = $o['ingreso_acum'];

        // (Se mantienen para el semáforo y el orden, aunque ya no se muestran)
        $o['margen_mes'] = $ingMes != 0
            ? round(($ingMes - $o['costo_apl_mes']) / $ingMes * 100, 1) : null;
        $o['margen_acum'] = $ingAcum != 0
            ? round(($ingAcum - $o['costo_apl_acum']) / $ingAcum * 100, 1) : null;
        $o['margen_proy'] = $ingAcum != 0
            ? round(($ingAcum - ($o['costo_apl_acum'] + $o['sum_aplicar'] + $o['sum_prov'])) / $ingAcum * 100, 1) : null;

        // === Estado de avance de obra (acumulado al mes ANTERIOR; mismo corte ingreso y costo) ===
        $o['fact_acum_rec']     = $ingAcum - $ingMes;
        $o['costo_acum_rec']    = $o['costo_apl_acum'] - $o['costo_apl_mes'];
        $o['margen_acum_pesos'] = $o['fact_acum_rec'] - $o['costo_acum_rec'];
        $o['mc_pct_acum']       = $o['fact_acum_rec'] != 0
            ? round((1 - $o['costo_acum_rec'] / $o['fact_acum_rec']) * 100, 1) : null;

        // === Rentabilidad del mes (el JS recalcula MC al aplicar 14→6) ===
        $o['costo_mes_c6'] = $o['costo_apl_mes'];                 // costo del mes ya en cuenta 6
        $aplicadoIni       = $o['sum_aplicar'] + $o['sum_prov'];  // lo que se está moviendo 14→6
        $o['aplicado_mes'] = $aplicadoIni;
        $costoMesTotal     = $o['costo_apl_mes'] + $aplicadoIni;
        $o['mc_mes_pesos'] = $ingMes - $costoMesTotal;
        $o['mc_mes_pct']   = $ingMes != 0 ? round($o['mc_mes_pesos'] / $ingMes * 100, 1) : null;

        // === Proyección de rentabilidad (oferta comercial vs realidad) ===
        $valorOferta  = $o['valor_oferta'];
        $costoPresup  = $o['costo_presup'];
        $factTotal    = $ingAcum;             // facturado total incl. mes
        $costoAcumTot = $o['costo_apl_acum']; // costo cuenta 6 acumulado incl. mes
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

    private function sumaMes(string $cm, int $anio, int $mes)
    {
        return RegistroFinanciero::where('cuenta_mayor', $cm)
            ->where('anio', $anio)->where('mes', $mes)
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')->pluck('total', 'codigo_proyecto');
    }

    private function sumaAcum(string $cm, int $anio, int $mes)
    {
        return RegistroFinanciero::where('cuenta_mayor', $cm)
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

    private function tipoObra(string $cod): string
    {
        $c = strtoupper($cod);
        // Mantenimiento
        if (str_starts_with($c, 'GM')) return 'garantia';
        if (str_starts_with($c, 'MO')) return 'obras';
        if (str_starts_with($c, 'R'))  return 'reparacion';
        if (str_starts_with($c, 'C'))  return 'contrato';
        // Instalaciones
        if (str_starts_with($c, 'GI')) return 'garantia';
        if (str_starts_with($c, 'O'))  return 'obras';   // O → todas Obras
        return 'otro';
    }
}