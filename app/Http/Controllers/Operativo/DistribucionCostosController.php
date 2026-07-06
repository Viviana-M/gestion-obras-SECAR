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
use App\Models\Distribucion;
use App\Models\User;
use Illuminate\Http\Request;

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

        $homol = Homologacion::get(['cuenta_14', 'cuenta_61', 'nombre', 'estructura'])
            ->keyBy(fn($h) => (string) $h->cuenta_14);

        $saldos14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, nombre_proyecto, cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'nombre_proyecto', 'cuenta_contable')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5')
            ->get();

        $ingresoMes   = $this->sumaMes('Ingreso', $anio, $mes);
        $ingresoAcum  = $this->sumaAcum('Ingreso', $anio, $mes);
        $costoAplMes  = $this->sumaMes('Costos aplicados', $anio, $mes);
        $costoAplAcum = $this->sumaAcum('Costos aplicados', $anio, $mes);

        $fichas   = FichaProyecto::get(['codigo_proyecto', 'margen_ofertado'])->keyBy('codigo_proyecto');
        $cerradas = ProyectoCerrado::pluck('codigo_proyecto')->flip();
        $estadosObra = ForecastOperativo::where('anio', $anio)
            ->selectRaw('codigo_proyecto, MAX(estado_obra) as estado_obra')
            ->groupBy('codigo_proyecto')
            ->pluck('estado_obra', 'codigo_proyecto');

        $estadoManual = ObraEstado::pluck('estado', 'codigo_proyecto');

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
                        // Editando un borrador existente: lo que no quedó guardado fue puesto en 0
                        $sub['aplicar'] = 0;
                    }
                    // Borrador nuevo: se mantiene el default = pendiente completo
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
            $this->calcularMargenes($o);
            $o['tipo']   = $this->tipoObra($cod);
            $o['metodo'] = $o['estado'] === 'abierta'
                ? 'Reclasificar OT áreas → OT operación' : 'Cuenta 14 → 61';
        }
        unset($o);

        $obras = array_filter($obras, function ($o) use ($tipo, $estadoFiltro) {
            if ($tipo !== 'todos' && $o['tipo'] !== $tipo) return false;
            if ($estadoFiltro !== 'todos' && $o['estado'] !== $estadoFiltro) return false;
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

        // No editar un borrador ya enviado salvo que contabilidad lo habilite
        if ($distribucion && $distribucion->estado === 'enviado' && !$distribucion->edicion_habilitada) {
            return back()->with('error', 'Este borrador ya fue enviado a contabilidad. Pídele a contabilidad que habilite la edición.');
        }

        // Crear borrador nuevo (con su número de versión) o reutilizar el que se está editando
        if (!$distribucion) {
            $version = (Distribucion::where('mes', $mes)->where('anio', $anio)->max('version') ?? 0) + 1;
            $distribucion = Distribucion::create([
                'mes' => $mes, 'anio' => $anio, 'version' => $version, 'estado' => 'borrador',
                'edicion_habilitada' => false, 'guardado_por' => $request->user()?->id,
            ]);
        } else {
            $distribucion->guardado_por = $request->user()?->id;
        }

        // Regla: no se puede cerrar una obra con saldo abierto en la cuenta 14
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

        // Guardar las líneas DE ESTE borrador
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
        ];
    }

    private function calcularMargenes(array &$o): void
    {
        $ingMes  = $o['ingreso_mes'];
        $ingAcum = $o['ingreso_acum'];

        $o['margen_mes'] = $ingMes != 0
            ? round(($ingMes - $o['costo_apl_mes']) / $ingMes * 100, 1) : null;
        $o['margen_acum'] = $ingAcum != 0
            ? round(($ingAcum - $o['costo_apl_acum']) / $ingAcum * 100, 1) : null;
        $o['margen_proy'] = $ingAcum != 0
            ? round(($ingAcum - ($o['costo_apl_acum'] + $o['sum_aplicar'] + $o['sum_prov'])) / $ingAcum * 100, 1) : null;

        $of = $o['ofertado'];
        $o['delta_acum'] = ($of !== null && $o['margen_acum'] !== null) ? round($o['margen_acum'] - $of, 1) : null;
        $o['delta_proy'] = ($of !== null && $o['margen_proy'] !== null) ? round($o['margen_proy'] - $of, 1) : null;

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
        if (str_starts_with($c, 'GM') || str_starts_with($c, 'GI')) return 'garantia';
        if (str_starts_with($c, 'MTO') || str_starts_with($c, 'MO')) return 'mantenimiento';
        if (str_starts_with($c, 'C')) return 'contrato';
        if (str_starts_with($c, 'R')) return 'reparacion';
        return 'otro';
    }
}