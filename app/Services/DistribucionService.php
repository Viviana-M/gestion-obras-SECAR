<?php

namespace App\Services;

use App\Models\BolsaMonto;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;

/**
 * Lógica de cálculo de la Distribución de costos, extraída del controlador para
 * que este no siga engordando y para poder reutilizarla (obras y bolsas de área).
 *
 * No toca base de datos de escritura: solo consulta saldos y hace aritmética de
 * márgenes / reparto FIFO. La persistencia queda en el controlador.
 */
class DistribucionService
{
    /** Estructuras de costo (cuenta 14) que maneja la distribución. */
    public const CATEGORIAS = [
        'EQU-MAT-SUM' => 'Equipos y materiales',
        'MOI'         => 'M.O. interna',
        'MOE'         => 'M.O. externa',
        'OTROS COSTO' => 'Otros costos',
        'MOFIJAOPER'  => 'M.O. fija (supervisores)',
    ];

    /**
     * Componentes con que se resume una bolsa de área en el panel superior.
     * Se agrupan las estructuras de cuenta 14 en tres cubetas legibles:
     *  - MO directa: mano de obra interna y fija (nómina propia).
     *  - Terceros:   mano de obra externa (proveedores, la que se cruza con SIESA).
     *  - Otros costos: equipos/materiales y demás.
     */
    public const COMPONENTES = [
        'mo_directa' => ['label' => 'MO directa',   'estructuras' => ['MOI', 'MOFIJAOPER'], 'color' => '#1D9E75'],
        'terceros'   => ['label' => 'Terceros',     'estructuras' => ['MOE'],               'color' => '#7F77DD'],
        'otros'      => ['label' => 'Otros costos', 'estructuras' => ['EQU-MAT-SUM', 'OTROS COSTO'], 'color' => '#EF9F27'],
    ];

    // ───────────────────────── Bolsas de área ─────────────────────────

    /**
     * Bolsas activas de un departamento (o todas si no se especifica), cada una con
     * su saldo por cuenta 14, el total y el desglose por componente. Solo se
     * devuelven las bolsas que tienen algo por repartir (total > 0).
     *
     * @return array<int, array{codigo:string,nombre:string,departamento:string,total:float,componentes:array,lineas:array}>
     */
    public function bolsasDelDepartamento(?string $departamento, int $periodo, int $anio, int $mes): array
    {
        $query = UnBolsa::where('activo', true);
        if ($departamento) {
            $query->where('departamento', $departamento);
        }
        $bolsas = $query->orderBy('codigo')->get();
        if ($bolsas->isEmpty()) {
            return [];
        }

        $saldos = $this->saldosBolsasPorCuenta($bolsas->pluck('codigo')->all(), $periodo, $anio, $mes);

        $resultado = [];
        foreach ($bolsas as $b) {
            $lineas = $saldos[$b->codigo] ?? [];
            $total  = array_sum(array_column($lineas, 'pendiente'));
            if ($total <= 0.5) {
                continue; // sin nada por repartir: no ensucia el panel
            }
            $resultado[] = [
                'codigo'       => $b->codigo,
                'nombre'       => (string) $b->nombre,
                'departamento' => (string) $b->departamento,
                'total'        => round($total, 2),
                'componentes'  => $this->componentesDe($lineas),
                'lineas'       => $lineas,
            ];
        }
        return $resultado;
    }

    /** Nombre visible de cada bolsa grande (departamento). */
    public const DEPARTAMENTOS = ['mantenimiento' => 'Mantenimiento', 'instalaciones' => 'Instalaciones'];

    /**
     * Umbrales de rentabilidad (semáforo) del margen %, por departamento. El color se
     * decide de mejor a peor: verde ≥ verde, amarillo ≥ amarillo, rojo ≥ rojo, gris por
     * debajo. (Mantenimiento y reparaciones vs. Instalaciones tienen metas distintas.)
     */
    public const UMBRALES_MARGEN = [
        'mantenimiento' => ['verde' => 30, 'amarillo' => 27, 'rojo' => 25],
        'instalaciones' => ['verde' => 23, 'amarillo' => 20, 'rojo' => 18],
    ];

    /** Colores del semáforo: [fondo, texto]. El nivel más bajo se muestra en negro. */
    public const COLORES_SEMAFORO = [
        'verde'    => ['#16A34A', '#FFFFFF'],
        'amarillo' => ['#FDE047', '#854D0E'],
        'rojo'     => ['#DC2626', '#FFFFFF'],
        'gris'     => ['#111827', '#FFFFFF'],
    ];

    /** Fondo suave (tinte) del semáforo, para pintar tarjetas completas y dejar legible el texto. */
    public const FONDOS_SEMAFORO = [
        'verde'    => '#F0FDF4',
        'amarillo' => '#FEFCE8',
        'rojo'     => '#FEF2F2',
        'gris'     => '#F3F4F6',
    ];

    /** Nivel del semáforo (verde|amarillo|rojo|gris) para un margen % y un departamento. */
    public static function nivelMargen(?float $pct, ?string $depto): string
    {
        if ($pct === null || $depto === null || ! isset(self::UMBRALES_MARGEN[$depto])) {
            return 'gris';
        }
        $u = self::UMBRALES_MARGEN[$depto];
        if ($pct >= $u['verde'])    return 'verde';
        if ($pct >= $u['amarillo']) return 'amarillo';
        if ($pct >= $u['rojo'])     return 'rojo';
        return 'gris';
    }

    /**
     * DOS bolsas grandes (Mantenimiento, Instalaciones), cada una consolidando TODAS sus
     * UN. Cada línea del detalle es una cuenta 14 de una UN, con su tercero, saldo y el
     * "monto a distribuir" editable (sin registro = saldo completo por defecto). El
     * disponible de la bolsa (`a_distribuir`) = suma de los montos a distribuir (no el total).
     *
     * @return array<int, array{codigo:string,nombre:string,departamento:string,total:float,a_distribuir:float,componentes:array,lineas:array}>
     */
    public function bolsasGrandes(?string $departamento, int $periodo, int $anio, int $mes): array
    {
        $q = UnBolsa::where('activo', true);
        if ($departamento) {
            $q->where('departamento', $departamento);
        }
        $uns = $q->orderBy('codigo')->get();
        if ($uns->isEmpty()) {
            return [];
        }
        $codigos   = $uns->pluck('codigo')->all();
        $nombreUn  = $uns->pluck('nombre', 'codigo');
        $deptoDeUn = $uns->pluck('departamento', 'codigo');

        $saldos   = $this->saldosBolsasPorCuenta($codigos, $periodo, $anio, $mes); // [un => líneas]
        $terceros = $this->tercerosPorCuenta($codigos, $anio, $mes);               // [un|cuenta => tercero]
        $montos   = BolsaMonto::where('mes', $mes)->where('anio', $anio)
            ->whereIn('un_codigo', $codigos)->get()
            ->keyBy(fn ($m) => $m->un_codigo.'|'.$m->cuenta_14);

        $porDepto = [];
        foreach ($saldos as $un => $lineas) {
            $depto = $deptoDeUn[$un] ?? 'otros';
            foreach ($lineas as $l) {
                $saldo = (float) $l['pendiente'];
                $key   = $un.'|'.$l['cuenta_14'];
                $edit  = $montos[$key] ?? null;
                // Sin registro: se distribuye el saldo completo. Editado: lo que quede (tope = saldo).
                $aDist = $edit ? max(0.0, min($saldo, (float) $edit->monto_distribuir)) : $saldo;
                $porDepto[$depto][] = [
                    'un_codigo'        => (string) $un,
                    'un_nombre'        => (string) ($nombreUn[$un] ?? ''),
                    'cuenta_14'        => $l['cuenta_14'],
                    'cuenta_61'        => $l['cuenta_61'],
                    'nombre'           => $l['nombre'],
                    'tercero'          => $terceros[$key] ?? '',
                    'estructura'       => $l['estructura'],
                    'periodo'          => $l['periodo'],
                    'saldo'            => round($saldo, 2),
                    'monto_distribuir' => round($aDist, 2),
                    'pendiente'        => round($aDist, 2), // tope consumible = lo a distribuir
                    'observaciones'    => $edit->observaciones ?? null,
                ];
            }
        }

        $result = [];
        foreach (self::DEPARTAMENTOS as $d => $nombre) {
            if ($departamento && $departamento !== $d) {
                continue;
            }
            $lineas = $porDepto[$d] ?? [];
            if (empty($lineas)) {
                continue;
            }
            usort($lineas, fn ($a, $b) => [$a['un_codigo'], $a['cuenta_14']] <=> [$b['un_codigo'], $b['cuenta_14']]);
            $result[] = [
                'codigo'       => $d,
                'nombre'       => $nombre,
                'departamento' => $d,
                'total'        => round(array_sum(array_column($lineas, 'saldo')), 2),
                'a_distribuir' => round(array_sum(array_column($lineas, 'monto_distribuir')), 2),
                'componentes'  => $this->componentesDe($lineas),
                'lineas'       => $lineas,
            ];
        }
        return $result;
    }

    /** Tercero (razón social) representativo por (UN, cuenta 14), del período acumulado. */
    public function tercerosPorCuenta(array $codigos, int $anio, int $mes): array
    {
        if (empty($codigos)) {
            return [];
        }
        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)->orWhere(function ($q2) use ($anio, $mes) {
                    $q2->where('anio', $anio)->where('mes', '<=', $mes);
                });
            })
            ->selectRaw('codigo_proyecto, cuenta_contable, MAX(razon_social) as tercero')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->get();
        $out = [];
        foreach ($filas as $f) {
            $out[$f->codigo_proyecto.'|'.$f->cuenta_contable] = (string) ($f->tercero ?? '');
        }
        return $out;
    }

    /**
     * Drena $monto de una bolsa grande: pool de líneas (UN + cuenta) en FIFO por período
     * (la última parcial), MUTANDO el pool. Devuelve las porciones por (UN, cuenta) para
     * que el plano acredite la cuenta 14 de la UN real de origen.
     *
     * @param array $pool  [ ['un_codigo','cuenta_14','periodo','monto'], ... ]
     * @return array<int, array{un_codigo:string,cuenta_14:string,monto:float}>
     */
    public function drenarBolsaGrande(array &$pool, float $monto): array
    {
        usort($pool, fn ($a, $b) => $a['periodo'] <=> $b['periodo']);
        $restante = max(0.0, $monto);
        $out = [];
        foreach ($pool as $i => $l) {
            if ($restante <= 0.005) {
                break;
            }
            $disp = (float) $l['monto'];
            if ($disp <= 0.005) {
                continue;
            }
            $usar = min($disp, $restante);
            $out[] = ['un_codigo' => $l['un_codigo'], 'cuenta_14' => $l['cuenta_14'], 'monto' => round($usar, 2)];
            $pool[$i]['monto'] = $disp - $usar;
            $restante -= $usar;
        }
        return $out;
    }

    /**
     * Saldo de varias bolsas desglosado por cuenta 14 (con su cuenta 61 destino y
     * estructura), en un solo barrido. Igual que en los proyectos: el costo por aplicar
     * se guarda con estado_er NEGATIVO (el importador pone signo -1 a las cuentas 1420),
     * así que el "por repartir" es el lado NEGATIVO (pendiente = abs(saldo)); el positivo
     * sería un reversado de más y no cuenta como por repartir.
     *
     * El saldo es el ACUMULADO AL MES FILTRADO (mismo corte que sumaAcum): se suman
     * los movimientos hasta (anio, mes), no todos los períodos. Así un movimiento de la
     * bolsa posterior al mes seleccionado no infla el disponible de ese mes.
     *
     * @return array<string, array<int, array>>  [codigo_bolsa => [ líneas ]]
     */
    public function saldosBolsasPorCuenta(array $codigos, int $periodo, int $anio, int $mes): array
    {
        if (empty($codigos)) {
            return [];
        }
        $homol = Homologacion::mapaEn($periodo);

        // Corte "acumulado al mes": anio anterior, o mismo anio hasta el mes filtrado.
        $corteAcum = function ($q) use ($anio, $mes) {
            $q->where('anio', '<', $anio)
              ->orWhere(function ($q2) use ($anio, $mes) {
                  $q2->where('anio', $anio)->where('mes', '<=', $mes);
              });
        };

        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->where($corteAcum)
            ->selectRaw('codigo_proyecto, cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->havingRaw('SUM(estado_er) < -0.5') // lado negativo = costo por repartir
            ->get();

        $out = [];
        foreach ($filas as $f) {
            $saldo = round((float) $f->saldo, 2);
            if ($saldo >= -0.5) {
                continue; // solo el lado negativo (por repartir)
            }
            $h = $homol[(string) $f->cuenta_contable] ?? null;
            $estructura = $h->estructura ?? 'OTROS COSTO';
            if (!isset(self::CATEGORIAS[$estructura])) {
                $estructura = 'OTROS COSTO';
            }
            $out[$f->codigo_proyecto][] = [
                'cuenta_14'  => (string) $f->cuenta_contable,
                'cuenta_61'  => (string) ($h->cuenta_61 ?? 'SIN HOMOLOGAR'),
                'nombre'     => $h->nombre ?? $f->descripcion,
                'estructura' => $estructura,
                'periodo'    => 0,
                'pendiente'  => abs($saldo), // el saldo viene negativo
            ];
        }

        // Antigüedad (período más viejo) de cada cuenta, para repartir FIFO al guardar.
        // Mismo corte acumulado al mes filtrado.
        $per = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->where($corteAcum)
            ->selectRaw('codigo_proyecto, cuenta_contable, MIN(anio*100+mes) as periodo')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->get();
        $mapaPer = [];
        foreach ($per as $p) {
            $mapaPer[$p->codigo_proyecto.'|'.$p->cuenta_contable] = (int) $p->periodo;
        }
        foreach ($out as $cod => &$lineas) {
            foreach ($lineas as &$l) {
                $l['periodo'] = $mapaPer[$cod.'|'.$l['cuenta_14']] ?? 0;
            }
            unset($l);
        }
        unset($lineas);

        // Retiro de la MO del personal de apoyo administrativo y operativo: esas cuentas 14 las
        // gestiona Contabilidad (módulo MO Apoyo), así que se descuentan del "por repartir" de la
        // bolsa para que Operaciones no las distribuya. Si no hay personas registradas, no cambia nada.
        $retiro = app(RedistribucionMoEspecialService::class)->retiroPorUnCuenta($mes, $anio);
        if (! empty($retiro)) {
            foreach ($out as $cod => &$lineas) {
                foreach ($lineas as $i => &$l) {
                    $r = (float) ($retiro[$cod.'|'.$l['cuenta_14']] ?? 0);
                    if ($r <= 0.005) continue;
                    $l['pendiente'] = max(0.0, round($l['pendiente'] - $r, 2));
                    if ($l['pendiente'] <= 0.5) unset($lineas[$i]);
                }
                unset($l);
                $lineas = array_values($lineas);
            }
            unset($lineas);
            $out = array_filter($out, fn ($ls) => ! empty($ls));
        }

        return $out;
    }

    /** Desglose de un conjunto de líneas de bolsa en los tres componentes. */
    public function componentesDe(array $lineas): array
    {
        $comp = [];
        foreach (self::COMPONENTES as $key => $def) {
            $comp[$key] = ['label' => $def['label'], 'color' => $def['color'], 'monto' => 0.0];
        }
        foreach ($lineas as $l) {
            $est = $l['estructura'] ?? 'OTROS COSTO';
            $bucket = 'otros';
            foreach (self::COMPONENTES as $key => $def) {
                if (in_array($est, $def['estructuras'], true)) {
                    $bucket = $key;
                    break;
                }
            }
            $comp[$bucket]['monto'] += (float) $l['pendiente'];
        }
        foreach ($comp as &$c) {
            $c['monto'] = round($c['monto'], 2);
        }
        unset($c);
        return $comp;
    }

    // ───────────────────────── Reparto FIFO ─────────────────────────

    /**
     * Reparte un tope entre cuentas 14 dando prioridad a las MÁS ANTIGUAS (FIFO),
     * aplicando el SALDO COMPLETO de cada cuenta (nunca una fracción) y sin exceder
     * el saldo abierto. Una cuenta que no cabe entera en el tope restante se SALTA y
     * se sigue distribuyendo con las siguientes que sí caben.
     *
     * @param array $lineas  [ ['cuenta_14'=>string,'periodo'=>int,'monto'=>float], ... ]
     * @return array [cuenta_14 => monto_asignado]
     */
    public function repartoFifo(array $lineas, float $tope): array
    {
        usort($lineas, fn ($a, $b) => $a['periodo'] <=> $b['periodo']);
        $restante = max(0.0, $tope);
        $asignado = [];
        foreach ($lineas as $l) {
            if ($restante <= 0.5) {
                break;
            }
            $monto = (float) $l['monto'];
            if ($monto <= 0.5) {
                continue;
            }
            if ($monto > $restante + 0.5) {
                continue; // no cabe entera: se salta y se sigue distribuyendo
            }
            $asignado[$l['cuenta_14']] = ($asignado[$l['cuenta_14']] ?? 0) + $monto;
            $restante -= $monto;
        }
        return $asignado;
    }

    /**
     * Reparte un monto entre cuentas 14 en FIFO, permitiendo consumir la ÚLTIMA
     * cuenta de forma PARCIAL (para no perder pesos cuando el monto no calza justo
     * con ningún saldo). Se usa al bajar una asignación de bolsa a cuentas reales.
     *
     * @return array [cuenta_14 => monto_asignado]
     */
    public function repartoFifoParcial(array $lineas, float $monto): array
    {
        usort($lineas, fn ($a, $b) => $a['periodo'] <=> $b['periodo']);
        $restante = max(0.0, $monto);
        $asignado = [];
        foreach ($lineas as $l) {
            if ($restante <= 0.005) {
                break;
            }
            $disp = (float) $l['monto'];
            if ($disp <= 0.005) {
                continue;
            }
            $usar = min($disp, $restante);
            $asignado[$l['cuenta_14']] = ($asignado[$l['cuenta_14']] ?? 0) + $usar;
            $restante -= $usar;
        }
        return $asignado;
    }

    /**
     * Drena $monto de un pool de saldos por cuenta (FIFO, la última parcial),
     * MUTANDO el pool (se pasa por referencia) para consumir el saldo de forma
     * acumulada entre varias llamadas. Devuelve [cuenta_14 => monto_drenado].
     */
    public function drenarFifo(array &$pool, float $monto): array
    {
        usort($pool, fn ($a, $b) => $a['periodo'] <=> $b['periodo']);
        $restante = max(0.0, $monto);
        $out = [];
        foreach ($pool as $i => $l) {
            if ($restante <= 0.005) {
                break;
            }
            $disp = (float) $l['monto'];
            if ($disp <= 0.005) {
                continue;
            }
            $usar = min($disp, $restante);
            $out[$l['cuenta_14']] = ($out[$l['cuenta_14']] ?? 0) + $usar;
            $pool[$i]['monto'] = $disp - $usar;
            $restante -= $usar;
        }
        return $out;
    }

    // ───────────────────────── Sumas de saldos ─────────────────────────

    public function sumaMes(string $cuentaMayor, int $anio, int $mes, ?array $codigos = null)
    {
        $q = RegistroFinanciero::where('cuenta_mayor', $cuentaMayor)
            ->where('anio', $anio)->where('mes', $mes);
        if ($codigos !== null) {
            $q->whereIn('codigo_proyecto', $codigos);
        }
        return $q->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')->pluck('total', 'codigo_proyecto');
    }

    public function sumaAcum(string $cuentaMayor, int $anio, int $mes, ?array $codigos = null)
    {
        $q = RegistroFinanciero::where('cuenta_mayor', $cuentaMayor);
        if ($codigos !== null) {
            $q->whereIn('codigo_proyecto', $codigos);
        }
        return $q->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            })
            ->selectRaw('codigo_proyecto, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto')->pluck('total', 'codigo_proyecto');
    }

    // ───────────────────────── Márgenes y semáforo ─────────────────────────

    /**
     * Calcula todos los indicadores de margen y el semáforo de una obra. Recibe el
     * arreglo por referencia (mismo contrato que tenían los controladores).
     */
    public function calcularMargenes(array &$o): void
    {
        $ingMes  = $o['ingreso_mes'];
        $ingAcum = $o['ingreso_acum'];
        // Costo que se está moviendo 14→6 este mes: aplicaciones propias + provisiones
        // + lo asignado desde bolsas de área a esta obra.
        $sumBolsa = (float) ($o['sum_bolsa'] ?? 0);

        $o['margen_mes'] = $ingMes != 0
            ? round(($ingMes - $o['costo_apl_mes']) / $ingMes * 100, 1) : null;
        $o['margen_acum'] = $ingAcum != 0
            ? round(($ingAcum - $o['costo_apl_acum']) / $ingAcum * 100, 1) : null;
        $o['margen_proy'] = $ingAcum != 0
            ? round(($ingAcum - ($o['costo_apl_acum'] + $o['sum_aplicar'] + $o['sum_prov'] + $sumBolsa)) / $ingAcum * 100, 1) : null;

        // Estado de avance (acumulado al mes anterior)
        $o['fact_acum_rec']     = $ingAcum - $ingMes;
        $o['costo_acum_rec']    = $o['costo_apl_acum'] - $o['costo_apl_mes'];
        $o['margen_acum_pesos'] = $o['fact_acum_rec'] - $o['costo_acum_rec'];
        $o['mc_pct_acum']       = $o['fact_acum_rec'] != 0
            ? round((1 - $o['costo_acum_rec'] / $o['fact_acum_rec']) * 100, 1) : null;

        // Rentabilidad del mes (el JS recalcula MC al aplicar 14→6 y al asignar bolsas).
        // La provisión NO es un 14→6: es "costo sin aplicar" (14→26), pero igual cuenta
        // como costo del mes y afecta el margen.
        $o['costo_mes_c6']      = $o['costo_apl_mes'];
        $o['aplicado_mes']      = $o['sum_aplicar'] + $sumBolsa;   // 14→6 real
        $o['costo_sin_aplicar'] = $o['sum_prov'];                   // provisiones (14→26)
        $costoMesTotal          = $o['costo_apl_mes'] + $o['aplicado_mes'] + $o['costo_sin_aplicar'];
        $o['mc_mes_pesos']      = $ingMes - $costoMesTotal;
        $o['mc_mes_pct']        = $ingMes != 0 ? round($o['mc_mes_pesos'] / $ingMes * 100, 1) : null;

        // Acumulado de CIERRE: llega hasta el mes que se está distribuyendo INCLUYENDO esta
        // distribución (lo aplicado 14→6 y la provisión), para ver cómo cierra el acumulado.
        // El JS lo recalcula en vivo cuando cambian los montos a aplicar.
        $o['costo_acum_cierre']        = $o['costo_apl_acum'] + $o['aplicado_mes'] + $o['costo_sin_aplicar'];
        $o['margen_acum_cierre_pesos'] = $ingAcum - $o['costo_acum_cierre'];
        $o['mc_acum_cierre_pct']       = $ingAcum != 0
            ? round($o['margen_acum_cierre_pesos'] / $ingAcum * 100, 1) : null;

        // Proyección (oferta comercial vs realidad)
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

    public function normalizarMargen($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = (float) $v;
        if (abs($v) <= 1.5) {
            $v = $v * 100;
        }
        return round($v, 1);
    }

    public function tipoObra(string $cod): string
    {
        $c = strtoupper($cod);
        // Mantenimiento
        if (str_starts_with($c, 'GM')) return 'garantia';
        if (str_starts_with($c, 'MO')) return 'obras';
        if (str_starts_with($c, 'R'))  return 'reparacion';
        if (str_starts_with($c, 'C'))  return 'contrato';
        // Instalaciones
        if (str_starts_with($c, 'GI')) return 'garantia';
        if (str_starts_with($c, 'O'))  return 'obras';
        return 'otro';
    }
}
