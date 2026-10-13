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

    /** Estructuras de cuenta 14 que son mano de obra (interna, externa, fija). */
    public const ESTRUCTURAS_MO = ['MOI', 'MOE', 'MOFIJAOPER'];

    /**
     * MANO DE OBRA DIRECTA a distribuir, por PERSONA (solo el maestro "Mano de obra directa",
     * TerceroManoObra). Reutiliza el MISMO cruce por cédula del plano de MO de apoyo
     * (RedistribucionMoEspecialService::costoPorPersona) para armar el costo completo del período de
     * cada persona: salario (líneas de la cuenta 14 de las bolsas cuyo tercero cruza por cédula o
     * nombre) + seguridad social (autoliquidación por cédula). Se conservan los "buckets" (UN,
     * cuenta 14, tercero del ERP) para generar el plano 14→61 preservando persona + obra destino.
     *
     * Solo se consideran los buckets de las UN indicadas ($codigos). Devuelve una entrada por
     * persona con costo > 0, indexada por cédula. Las demás personas/costos de la bolsa (que ya
     * traen su obra en el ERP) no entran aquí.
     *
     * @return array<string, array{cedula:string,doc:string,nombre:string,total:float,buckets:array<int,array{un:string,cuenta:string,monto:float,tipo:string,tercero:string}>}>
     */
    public function manoObraDirectaPorPersona(array $codigos, int $anio, int $mes): array
    {
        if (empty($codigos)) {
            return [];
        }
        $maestro = \App\Models\TerceroManoObra::where('activo', true)->get();
        if ($maestro->isEmpty()) {
            return [];
        }

        // Mismo cruce por cédula/nombre + seguridad social que el plano de MO de apoyo, pero con el
        // maestro de mano de obra DIRECTA. No se toca la lógica del servicio, solo se le pasa el maestro.
        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona($mes, $anio, $maestro);

        $out = [];
        foreach ($costo as $ced => $p) {
            $buckets = [];
            $total   = 0.0;
            foreach ($p['buckets'] as $b) {
                if (! in_array((string) $b['un'], $codigos, true)) continue; // solo las UN pedidas
                $monto = (float) $b['monto'];
                if ($monto <= 0.005) continue;
                $buckets[] = [
                    'un'     => (string) $b['un'],   'cuenta'  => (string) $b['cuenta'],
                    'monto'  => round($monto, 2),    'tipo'    => (string) ($b['tipo'] ?? ''),
                    'tercero'=> (string) ($b['tercero'] ?? $ced),
                ];
                $total += $monto;
            }
            if ($total <= 0.005 || empty($buckets)) continue;
            $out[(string) $ced] = [
                'cedula' => (string) $p['cedula'],
                'doc'    => (string) ($p['doc'] ?: $p['cedula']),
                'nombre' => (string) $p['nombre'],
                'total'  => round($total, 2),
                'buckets'=> $buckets,
            ];
        }
        return $out;
    }

    /**
     * Explota asignaciones (persona → obra → monto) en filas de mano_obra_asignacion, repartiendo el
     * monto proporcionalmente entre los buckets del costo de la persona (salario y seguridad social),
     * para preservar la cuenta 14 y el tercero del ERP de cada componente en el plano 14→61.
     *
     * @param  array  $personas  [cédula => {cedula,doc,nombre,total,buckets}] (de manoObraDirectaPorPersona)
     * @param  array  $mapa      [cédula => [obra => {monto, obs}]]
     * @return array<int, array<string,mixed>>
     */
    public function filasAsignacionMo(array $personas, array $mapa, int $mes, int $anio, string $origen, ?int $userId): array
    {
        $ahora = now();
        $filas = [];
        foreach ($mapa as $ced => $obras) {
            $p = $personas[(string) $ced] ?? null;
            if (! $p || $p['total'] <= 0.005 || empty($p['buckets'])) continue;
            $total = (float) $p['total'];

            foreach ($obras as $obra => $info) {
                $montoObra = round((float) $info['monto'], 2);
                if ($montoObra <= 0.005) continue;
                $obs = $info['obs'] ?? null;

                $acum = 0.0; $ult = count($p['buckets']) - 1;
                foreach ($p['buckets'] as $idx => $b) {
                    $monto = $idx === $ult ? round($montoObra - $acum, 2) : round($montoObra * ((float) $b['monto'] / $total), 2);
                    $acum += $monto;
                    if ($monto <= 0.005) continue;
                    $filas[] = [
                        'bolsa_un' => (string) $b['un'], 'cuenta_14' => (string) $b['cuenta'],
                        'persona' => (string) $p['cedula'], 'tercero' => (string) $b['tercero'],
                        'tercero_doc' => (string) $p['doc'], 'tercero_nombre' => (string) $p['nombre'],
                        'obra_destino' => (string) $obra, 'monto' => $monto,
                        'mes' => $mes, 'anio' => $anio, 'observacion' => $obs,
                        'origen' => $origen, 'user_id' => $userId,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                }
            }
        }
        return $filas;
    }

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
    public function bolsasGrandes(?string $departamento, int $periodo, int $anio, int $mes, string $vista = 'todo'): array
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

        $saldos   = $this->saldosBolsasPorCuenta($codigos, $periodo, $anio, $mes, $vista); // [un => líneas con su desglose por tercero]
        $moPers   = $this->manoObraDirectaPorPersona($codigos, $anio, $mes);       // [cédula => costo completo + buckets]
        // Montos "a distribuir" AGREGADOS por (UN, cuenta): aunque ahora se guardan por tercero, el
        // disponible de la bolsa y el plano siguen siendo por cuenta → se suman las líneas de tercero.
        $montosSum = []; $montoGuardado = []; $obsPorCuenta = [];
        foreach (BolsaMonto::where('mes', $mes)->where('anio', $anio)->whereIn('un_codigo', $codigos)->get() as $m) {
            if ($m->reverso_solicitado) continue; // las líneas de reverso no son "a distribuir"
            $k = $m->un_codigo.'|'.$m->cuenta_14;
            $montosSum[$k] = ($montosSum[$k] ?? 0.0) + (float) $m->monto_distribuir;
            $montoGuardado[$k] = true;
            $o = trim((string) $m->observaciones);
            if ($o !== '' && empty($obsPorCuenta[$k])) $obsPorCuenta[$k] = $o; // primera observación de la cuenta
        }

        // Mano de obra directa por PERSONA, repartida por departamento según la UN de sus buckets.
        $personasPorDepto = [];
        foreach ($moPers as $ced => $p) {
            foreach ($p['buckets'] as $b) {
                $d = $deptoDeUn[$b['un']] ?? 'otros';
                if (! isset($personasPorDepto[$d][$ced])) {
                    $personasPorDepto[$d][$ced] = [
                        'cedula' => $p['cedula'], 'doc' => $p['doc'], 'nombre' => $p['nombre'],
                        'total' => 0.0, 'buckets' => [],
                    ];
                }
                $personasPorDepto[$d][$ced]['total']    += (float) $b['monto'];
                $personasPorDepto[$d][$ced]['buckets'][] = $b;
            }
        }

        $porDepto = [];
        foreach ($saldos as $un => $lineas) {
            $depto = $deptoDeUn[$un] ?? 'otros';
            foreach ($lineas as $l) {
                $saldo = (float) $l['pendiente'];           // neto de la (U.N., cuenta), ambos signos
                $key   = $un.'|'.$l['cuenta_14'];
                // Una (U.N., cuenta) con neto NEGATIVO es "reversado de más": no es distribuible, se
                // reporta aparte (solo lectura) y NO se resta del disponible.
                $reversado = $saldo < -0.005;
                $aDist = $reversado
                    ? 0.0
                    // Sin registro: se distribuye el saldo completo. Con registro(s): la suma por tercero (tope = saldo).
                    : (isset($montoGuardado[$key]) ? max(0.0, min($saldo, (float) ($montosSum[$key] ?? 0))) : $saldo);
                $esMo  = in_array((string) $l['estructura'], self::ESTRUCTURAS_MO, true);
                $terceros = $l['terceros'] ?? [];
                $porDepto[$depto][] = [
                    'un_codigo'        => (string) $un,
                    'un_nombre'        => (string) ($nombreUn[$un] ?? ''),
                    'cuenta_14'        => $l['cuenta_14'],
                    'cuenta_61'        => $l['cuenta_61'],
                    'nombre'           => $l['nombre'],
                    // Etiqueta representativa (mayor saldo) + el desglose completo por tercero.
                    'tercero'          => (string) ($terceros[0]['tercero'] ?? ''),
                    'terceros'         => $terceros,
                    'estructura'       => $l['estructura'],
                    'es_mo'            => $esMo,
                    'periodo'          => $l['periodo'],
                    'saldo'            => round($saldo, 2),
                    'reversado'        => $reversado,       // neto negativo: por revisar, no distribuible
                    'monto_distribuir' => round($aDist, 2),
                    'pendiente'        => round($aDist, 2), // tope consumible = lo a distribuir
                    'observaciones'    => $obsPorCuenta[$key] ?? null, // obs por tercero, agregada por cuenta
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
            // "Disponible por distribuir" = suma de las (U.N., cuenta) con neto POSITIVO (lo realmente
            // distribuible); coincide con el total del detalle. Las de neto negativo se reportan aparte
            // como "reversado de más / por revisar" y NO se restan del disponible.
            $total          = round(array_sum(array_map(fn ($l) => $l['reversado'] ? 0.0 : $l['saldo'], $lineas)), 2);
            $totalReversado = round(array_sum(array_map(fn ($l) => $l['reversado'] ? -$l['saldo'] : 0.0, $lineas)), 2);
            // Saldo NETO de la bolsa = positivos − negativos: es el que reconcilia con contabilidad.
            // Invariante: neto = disponible (positivos) − reversado de más (negativos).
            $neto           = round($total - $totalReversado, 2);

            // DOS POOLS DISTINTOS (no sumados), para no inflar "otros costos" con la mano de obra:
            //  · "Mano de obra del personal" = costo completo (sueldos + parafiscales vía PILA) de las
            //    personas del maestro Mano de obra directa; se distribuye por persona a los proyectos.
            //  · "Otros costos" = la bolsa MENOS esa mano de obra (materiales, servicios…).
            // La MO de apoyo (otro maestro) NO entra aquí: se reclasifica por su propio plano 14→61.
            $personas = array_values($personasPorDepto[$d] ?? []);
            foreach ($personas as &$per) $per['total'] = round($per['total'], 2);
            unset($per);
            usort($personas, fn ($a, $b) => $b['total'] <=> $a['total']);
            $mo          = round(min($total, array_sum(array_column($personas, 'total'))), 2);
            $otrosCostos = round($total - $mo, 2);                      // disponible bruto de otros costos
            $otrosNeto   = round($otrosCostos - $totalReversado, 2);    // otros costos neto = disponible − reversado

            $result[] = [
                'codigo'       => $d,
                'nombre'       => $nombre,
                'departamento' => $d,
                'total'        => $total,                    // distribuible = suma de netos positivos (cuadra con el detalle)
                'neto'         => $neto,                      // saldo de la bolsa (positivos − negativos): reconcilia con contabilidad
                'total_reversado' => $totalReversado,        // netos negativos (reversado de más / por revisar), solo lectura
                'otros_costos' => $otrosCostos,              // "Otros costos" disponible (bruto) = la bolsa menos la MO
                'otros_neto'   => $otrosNeto,                // "Otros costos" neto = disponible − reversado de más
                'mano_obra'    => $mo,                        // pool "Mano de obra del personal" (maestro directa)
                'total_mo'     => $mo,                        // alias retrocompat: MO directa a distribuir (costo por persona)
                'total_sinmo'  => $otrosCostos,              // alias retrocompat: otros costos (ya sin MO)
                'mo_personas'  => $personas,                 // una fila por persona del maestro (con buckets)
                'a_distribuir' => round(array_sum(array_column($lineas, 'monto_distribuir')), 2),
                'componentes'  => $this->componentesDe($lineas),
                'lineas'       => $lineas,
            ];
        }
        return $result;
    }

    /**
     * Tercero (razón social) REPRESENTATIVO por (UN, cuenta 14), del período acumulado. Sirve solo
     * como etiqueta en el panel de bolsas para cuentas NO laborales (materiales/otros), donde un
     * tercero representativo basta.
     *
     * OJO — mano de obra: NO uses este método para MO. Colapsa todos los terceros de una cuenta en
     * uno solo (MAX(razon_social)). La MO directa a distribuir se calcula por PERSONA (costo
     * completo del maestro Mano de obra directa) en manoObraDirectaPorPersona(), que es lo que abre
     * el grid de bolsas de la pantalla de Distribución.
     */
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
     * estructura), en un solo barrido. El costo por aplicar se guarda con estado_er
     * NEGATIVO (el importador pone signo -1 a las cuentas 1420). Se incluye CUALQUIER saldo
     * distinto de cero (ambos signos): `pendiente = -saldo`, de modo que el costo por
     * repartir queda POSITIVO y un saldo contrario (a favor) queda NEGATIVO con su signo
     * real. Así la suma de las líneas de una bolsa cuadra con su saldo real de cuenta 14.
     *
     * El saldo es el ACUMULADO AL MES FILTRADO (mismo corte que sumaAcum): se suman
     * los movimientos hasta (anio, mes), no todos los períodos. Así un movimiento de la
     * bolsa posterior al mes seleccionado no infla el disponible de ese mes.
     *
     * @return array<string, array<int, array>>  [codigo_bolsa => [ líneas ]]
     */
    public function saldosBolsasPorCuenta(array $codigos, int $periodo, int $anio, int $mes, string $vista = 'todo'): array
    {
        if (empty($codigos)) {
            return [];
        }
        $homol = Homologacion::mapaEn($periodo);

        // Ventana según la vista:
        //  - 'mes'  → SOLO el movimiento del mes/año elegido (neto del mes: SUM(débito − crédito)).
        //  - 'todo' → acumulado al mes (inventario en tránsito pendiente). Incluye biable + ajuste_plano.
        $ventana = $vista === 'mes'
            ? function ($q) use ($anio, $mes) {
                $q->where('anio', $anio)->where('mes', $mes);
            }
            : function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            };

        // Desglose por (UN, cuenta, TERCERO): una fila por cada tercero con su saldo. El saldo es
        // deb − cré = −SUM(estado_er) (costo por repartir positivo; saldo a favor negativo).
        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where('cuenta_contable', 'like', self::PREFIJO_BOLSA.'%') // solo 1420xx (otras 14xx son inventario en tránsito)
            ->whereIn('codigo_proyecto', $codigos)
            ->where($ventana)
            ->selectRaw('codigo_proyecto, cuenta_contable, tercero_dcto, razon_social, MAX(descripcion) as descripcion, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'razon_social')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5') // cualquier saldo distinto de cero (ambos signos)
            ->get();

        // Agrega por (UN, cuenta): el saldo de la cuenta es la SUMA de todos sus terceros, y se guarda
        // la lista de terceros para mostrar una fila por cada uno en el grid.
        $porCuenta = [];
        foreach ($filas as $f) {
            $pend = round(-1 * (float) $f->saldo, 2);
            if (abs($pend) <= 0.5) continue; // tercero sin saldo material
            $un = (string) $f->codigo_proyecto; $cuenta = (string) $f->cuenta_contable;
            $key = $un.'|'.$cuenta;
            if (! isset($porCuenta[$key])) {
                $porCuenta[$key] = ['un' => $un, 'cuenta' => $cuenta, 'descripcion' => (string) $f->descripcion, 'total' => 0.0, 'terceros' => []];
            }
            $doc = trim((string) $f->tercero_dcto);
            $nom = trim((string) $f->razon_social);
            $porCuenta[$key]['total'] += $pend;
            $porCuenta[$key]['terceros'][] = ['tercero' => ($nom ?: $doc ?: '—'), 'doc' => $doc, 'saldo' => $pend];
        }

        // SECAR cruza a nivel DEPARTAMENTO + cuenta: su reverso en la bolsa consume el crédito de SECAR
        // repartido en las obras del depto (por prefijo), además de sus propios positivos en la bolsa.
        // Así su reverso deja de reportarse como "reversado de más" cuando lo respaldan las obras, y el
        // neto (positivos − reversado) refleja el saldo real. Los demás terceros no se tocan.
        $deptoDeUn   = UnBolsa::whereIn('codigo', $codigos)->pluck('departamento', 'codigo');
        $creditoSecar = $this->creditoSecarEnObras(
            array_values(array_unique(array_filter($deptoDeUn->all()))), $anio, $mes, $vista);
        $secarPorDC = []; // "depto|cuenta" => [ [key, idxTercero], ... ]
        foreach ($porCuenta as $key => $c) {
            $dep = (string) ($deptoDeUn[$c['un']] ?? '');
            foreach ($c['terceros'] as $ti => $t) {
                if ($t['doc'] === self::NIT_SECAR) $secarPorDC[$dep.'|'.$c['cuenta']][] = [$key, $ti];
            }
        }
        foreach ($secarPorDC as $dc => $refs) {
            $celdas = [];
            foreach ($refs as $n => [$key, $ti]) {
                $celdas[$n] = ['saldo' => $porCuenta[$key]['terceros'][$ti]['saldo'], 'ord' => 0];
            }
            $res = $this->absorberReversoSecar($celdas, (float) ($creditoSecar[$dc] ?? 0.0));
            foreach ($refs as $n => [$key, $ti]) {
                $porCuenta[$key]['terceros'][$ti]['saldo'] = $res[$n]['saldo'];
            }
        }
        foreach ($porCuenta as $key => $c) { // recomputar el total de cada (U.N., cuenta) tras ajustar SECAR
            $porCuenta[$key]['total'] = round(array_sum(array_column($c['terceros'], 'saldo')), 2);
        }

        $out = [];
        foreach ($porCuenta as $c) {
            $h = $homol[$c['cuenta']] ?? null;
            $estructura = $h->estructura ?? 'OTROS COSTO';
            if (!isset(self::CATEGORIAS[$estructura])) {
                $estructura = 'OTROS COSTO';
            }
            $terceros = $c['terceros'];
            usort($terceros, fn ($a, $b) => $b['saldo'] <=> $a['saldo']);
            $out[$c['un']][] = [
                'cuenta_14'  => $c['cuenta'],
                'cuenta_61'  => (string) ($h->cuenta_61 ?? 'SIN HOMOLOGAR'),
                'nombre'     => $h->nombre ?? $c['descripcion'],
                'estructura' => $estructura,
                'periodo'    => 0,
                // Saldo de la cuenta = SUM(débito − crédito) de TODOS sus terceros.
                'pendiente'  => round($c['total'], 2),
                'terceros'   => $terceros, // una entrada por tercero con su saldo
            ];
        }

        // Antigüedad (período más viejo) de cada cuenta, para repartir FIFO al guardar.
        // Misma ventana que el saldo (acumulado, o solo el mes si la vista es 'mes').
        $per = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->where($ventana)
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

        // La MO del personal de apoyo YA NO se descuenta aquí: el total de la bolsa refleja el saldo
        // real de la cuenta 14 (SUM(débito − crédito)). Esa MO se reclasifica cuando Contabilidad
        // aplica su plano de apoyo (origen='ajuste_plano'), que acredita la 14 y baja el saldo real.

        return $out;
    }

    /** NIT de SECAR: su saldo (reverso) se cruza FIFO contra los terceros más antiguos. */
    public const NIT_SECAR = '890319324';

    /**
     * Prefijo de cuenta de la BOLSA DE OTROS COSTOS: solo 1420xx. Las demás 14xx (p. ej. 1455xx,
     * inventario/cruce como 14552599 "Materiales y repuestos AA" o 14552505) pertenecen al flujo de
     * inventario en tránsito, NO a la bolsa de otros costos, así que se excluyen del disponible, del
     * detalle por cuenta, del cruce (U.N., cuenta) y del plano que se genera de ahí. Siguen cargándose
     * en el importador (para inventario en tránsito); solo se excluyen del cálculo de la bolsa.
     */
    public const PREFIJO_BOLSA = '1420';

    /**
     * Detalle por DOCUMENTO de las bolsas, para la tabla plana de "Detalle por cuenta". Una fila por
     * (UN, cuenta, tercero, DOCUMENTO) con: fecha del movimiento (fecha_docto), nombre del proyecto,
     * auxiliar (cuenta) y su descripción, NIT, razón social, documento y el remanente de ESE documento
     * tras el CRUCE FIFO. Los parafiscales y proveedores cruzan por (U.N., cuenta); SECAR (890319324)
     * cruza a nivel DEPARTAMENTO + cuenta (sus positivos están repartidos en las obras), ver más abajo.
     *
     * IMPORTANTE: el cruce consume los documentos más ANTIGUOS primero (quedan en $0 y se ocultan); los
     * que sobreviven son los más RECIENTES y cada fila muestra la fecha/documento/saldo de ese documento
     * real, nunca la fecha del más antiguo con el saldo agregado. El saldo de la fila = el remanente del
     * documento después del cruce. También trae el "a distribuir"/observaciones guardados por documento
     * (default = saldo). NO cambia los totales por cuenta: el cruce solo reatribuye el mismo neto.
     *
     * @return array<string, array<int, array<string,mixed>>>  [un_codigo => líneas]
     */
    public function detalleBolsaTerceros(array $codigos, int $periodo, int $anio, int $mes, string $vista = 'todo'): array
    {
        if (empty($codigos)) {
            return [];
        }
        $homol = Homologacion::mapaEn($periodo);
        $ventana = $vista === 'mes'
            ? function ($q) use ($anio, $mes) { $q->where('anio', $anio)->where('mes', $mes); }
            : function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(fn ($q2) => $q2->where('anio', $anio)->where('mes', '<=', $mes));
            };

        // Grano por DOCUMENTO real: se agrupa también por tipo/numero de documento y fecha, para que cada
        // fila sea un movimiento concreto (y su remanente tras el cruce), no un agregado por tercero.
        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where('cuenta_contable', 'like', self::PREFIJO_BOLSA.'%') // solo 1420xx (otras 14xx son inventario en tránsito)
            ->whereIn('codigo_proyecto', $codigos)
            ->where($ventana)
            ->selectRaw('codigo_proyecto, cuenta_contable, tercero_dcto, razon_social, tipo_documento, documento, fecha_docto, '
                . 'MAX(nombre_proyecto) as nombre_proyecto, MAX(descripcion) as descripcion, '
                . 'MIN(anio*100+mes) as periodo, MIN(id) as min_id, SUM(estado_er) as saldo_er')
            ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'razon_social', 'tipo_documento', 'documento', 'fecha_docto')
            ->havingRaw('ABS(SUM(estado_er)) > 0.5')
            ->get();

        $nombreUn = UnBolsa::whereIn('codigo', $codigos)->pluck('nombre', 'codigo');

        // El personal de MANO DE OBRA no es "otros costos" y NO se distribuye desde este detalle:
        //  · DIRECTA (maestro TerceroManoObra): se reparte por persona en cada proyecto (sección MO).
        //  · APOYO (maestro ManoObraDirecta): contabilidad lo reclasifica 14→61 desde la PILA.
        // Se excluyen del detalle los terceros de ambos maestros.
        $terceroExcluido = \App\Models\TerceroManoObra::where('activo', true)->pluck('cedula')
            ->merge(\App\Models\ManoObraDirecta::where('activo', true)->pluck('cedula'))
            ->map(fn ($c) => trim((string) $c))->filter()->flip();

        // Agrupar por la llave del cruce (U.N., cuenta) = (codigo_proyecto, cuenta_contable). TODO el
        // cruce se hace dentro de esta llave: NUNCA se cruza entre distintas U.N. aunque compartan la
        // misma cuenta contable.
        $porCuenta = [];
        foreach ($filas as $f) {
            if (isset($terceroExcluido[trim((string) $f->tercero_dcto)])) continue; // MO directa/apoyo: fuera del detalle
            $un = (string) $f->codigo_proyecto; $cuenta = (string) $f->cuenta_contable;
            $key = $un.'|'.$cuenta;
            $fecha = $f->fecha_docto ? substr((string) $f->fecha_docto, 0, 10) : null;
            $ord = $fecha ? (int) str_replace('-', '', $fecha) : ((int) $f->periodo) * 100; // FIFO: lo más antiguo primero
            // Documento completo TIPO-NÚMERO con el número a 8 dígitos (ej. CCC-00002462). Si BIABLE ya
            // trae el documento completo en un solo campo (con prefijo de letras), se deja tal cual.
            $docCompleto = $this->documentoCompleto((string) $f->tipo_documento, (string) $f->documento);
            $porCuenta[$key][] = [
                'un'             => $un,
                'cuenta'         => $cuenta,
                'tercero_dcto'   => trim((string) $f->tercero_dcto),
                'razon_social'   => trim((string) $f->razon_social),
                'nombre_proyecto'=> (string) $f->nombre_proyecto,
                'descripcion'    => (string) $f->descripcion,
                'documento'      => $docCompleto,
                'fecha'          => $fecha,
                'ord'            => $ord,
                'min_id'         => (int) $f->min_id, // desempate estable del FIFO
                'saldo'          => round(-1 * (float) $f->saldo_er, 2), // Libro 1 = débito − crédito
            ];
        }

        // ───────────── CRUCE ─────────────
        // Los parafiscales y proveedores cruzan DENTRO de su (U.N., cuenta). SECAR es la excepción:
        // cruza a nivel DEPARTAMENTO + cuenta, porque sus reversos viven en la bolsa pero sus positivos
        // están repartidos en las obras reales. Por eso su reverso consume primero sus positivos en la
        // bolsa y luego el crédito de SECAR acumulado en las obras del departamento (por prefijo). NO
        // toca lo distribuible de la bolsa (otros costos + MO de otros terceros).
        $deptoDeUn    = UnBolsa::whereIn('codigo', $codigos)->pluck('departamento', 'codigo');
        $creditoSecar = $this->creditoSecarEnObras(
            array_values(array_unique(array_filter($deptoDeUn->all()))), $anio, $mes, $vista);

        $secarPorDC = []; // "depto|cuenta" => [ [key,i], ... ]  (líneas de SECAR de cualquier U.N.)
        foreach ($porCuenta as $key => &$grp) {
            $idxNoSecar = [];
            foreach ($grp as $i => $row) {
                if ($row['tercero_dcto'] === self::NIT_SECAR) {
                    $dep = (string) ($deptoDeUn[$row['un']] ?? '');
                    $secarPorDC[$dep.'|'.$row['cuenta']][] = [$key, $i];
                } else {
                    $idxNoSecar[] = $i;
                }
            }
            $this->cruzarFifo($grp, $idxNoSecar); // los demás terceros, dentro de la (U.N., cuenta)
        }
        unset($grp);

        // SECAR: cruce a nivel departamento + cuenta (consume sus positivos de bolsa y el crédito de obras).
        foreach ($secarPorDC as $dc => $refs) {
            $celdas = [];
            foreach ($refs as $n => [$key, $i]) {
                $celdas[$n] = ['saldo' => $porCuenta[$key][$i]['saldo'], 'ord' => (int) $porCuenta[$key][$i]['ord']];
            }
            $res = $this->absorberReversoSecar($celdas, (float) ($creditoSecar[$dc] ?? 0.0));
            foreach ($refs as $n => [$key, $i]) {
                $porCuenta[$key][$i]['saldo']  = $res[$n]['saldo'];
                $porCuenta[$key][$i]['alerta'] = $res[$n]['alerta'];
            }
        }

        $out = [];
        foreach ($porCuenta as $terceros) {
            $cuenta = $terceros[0]['cuenta'];
            $un     = $terceros[0]['un'];
            $h      = $homol[$cuenta] ?? null;
            $nombreCuenta = $h->nombre ?? $terceros[0]['descripcion'];

            // "A distribuir"/observaciones guardados por (UN, cuenta, tercero, DOCUMENTO). Default: el saldo.
            $guardados = BolsaMonto::where('mes', $mes)->where('anio', $anio)
                ->where('un_codigo', $un)->where('cuenta_14', $cuenta)->get()
                ->keyBy(fn ($m) => (string) $m->tercero.'∷'.(string) $m->documento);

            foreach ($terceros as $t) {
                $saldo  = round((float) $t['saldo'], 2);
                $alerta = (bool) ($t['alerta'] ?? false); // neto negativo: reversado de más (solo lectura)
                $g      = $guardados[$t['tercero_dcto'].'∷'.$t['documento']] ?? null;
                // La alerta no se distribuye; en lo demás, por defecto se distribuye el saldo completo.
                $aDist  = $alerta ? 0.0 : ($g ? max(0.0, min($saldo, (float) $g->monto_distribuir)) : $saldo);
                $out[$un][] = [
                    'un_codigo'        => $un,
                    'un_nombre'        => (string) ($nombreUn[$un] ?? ''),
                    'fecha'            => $t['fecha'],
                    'nombre_proyecto'  => $t['nombre_proyecto'],
                    'cuenta_14'        => $cuenta,
                    'nombre'           => (string) $nombreCuenta,
                    'tercero_dcto'     => $t['tercero_dcto'],
                    'razon_social'     => $t['razon_social'] ?: $t['tercero_dcto'],
                    'documento'        => $t['documento'],
                    'saldo'            => $saldo,
                    'monto_distribuir' => round($aDist, 2),
                    'observaciones'    => (string) ($g->observaciones ?? ''),
                    'cero'             => ! $alerta && round($saldo) == 0,
                    'alerta'           => $alerta,
                    // Solicitud de reverso a contabilidad (solo aplica a líneas de reversado de más).
                    'reverso_solicitado' => $alerta && (bool) ($g->reverso_solicitado ?? false),
                    'reverso_destino'    => $alerta ? (string) ($g->reverso_destino ?? '') : '',
                    'ord'              => $t['ord'],
                    'min_id'           => $t['min_id'],
                ];
            }
        }

        // Orden dentro de cada UN: por cuenta y, dentro de la cuenta, del más antiguo al más reciente.
        foreach ($out as &$lineas) {
            usort($lineas, fn ($a, $b) => [$a['cuenta_14'], $a['ord'], $a['min_id']] <=> [$b['cuenta_14'], $b['ord'], $b['min_id']]);
        }
        unset($lineas);

        return $out;
    }

    /**
     * Cruce FIFO dentro de una (U.N., cuenta) (modifica $terceros por referencia). El llamador ya agrupa
     * por esa llave, así que aquí nunca se mezclan terceros de distintas U.N.
     *
     * Los saldos negativos (de los terceros considerados) forman un "pool" que consume los saldos
     * positivos del más ANTIGUO al más reciente (por 'ord', desempate 'min_id'), dejándolos en $0 a
     * medida que los absorbe. Lo que queda en los positivos es el saldo real por tercero. El neto de la
     * cuenta NO cambia: solo se reatribuye y se presenta.
     *
     * Si los positivos no alcanzan a cubrir el pool, el sobrante se marca en UNA línea como ALERTA de
     * solo lectura ("reversado de más — revisar con contabilidad"), nunca como saldo a distribuir.
     *
     * $soloIdx limita el cruce a esos índices (los demás terceros quedan intactos). Se usa para apartar
     * a SECAR: los parafiscales y proveedores cruzan por (U.N., cuenta); SECAR cruza aparte, a nivel
     * departamento, en cruzarSecarDepartamento(). Con null, cruza todos los terceros del grupo.
     */
    private function cruzarFifo(array &$terceros, ?array $soloIdx = null): void
    {
        $indices = $soloIdx ?? array_keys($terceros);

        // 1) Pool con los negativos de los terceros considerados; se consumen (quedan en $0).
        $pool   = 0.0;
        $idxNeg = [];
        foreach ($indices as $i) {
            $terceros[$i]['alerta'] = false;
            if ($terceros[$i]['saldo'] < -0.005) {
                $pool += -$terceros[$i]['saldo'];
                $terceros[$i]['saldo'] = 0.0;
                $idxNeg[] = $i;
            }
        }
        if ($pool <= 0.5) return;

        // 2) Positivos del más antiguo al más reciente.
        $orden = [];
        foreach ($indices as $i) {
            if ($terceros[$i]['saldo'] > 0.005) $orden[] = $i;
        }
        usort($orden, fn ($a, $b) => [$terceros[$a]['ord'], $terceros[$a]['min_id']] <=> [$terceros[$b]['ord'], $terceros[$b]['min_id']]);

        // 3) El pool absorbe los positivos; el más antiguo primero, hasta agotarse.
        foreach ($orden as $i) {
            if ($pool <= 0.5) break;
            $consumir = min($terceros[$i]['saldo'], $pool);
            $terceros[$i]['saldo'] = round($terceros[$i]['saldo'] - $consumir, 2);
            $pool = round($pool - $consumir, 2);
        }

        // 5) Sobrante del pool (positivos insuficientes) = neto negativo → ALERTA de solo lectura.
        if ($pool > 0.5) {
            // Lo lleva la línea negativa MÁS RECIENTE (así la alerta queda al final del FIFO).
            $destino = null;
            foreach ($idxNeg as $i) {
                if ($destino === null
                    || [$terceros[$i]['ord'], $terceros[$i]['min_id']] > [$terceros[$destino]['ord'], $terceros[$destino]['min_id']]) {
                    $destino = $i;
                }
            }
            if ($destino !== null) {
                $terceros[$destino]['saldo']  = round(-$pool, 2);
                $terceros[$destino]['alerta'] = true;
            }
        }
    }

    /**
     * Crédito de SECAR (tercero 890319324) repartido en las OBRAS reales del departamento, por cuenta
     * 1420. Son los costos cargados a las obras a nombre de SECAR (saldo positivo): el reverso de SECAR
     * en la bolsa los consume a nivel departamento. Se delimitan las obras por PREFIJO de código
     * (C/R/MO/GM, GI/O), excluyendo las U.N. del maestro de bolsa.
     *
     * @return array<string,float>  ["departamento|cuenta" => saldo positivo de SECAR en las obras]
     */
    private function creditoSecarEnObras(array $departamentos, int $anio, int $mes, string $vista): array
    {
        // Prefijos de obra por departamento (p. ej. mantenimiento → C/R/MO/GM).
        $prefijos = [];
        foreach ($departamentos as $d) {
            foreach (\App\Models\User::prefijosDeDepartamento((string) $d) as $p) {
                $prefijos[] = $p;
            }
        }
        $prefijos = array_values(array_unique($prefijos));
        if (empty($prefijos)) return [];

        $bolsaCodigos = UnBolsa::pluck('codigo')->all();
        $ventana = $vista === 'mes'
            ? function ($q) use ($anio, $mes) { $q->where('anio', $anio)->where('mes', $mes); }
            : function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(fn ($q2) => $q2->where('anio', $anio)->where('mes', '<=', $mes));
            };

        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where('cuenta_contable', 'like', self::PREFIJO_BOLSA.'%')
            ->where('tercero_dcto', self::NIT_SECAR)
            ->where(function ($q) use ($prefijos) {
                foreach ($prefijos as $p) $q->orWhere('codigo_proyecto', 'like', $p.'%');
            })
            ->when(! empty($bolsaCodigos), fn ($q) => $q->whereNotIn('codigo_proyecto', $bolsaCodigos))
            ->where($ventana)
            ->selectRaw('codigo_proyecto, cuenta_contable, SUM(estado_er) as saldo_er')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->get();

        $credito = [];
        foreach ($filas as $f) {
            $saldo = round(-1 * (float) $f->saldo_er, 2); // débito − crédito; positivo = costo a nombre de SECAR
            if ($saldo <= 0.005) continue;                 // solo positivos: absorben el reverso de la bolsa
            $dep = \App\Models\User::departamentoDeCodigo((string) $f->codigo_proyecto);
            if ($dep === null) continue;
            $k = $dep.'|'.$f->cuenta_contable;
            $credito[$k] = round(($credito[$k] ?? 0.0) + $saldo, 2);
        }
        return $credito;
    }

    /**
     * Resuelve el reverso de SECAR de un conjunto de celdas (líneas de SECAR de una misma cuenta, de
     * cualquier U.N. del departamento). Los negativos (reverso) consumen: 1) los positivos de SECAR en
     * la bolsa, del más antiguo al más reciente; 2) el crédito de SECAR repartido en las obras del
     * departamento. El sobrante queda como neto negativo en la celda negativa MÁS RECIENTE, marcada
     * como alerta (reversado de más real). No altera las demás celdas ni el crédito de obras (que no se
     * muestra: solo absorbe).
     *
     * @param array<int,array{saldo:float,ord:int}> $celdas  indexado; 'ord' = antigüedad (AAAAMMDD)
     * @return array<int,array{saldo:float,alerta:bool}>      mismas claves que $celdas
     */
    private function absorberReversoSecar(array $celdas, float $credito): array
    {
        $res = [];
        foreach ($celdas as $i => $c) $res[$i] = ['saldo' => round((float) $c['saldo'], 2), 'alerta' => false];

        $pool = 0.0; $neg = []; $pos = [];
        foreach ($celdas as $i => $c) {
            $s = (float) $c['saldo'];
            if ($s < -0.005)      { $pool += -$s; $res[$i]['saldo'] = 0.0; $neg[] = $i; }
            elseif ($s > 0.005)   { $pos[] = $i; }
        }
        if ($pool <= 0.5) return $res;

        // 1) Positivos de SECAR en la bolsa, del más antiguo al más reciente.
        usort($pos, fn ($a, $b) => [(int) $celdas[$a]['ord'], $a] <=> [(int) $celdas[$b]['ord'], $b]);
        foreach ($pos as $i) {
            if ($pool <= 0.5) break;
            $use = min($res[$i]['saldo'], $pool);
            $res[$i]['saldo'] = round($res[$i]['saldo'] - $use, 2);
            $pool = round($pool - $use, 2);
        }
        // 2) Crédito de SECAR repartido en las obras del departamento (no se muestra; solo absorbe).
        if ($pool > 0.5 && $credito > 0.005) {
            $pool = round($pool - min($pool, $credito), 2);
        }
        // 3) Sobrante = reverso real (reversado de más): queda en la celda negativa más reciente.
        if ($pool > 0.5 && ! empty($neg)) {
            usort($neg, fn ($a, $b) => [(int) $celdas[$a]['ord'], $a] <=> [(int) $celdas[$b]['ord'], $b]);
            $dest = end($neg);
            $res[$dest]['saldo']  = round(-$pool, 2);
            $res[$dest]['alerta'] = true;
        }
        return $res;
    }

    /**
     * Documento completo para mostrar: TIPO-NÚMERO con el número a 8 dígitos (ej. CCC-00002462).
     * Si BIABLE ya trae el documento completo en un solo campo (número con prefijo de letras, p. ej.
     * "FSP-26352"), se deja tal cual. Sin tipo, solo el número; sin número, solo el tipo.
     */
    private function documentoCompleto(string $tipo, string $numero): string
    {
        $tipo = trim($tipo); $numero = trim($numero);
        if ($numero === '') return $tipo;
        if (preg_match('/[A-Za-z]/', $numero)) return $numero; // ya viene completo en un solo campo
        if ($tipo === '') return $numero;
        return $tipo.'-'.str_pad($numero, 8, '0', STR_PAD_LEFT);
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
        // La provisión NO reclasifica la 14: reconoce el costo directo en la 61 contra la
        // provisión de la 26 (61→26), pero igual cuenta como costo del mes y afecta el margen.
        $o['costo_mes_c6']      = $o['costo_apl_mes'];
        $o['aplicado_mes']      = $o['sum_aplicar'] + $sumBolsa;   // 14→6 real
        $o['costo_sin_aplicar'] = $o['sum_prov'];                   // provisiones (61→26)
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
        // Costo total proyectado = costo acumulado reconocido (cuenta 6) + lo que sigue pendiente en
        // la cuenta 14 (inventario en tránsito propio y de almacén) + los OTROS COSTOS distribuidos
        // al proyecto desde las bolsas de área (sum_bolsa, otra UN: no está en inventario_obra) + las
        // PROVISIONES incluidas (viven en el modelo Provision, tampoco están en la 14). sum_aplicar NO
        // se suma: reclasifica la 14 propia del proyecto y ya está contado en inventario_obra.
        $costoTotal   = $costoAcumTot + $o['inventario_obra'] + $o['inventario_almacen']
                      + $sumBolsa + (float) ($o['sum_prov'] ?? 0);

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
