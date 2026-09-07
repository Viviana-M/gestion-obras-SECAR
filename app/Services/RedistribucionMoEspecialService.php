<?php

namespace App\Services;

use App\Models\AutoliquidacionAporte;
use App\Models\Homologacion;
use App\Models\ManoObraDirecta;
use App\Models\ManoObraEspecial;
use App\Models\MontoDistribuirMoEspecial;
use App\Models\RedistribucionMoEspecial;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use Illuminate\Support\Facades\Schema;

/**
 * Grupo B: mano de obra de personal especial que se REDISTRIBUYE por porcentaje entre las
 * bolsas de área (no por proyecto). Calcula el costo total por persona (MO directa de las
 * bolsas por tercero + seguridad social cruzada de la autoliquidación por cédula), lo retira
 * de las bolsas y lo reparte por los porcentajes del período. Todo por período (mes/año).
 */
class RedistribucionMoEspecialService
{
    /**
     * Cuentas 14 que son mano de obra. Es una lista fija y taxativa: SOLO estas cuentas
     * cuentan como mano de obra del personal de apoyo administrativo y operativo.
     */
    public const CUENTAS_MO = [
        '14200506', '14200527', '14200545', '14200570', '14200572', '14200568',
        '14200569', '14200530', '14200533', '14200536', '14200539',
    ];

    /** Cuentas 14 que son mano de obra (lista fija). */
    public function cuentasMO(int $mes, int $anio): array
    {
        return self::CUENTAS_MO;
    }

    /**
     * Costo por persona del Grupo B en el período, con sus "fuentes" (buckets) por UN y cuenta:
     *   - directo: líneas de MO (cuenta 14) de las bolsas cuyo tercero = la cédula.
     *   - ss:      aportes (aporte_empresa) de la autoliquidación cruzados por cédula del empleado.
     *
     * @return array<string, array{cedula:string,nombre:string,directo:float,ss:float,total:float,buckets:array}>
     */
    public function costoPorPersona(int $mes, int $anio): array
    {
        $maestro = ManoObraDirecta::where('activo', true)->get();
        if ($maestro->isEmpty()) {
            return [];
        }
        $nombreMae = $maestro->pluck('nombre', 'cedula');

        // Índices para cruzar el tercero. Se cruza por CÉDULA (tercero_dcto/empleado); si el
        // financiero no trae la cédula (viene el NOMBRE en la razón social, como en producción),
        // se cae al cruce por NOMBRE normalizado (sin acentos ni orden de palabras, para que
        // "VALENCIA VILLABONA ARLEY" == "Arley Valencia Villabona").
        [$porCed, $porNom] = $this->indicesMaestro($maestro);
        if (empty($porCed) && empty($porNom)) {
            return [];
        }

        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);

        // 1) MO DIRECTA: líneas de bolsa (cuenta 14 MO) del período, agrupadas por tercero
        //    (documento + razón social) y atribuidas a la persona por cédula o nombre.
        $directo = collect();
        if (! empty($cuentasMO) && ! empty($unBolsa)) {
            $directo = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
                ->whereIn('codigo_proyecto', $unBolsa)
                ->whereIn('cuenta_contable', $cuentasMO)
                ->where($this->corteAcum($anio, $mes))
                ->selectRaw('codigo_proyecto as un, cuenta_contable as cuenta, tercero_dcto, razon_social, SUM(estado_er) as saldo')
                ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'razon_social')
                ->get();
        }

        // 2) SEGURIDAD SOCIAL (desde la cuenta 14): la SS también está en la cuenta 14, pero a
        //    nombre del FONDO/EPS (tercero = fondo, sin cédula). La autoliquidación (fondo +
        //    empleado + aporte) se usa SOLO como llave para repartir el monto de la cuenta 14 de
        //    cada fondo entre las personas, según su proporción de aporte_empresa en ese fondo.
        //    El valor sale de la cuenta 14, no del número de la autoliquidación.
        $fondos = $this->fondosAutoliquidacion($mes, $anio, $porCed, $porNom);

        $out = [];
        foreach ($nombreMae as $ced => $nom) {
            $out[$ced] = ['cedula' => (string) $ced, 'nombre' => $nom, 'doc' => '',
                'directo' => 0.0, 'ss' => 0.0, 'total' => 0.0, 'buckets' => []];
        }

        foreach ($directo as $r) {
            $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0; // el costo por repartir va negativo
            if ($m <= 0.005) continue;

            $terceroErp = trim((string) $r->tercero_dcto) ?: trim((string) $r->razon_social);

            // ¿MO directa (salario)? El tercero de la línea es la persona.
            $ced = $this->cruzar($r->tercero_dcto, $r->razon_social, $porCed, $porNom);
            if ($ced !== null && isset($out[$ced])) {
                $out[$ced]['directo'] += $m;
                $out[$ced]['total']   += $m;
                // Documento real de la persona (el que trae el ERP en su línea de salario).
                if ($out[$ced]['doc'] === '' && trim((string) $r->tercero_dcto) !== '') {
                    $out[$ced]['doc'] = trim((string) $r->tercero_dcto);
                }
                $out[$ced]['buckets'][] = ['un' => (string) $r->un, 'cuenta' => (string) $r->cuenta, 'monto' => $m, 'tipo' => 'directo', 'tercero' => $terceroErp];
                continue;
            }

            // ¿Seguridad social? El tercero de la línea es un fondo/EPS de la autoliquidación:
            // se reparte su cuenta 14 entre las personas según su proporción de aporte del fondo.
            $fk = $fondos['byNit'][$this->normCedula($r->tercero_dcto)]
                ?? $fondos['byNom'][$this->normNombre($r->razon_social)] ?? null;
            if ($fk === null) continue;
            $totalFondo = (float) ($fondos['total'][$fk] ?? 0);
            if ($totalFondo <= 0.005) continue;
            foreach (($fondos['persons'][$fk] ?? []) as $pced => $aporte) {
                if (! isset($out[$pced])) continue;
                $porcion = $m * ((float) $aporte / $totalFondo); // el valor sale de la cuenta 14
                if ($porcion <= 0.005) continue;
                $out[$pced]['ss']    += $porcion;
                $out[$pced]['total'] += $porcion;
                // El crédito conserva el tercero del ERP (el fondo/EPS de esta línea).
                $out[$pced]['buckets'][] = ['un' => (string) $r->un, 'cuenta' => (string) $r->cuenta, 'monto' => $porcion, 'tipo' => 'ss', 'tercero' => $terceroErp];
            }
        }

        return $out;
    }

    /**
     * Fondos de la autoliquidación del período (acumulado): total de aporte_empresa por fondo,
     * aporte por persona registrada dentro del fondo, e índices para cruzar el fondo con las
     * líneas de la cuenta 14 (por NIT o por nombre).
     *
     * @return array{total:array<string,float>,persons:array<string,array<string,float>>,byNit:array<string,string>,byNom:array<string,string>,nombre:array<string,string>}
     */
    private function fondosAutoliquidacion(int $mes, int $anio, array $porCed, array $porNom): array
    {
        $rows = AutoliquidacionAporte::where($this->corteAcum($anio, $mes))
            ->selectRaw('cedula as fondo_nit, razon_social as fondo_nom, empleado, empleado_nombre, SUM(aporte_empresa) as monto')
            ->groupBy('cedula', 'razon_social', 'empleado', 'empleado_nombre')
            ->havingRaw('SUM(aporte_empresa) > 0.005')
            ->get();

        $total = []; $persons = []; $byNit = []; $byNom = []; $nombre = [];
        foreach ($rows as $r) {
            $nit = $this->normCedula($r->fondo_nit);
            $nom = $this->normNombre($r->fondo_nom);
            $fk = $nit !== '' ? $nit : $nom; // clave canónica del fondo
            if ($fk === '') continue;
            $ap = (float) $r->monto;
            $total[$fk] = ($total[$fk] ?? 0) + $ap;
            $nombre[$fk] = $nombre[$fk] ?? (trim((string) $r->fondo_nom) ?: (string) $r->fondo_nit);
            if ($nit !== '') $byNit[$nit] = $fk;
            if ($nom !== '') $byNom[$nom] = $fk;
            $ced = $this->cruzar($r->empleado, $r->empleado_nombre, $porCed, $porNom);
            if ($ced !== null) {
                $persons[$fk][$ced] = ($persons[$fk][$ced] ?? 0) + $ap;
            }
        }
        return compact('total', 'persons', 'byNit', 'byNom', 'nombre');
    }

    /**
     * Validación: por fondo, compara la suma de la autoliquidación (aporte_empresa) contra el
     * monto de la cuenta 14 de ese fondo. Devuelve solo los que NO cuadran, para revisar.
     *
     * @return array<int, array{fondo:string,autoliq:float,cuenta14:float,diferencia:float}>
     */
    public function descuadresFondos(int $mes, int $anio): array
    {
        $maestro = ManoObraDirecta::where('activo', true)->get();
        [$porCed, $porNom] = $this->indicesMaestro($maestro);
        $fondos = $this->fondosAutoliquidacion($mes, $anio, $porCed, $porNom);
        if (empty($fondos['total'])) {
            return [];
        }

        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);
        $cuenta14 = [];
        if (! empty($cuentasMO) && ! empty($unBolsa)) {
            $rows = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
                ->whereIn('codigo_proyecto', $unBolsa)->whereIn('cuenta_contable', $cuentasMO)
                ->where($this->corteAcum($anio, $mes))
                ->selectRaw('tercero_dcto, razon_social, SUM(estado_er) as saldo')
                ->groupBy('tercero_dcto', 'razon_social')->get();
            foreach ($rows as $r) {
                $fk = $fondos['byNit'][$this->normCedula($r->tercero_dcto)]
                    ?? $fondos['byNom'][$this->normNombre($r->razon_social)] ?? null;
                if ($fk === null) continue;
                $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0;
                $cuenta14[$fk] = ($cuenta14[$fk] ?? 0) + $m;
            }
        }

        $out = [];
        foreach ($fondos['total'] as $fk => $ap) {
            $c14 = (float) ($cuenta14[$fk] ?? 0);
            $dif = round($c14 - $ap, 2);
            if (abs($dif) < 0.5) continue;
            $out[] = ['fondo' => $fondos['nombre'][$fk] ?? $fk, 'autoliq' => round($ap, 2), 'cuenta14' => round($c14, 2), 'diferencia' => $dif];
        }
        usort($out, fn ($a, $b) => abs($b['diferencia']) <=> abs($a['diferencia']));
        return $out;
    }

    /** Índices del maestro: [normCedula => cedula] y [normNombre => cedula] para cruzar terceros. */
    private function indicesMaestro($maestro): array
    {
        $porCed = [];
        $porNom = [];
        foreach ($maestro as $p) {
            $c = $this->normCedula($p->cedula);
            if ($c !== '') $porCed[$c] = (string) $p->cedula;
            $n = $this->normNombre($p->nombre);
            if ($n !== '') $porNom[$n] = (string) $p->cedula;
        }
        return [$porCed, $porNom];
    }

    /**
     * Cruza un tercero (documento + nombre) contra el maestro: primero por cédula y, si no
     * cruza, por nombre normalizado (sin acentos ni orden de palabras). En este financiero el
     * tercero suele venir solo con el NOMBRE en la razón social, por eso el nombre debe cruzar
     * aunque la línea traiga algún documento distinto (p. ej. un auxiliar de cuenta).
     */
    private function cruzar(?string $doc, ?string $nombre, array $porCed, array $porNom): ?string
    {
        $c = $this->normCedula($doc);
        if ($c !== '' && isset($porCed[$c])) return $porCed[$c];
        $n = $this->normNombre($nombre);
        if ($n !== '' && isset($porNom[$n])) return $porNom[$n];
        return null;
    }

    /**
     * Terceros con MO en bolsas (para elegirlos al registrar y evitar diferencias de digitación):
     * documento, nombre y monto total. Excluye los que ya están cruzando con el maestro.
     *
     * @return array<int, array{doc:string,nombre:string,monto:float}>
     */
    public function tercerosDisponibles(int $mes, int $anio): array
    {
        return $this->tercerosSinCruzar($mes, $anio);
    }

    /**
     * Corte ACUMULADO AL MES: todos los períodos anteriores + el mes filtrado. Mismo criterio
     * que el saldo de las bolsas. Así el saldo no distribuido de un mes queda disponible el mes
     * siguiente (y cuando se importa el plano a SIESA, la cuenta 14 baja y el pendiente se reduce).
     */
    private function corteAcum(int $anio, int $mes): \Closure
    {
        return function ($q) use ($anio, $mes) {
            $q->where('anio', '<', $anio)->orWhere(function ($q2) use ($anio, $mes) {
                $q2->where('anio', $anio)->where('mes', '<=', $mes);
            });
        };
    }

    /** Normaliza una cédula/NIT: solo alfanuméricos, en minúsculas (quita puntos, espacios y guiones). */
    private function normCedula(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $s)));
    }

    /** Normaliza un nombre: sin acentos, sin puntuación y con las palabras ordenadas (el orden no importa). */
    private function normNombre(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $toks = array_values(array_filter(explode(' ', $s), fn ($t) => $t !== ''));
        sort($toks);
        return implode(' ', $toks);
    }

    /** Porcentajes GUARDADOS del período (sin herencia): [cedula => [un_codigo => porcentaje]]. */
    public function porcentajesGuardados(int $mes, int $anio): array
    {
        $out = [];
        foreach (RedistribucionMoEspecial::where('mes', $mes)->where('anio', $anio)->get() as $r) {
            $out[$r->cedula][$r->un_codigo] = (float) $r->porcentaje;
        }
        return $out;
    }

    /**
     * Porcentajes EFECTIVOS del período: los guardados y, para las personas que aún no tienen
     * % en el período, los HEREDADOS del período anterior más reciente que sí los tenga. No se
     * persisten: quedan heredados hasta que Contabilidad los guarde. Una persona nueva (sin
     * historial) queda sin %.
     *
     * @return array{pct: array<string,array<string,float>>, heredados: array<string,bool>}
     */
    public function porcentajesEfectivos(int $mes, int $anio): array
    {
        $pct = $this->porcentajesGuardados($mes, $anio);
        $heredados = [];

        $maestro = ManoObraEspecial::where('activo', true)->pluck('cedula')->all();
        $faltan = array_values(array_diff($maestro, array_keys($pct)));
        if (! empty($faltan)) {
            $periodoActual = $anio * 100 + $mes;
            $prev = RedistribucionMoEspecial::whereIn('cedula', $faltan)
                ->whereRaw('(anio * 100 + mes) < ?', [$periodoActual])
                ->orderByRaw('(anio * 100 + mes) desc')
                ->get();
            $masReciente = []; // cedula => período heredado (el primero visto = el más reciente)
            foreach ($prev as $r) {
                $per = $r->anio * 100 + $r->mes;
                if (! isset($masReciente[$r->cedula])) $masReciente[$r->cedula] = $per;
                if ($per === $masReciente[$r->cedula]) {
                    $pct[$r->cedula][$r->un_codigo] = (float) $r->porcentaje;
                    $heredados[$r->cedula] = true;
                }
            }
        }

        return ['pct' => $pct, 'heredados' => $heredados];
    }

    /** Porcentajes efectivos (guardados o heredados del mes anterior): [cedula => [un_codigo => porcentaje]]. */
    public function porcentajes(int $mes, int $anio): array
    {
        return $this->porcentajesEfectivos($mes, $anio)['pct'];
    }

    /** Monto a distribuir por persona del período (lo que Contabilidad decidió repartir): [cedula => monto]. */
    public function montosDistribuir(int $mes, int $anio): array
    {
        // Salvaguarda: si la migración aún no se ha corrido, no revienta (se distribuye el total).
        if (! Schema::hasTable('monto_distribuir_mo_especial')) {
            return [];
        }
        $out = [];
        foreach (MontoDistribuirMoEspecial::where('mes', $mes)->where('anio', $anio)->get() as $r) {
            $out[$r->cedula] = (float) $r->monto_distribuir;
        }
        return $out;
    }

    /**
     * Monto efectivo a distribuir de una persona: lo registrado para el período, topado a su
     * total; si no hay registro, se distribuye el total completo (comportamiento por defecto).
     */
    private function montoEfectivo(float $total, string $cedula, array $montos): float
    {
        $m = array_key_exists($cedula, $montos) ? (float) $montos[$cedula] : $total;
        return max(0.0, min($m, $total));
    }

    /**
     * Resumen por bolsa (UN): MO cruda del período y cuánto de esa MO corresponde a las personas
     * registradas (que se reclasifica de la 14 a la 61 en esa MISMA UN). No hay redistribución
     * por %: la distribución por UN la trae el archivo del cierre.
     *
     * @return array{filas: array, total_crudo: float, total_reclasificado: float}
     */
    public function resumenBolsas(int $mes, int $anio): array
    {
        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);
        $nombresUn = UnBolsa::pluck('nombre', 'codigo');

        // MO cruda por UN (todas las líneas MO de las bolsas del período, acumulado al mes).
        $crudo = [];
        if (! empty($cuentasMO)) {
            $rows = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
                ->whereIn('codigo_proyecto', $unBolsa)
                ->whereIn('cuenta_contable', $cuentasMO)
                ->where($this->corteAcum($anio, $mes))
                ->selectRaw('codigo_proyecto as un, SUM(estado_er) as saldo')
                ->groupBy('codigo_proyecto')->get();
            foreach ($rows as $r) {
                $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0;
                if ($m > 0.005) $crudo[$r->un] = $m;
            }
        }

        // MO de las personas registradas por UN (lo que se reclasifica 14→61 en esa misma UN).
        $reclas = [];
        foreach ($this->costoPorPersona($mes, $anio) as $p) {
            foreach ($p['buckets'] as $b) {
                $reclas[$b['un']] = ($reclas[$b['un']] ?? 0) + (float) $b['monto'];
            }
        }

        $unes = array_unique(array_merge(array_keys($crudo), array_keys($reclas)));
        $filas = [];
        foreach ($unes as $un) {
            $c = (float) ($crudo[$un] ?? 0);
            $r = (float) ($reclas[$un] ?? 0);
            $filas[] = [
                'un' => (string) $un, 'nombre' => $nombresUn[$un] ?? '',
                'crudo' => $c, 'reclasificado' => $r, 'queda' => $c - $r,
            ];
        }
        usort($filas, fn ($a, $b) => $b['crudo'] <=> $a['crudo']);

        return [
            'filas'               => $filas,
            'total_crudo'         => array_sum(array_column($filas, 'crudo')),
            'total_reclasificado' => array_sum(array_column($filas, 'reclasificado')),
        ];
    }

    /**
     * Tuplas de movimiento para el plano contable de reclasificación 14→61, usando la distribución
     * por UN que YA trae el archivo del cierre (sin %). Por cada línea de MO de la persona:
     *   - CR la cuenta 14, conservando el tercero del ERP (la persona en el salario; el fondo/EPS
     *     en la seguridad social) y la UN de la línea.
     *   - DB la cuenta 61 correspondiente (homologada), a nombre de la PERSONA, en la misma UN.
     * Cada tupla es un asiento balanceado en su propia UN. Se reclasifica la MO completa.
     *
     * @return array<int, array{un:string,cuenta_credito:string,tercero_credito:string,cuenta_debito:string,tercero_debito:string,monto:float,cedula:string}>
     */
    public function movimientosRedistribucion(int $mes, int $anio): array
    {
        $costo = $this->costoPorPersona($mes, $anio);
        $homol = Homologacion::mapaEn(Homologacion::periodo($anio, $mes));

        $mov = [];
        foreach ($costo as $ced => $p) {
            if ($p['total'] <= 0.005) continue;
            // Documento de la persona para el débito a la 61: el del ERP (de su salario); si no hay,
            // la cédula del maestro cuando es real (no una clave interna 'SD-...'); si no, el nombre.
            $terceroPersona = $p['doc'] !== ''
                ? $p['doc']
                : (str_starts_with((string) $ced, 'SD-') ? ((string) $p['nombre'] ?: (string) $ced) : (string) $ced);
            foreach ($p['buckets'] as $b) {
                $cuenta14 = $b['cuenta'] !== '' ? $b['cuenta'] : '14';
                $cuenta61 = (string) ($homol[$cuenta14]->cuenta_61 ?? $cuenta14); // su 6 correspondiente
                $monto = round((float) $b['monto'], 2);
                if ($monto <= 0.005) continue;
                $mov[] = [
                    'un'              => (string) $b['un'],
                    'cuenta_credito'  => $cuenta14, 'tercero_credito' => (string) ($b['tercero'] ?? $terceroPersona),
                    'cuenta_debito'   => $cuenta61, 'tercero_debito'  => $terceroPersona,
                    'monto'           => $monto,    'cedula' => (string) $ced,
                ];
            }
        }
        return $mov;
    }

    /**
     * Retiro de MO por (UN|cuenta 14) de los terceros registrados, para descontarlo del saldo
     * de las bolsas de Operaciones. Incluye la MO directa (salario) Y la seguridad social
     * atribuida (la porción de la cuenta 14 del fondo que corresponde a las personas), pues
     * ambas salen de la cuenta 14 y las gestiona Contabilidad. Se calcula desde costoPorPersona,
     * que ya usa el corte ACUMULADO AL MES (igual que el saldo de la bolsa).
     *
     * @param array|null $codigos  bolsas a considerar (por defecto todas las UnBolsa)
     * @return array<string, float>  [ "un|cuenta" => monto ]
     */
    public function retiroAcumuladoPorUnCuenta(int $anio, int $mes, ?array $codigos = null): array
    {
        $out = [];
        foreach ($this->costoPorPersona($mes, $anio) as $p) {
            foreach ($p['buckets'] as $b) {
                $un = (string) ($b['un'] ?? '');
                if ($un === '' || $un === '—') continue;
                if ($codigos !== null && ! in_array($un, $codigos, true)) continue;
                $k = $un.'|'.$b['cuenta'];
                $out[$k] = ($out[$k] ?? 0) + (float) $b['monto'];
            }
        }
        return $out;
    }

    /**
     * Terceros con MO en bolsas del período que NO cruzaron con ninguna persona del maestro
     * (para diagnóstico: qué falta agregar/corregir en el maestro). Devuelve [{doc,nombre,monto}].
     *
     * @return array<int, array{doc:string,nombre:string,monto:float}>
     */
    public function tercerosSinCruzar(int $mes, int $anio): array
    {
        $maestro = ManoObraDirecta::where('activo', true)->get();
        [$porCed, $porNom] = $this->indicesMaestro($maestro);
        // Fondos de la autoliquidación: sus líneas de cuenta 14 son seguridad social (no personas
        // por registrar), así que no deben aparecer como "terceros sin cruzar".
        $fondos = $this->fondosAutoliquidacion($mes, $anio, $porCed, $porNom);

        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);
        if (empty($cuentasMO) || empty($unBolsa)) {
            return [];
        }

        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $unBolsa)
            ->whereIn('cuenta_contable', $cuentasMO)
            ->where($this->corteAcum($anio, $mes))
            ->selectRaw('tercero_dcto, razon_social, SUM(estado_er) as saldo')
            ->groupBy('tercero_dcto', 'razon_social')
            ->get();

        $sin = [];
        foreach ($filas as $r) {
            $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0;
            if ($m <= 0.005) continue;
            if ($this->cruzar($r->tercero_dcto, $r->razon_social, $porCed, $porNom) !== null) continue;
            // Excluir fondos/EPS (seguridad social): no son personas a registrar.
            $esFondo = isset($fondos['byNit'][$this->normCedula($r->tercero_dcto)])
                || isset($fondos['byNom'][$this->normNombre($r->razon_social)]);
            if ($esFondo) continue;
            $doc = trim((string) $r->tercero_dcto);
            $nom = trim((string) $r->razon_social);
            $key = $doc.'|'.$nom;
            if (! isset($sin[$key])) $sin[$key] = ['doc' => $doc, 'nombre' => $nom, 'monto' => 0.0];
            $sin[$key]['monto'] += $m;
        }
        usort($sin, fn ($a, $b) => $b['monto'] <=> $a['monto']);
        return array_values($sin);
    }
}
