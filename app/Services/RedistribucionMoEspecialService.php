<?php

namespace App\Services;

use App\Models\AutoliquidacionAporte;
use App\Models\Homologacion;
use App\Models\ManoObraEspecial;
use App\Models\MontoDistribuirMoEspecial;
use App\Models\RedistribucionMoEspecial;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;

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
        $maestro = ManoObraEspecial::where('activo', true)->get();
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
                ->where('mes', $mes)->where('anio', $anio)
                ->selectRaw('codigo_proyecto as un, cuenta_contable as cuenta, tercero_dcto, razon_social, SUM(estado_er) as saldo')
                ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'razon_social')
                ->get();
        }

        // 2) SEGURIDAD SOCIAL: aporte_empresa de la autoliquidación, cruzado por la cédula del
        //    empleado (o su nombre). En el financiero estos aportes vienen a nombre del fondo/EPS,
        //    por eso se cruzan por la autoliquidación.
        $ss = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)
            ->selectRaw('empleado, empleado_nombre, un_codigo as un, cuenta_contable as cuenta, SUM(aporte_empresa) as monto')
            ->groupBy('empleado', 'empleado_nombre', 'un_codigo', 'cuenta_contable')
            ->havingRaw('SUM(aporte_empresa) > 0.005')
            ->get();

        $out = [];
        foreach ($nombreMae as $ced => $nom) {
            $out[$ced] = ['cedula' => (string) $ced, 'nombre' => $nom,
                'directo' => 0.0, 'ss' => 0.0, 'total' => 0.0, 'buckets' => []];
        }

        foreach ($directo as $r) {
            $ced = $this->cruzar($r->tercero_dcto, $r->razon_social, $porCed, $porNom);
            if ($ced === null || ! isset($out[$ced])) continue;
            $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0; // el costo por repartir va negativo
            if ($m <= 0.005) continue;
            $out[$ced]['directo'] += $m;
            $out[$ced]['total']   += $m;
            $out[$ced]['buckets'][] = ['un' => (string) $r->un, 'cuenta' => (string) $r->cuenta, 'monto' => $m, 'tipo' => 'directo'];
        }

        foreach ($ss as $r) {
            $ced = $this->cruzar($r->empleado, $r->empleado_nombre, $porCed, $porNom);
            if ($ced === null || ! isset($out[$ced])) continue;
            $m = (float) $r->monto;
            if ($m <= 0.005) continue;
            $out[$ced]['ss']    += $m;
            $out[$ced]['total'] += $m;
            $out[$ced]['buckets'][] = ['un' => (string) ($r->un ?: '—'), 'cuenta' => (string) ($r->cuenta ?: ''), 'monto' => $m, 'tipo' => 'ss'];
        }

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

    /** Porcentajes por persona/UN del período: [cedula => [un_codigo => porcentaje]]. */
    public function porcentajes(int $mes, int $anio): array
    {
        $out = [];
        foreach (RedistribucionMoEspecial::where('mes', $mes)->where('anio', $anio)->get() as $r) {
            $out[$r->cedula][$r->un_codigo] = (float) $r->porcentaje;
        }
        return $out;
    }

    /** Monto a distribuir por persona del período (lo que Contabilidad decidió repartir): [cedula => monto]. */
    public function montosDistribuir(int $mes, int $anio): array
    {
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
     * Resumen por bolsa (UN): MO cruda del período, lo retirado (Grupo B), el neto, lo
     * redistribuido por % y el final. Global: sum(final) == sum(crudo).
     *
     * @return array{filas: array, total_crudo: float, total_retirado: float, total_redistribuido: float}
     */
    public function resumenBolsas(int $mes, int $anio): array
    {
        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);
        $nombresUn = UnBolsa::pluck('nombre', 'codigo');

        // MO cruda por UN (todas las líneas MO de las bolsas del período).
        $crudo = [];
        if (! empty($cuentasMO)) {
            $rows = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
                ->whereIn('codigo_proyecto', $unBolsa)
                ->whereIn('cuenta_contable', $cuentasMO)
                ->where('mes', $mes)->where('anio', $anio)
                ->selectRaw('codigo_proyecto as un, SUM(estado_er) as saldo')
                ->groupBy('codigo_proyecto')->get();
            foreach ($rows as $r) {
                $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0;
                if ($m > 0.005) $crudo[$r->un] = $m;
            }
        }

        $costo   = $this->costoPorPersona($mes, $anio);
        $pcts    = $this->porcentajes($mes, $anio);
        $montos  = $this->montosDistribuir($mes, $anio);

        $retirado = [];       // por UN de origen (todo el total del tercero sale de la bolsa)
        $redistribuido = [];  // por UN destino (según %, sobre el monto a distribuir)
        $pendiente = 0.0;     // total retirado que Contabilidad aún no distribuye
        foreach ($costo as $ced => $p) {
            if ($p['total'] <= 0.005) continue;
            foreach ($p['buckets'] as $b) {
                $retirado[$b['un']] = ($retirado[$b['un']] ?? 0) + $b['monto'];
            }
            $montoDist = $this->montoEfectivo((float) $p['total'], (string) $ced, $montos);
            $pendiente += (float) $p['total'] - $montoDist;
            // Redistribución: solo si los % del período suman 100. Reparte el monto a distribuir.
            $ptc = $pcts[$ced] ?? [];
            if ($montoDist > 0.005 && abs(array_sum($ptc) - 100) < 0.05) {
                foreach ($ptc as $un => $pct) {
                    $redistribuido[$un] = ($redistribuido[$un] ?? 0) + $montoDist * $pct / 100;
                }
            }
        }

        $unes = array_unique(array_merge(array_keys($crudo), array_keys($retirado), array_keys($redistribuido)));
        $filas = [];
        foreach ($unes as $un) {
            $c = (float) ($crudo[$un] ?? 0);
            $r = (float) ($retirado[$un] ?? 0);
            $d = (float) ($redistribuido[$un] ?? 0);
            $filas[] = [
                'un' => (string) $un, 'nombre' => $nombresUn[$un] ?? '',
                'crudo' => $c, 'retirado' => $r, 'neto' => $c - $r, 'redistribuido' => $d, 'final' => $c - $r + $d,
            ];
        }
        usort($filas, fn ($a, $b) => $b['crudo'] <=> $a['crudo']);

        return [
            'filas'               => $filas,
            'total_crudo'         => array_sum(array_column($filas, 'crudo')),
            'total_retirado'      => array_sum($retirado),
            'total_redistribuido' => array_sum($redistribuido),
            'total_pendiente'     => round($pendiente, 2),
        ];
    }

    /**
     * Tuplas de movimiento para el plano contable. Al distribuir, el costo SALE de la cuenta 14
     * (por aplicar) de la bolsa de origen y ENTRA a su cuenta 6 correspondiente (homologada) en
     * la bolsa destino, según el porcentaje, para que el costo quede en firme. Cada tupla es un
     * asiento balanceado: CR cuenta 14 (origen) / DB cuenta 61 (destino).
     *
     * @return array<int, array{un_origen:string,cuenta_origen:string,un_destino:string,cuenta_destino:string,monto:float,cedula:string}>
     */
    public function movimientosRedistribucion(int $mes, int $anio): array
    {
        $costo  = $this->costoPorPersona($mes, $anio);
        $pcts   = $this->porcentajes($mes, $anio);
        $montos = $this->montosDistribuir($mes, $anio);
        $homol  = Homologacion::mapaEn(Homologacion::periodo($anio, $mes));

        $mov = [];
        foreach ($costo as $ced => $p) {
            if ($p['total'] <= 0.005) continue;
            $ptc = $pcts[$ced] ?? [];
            if (abs(array_sum($ptc) - 100) >= 0.05) continue; // sin % válidos: no se redistribuye

            // Se distribuye la totalidad o una porción: cada cuenta 14 se escala por la fracción
            // del total que Contabilidad decidió distribuir; el resto queda pendiente en la 14.
            $montoDist = $this->montoEfectivo((float) $p['total'], (string) $ced, $montos);
            if ($montoDist <= 0.005) continue;
            $fraccion = $montoDist / (float) $p['total'];

            foreach ($p['buckets'] as $b) {
                $cuenta14 = $b['cuenta'] !== '' ? $b['cuenta'] : '14';
                $cuenta61 = (string) ($homol[$cuenta14]->cuenta_61 ?? $cuenta14); // su 6 correspondiente
                foreach ($ptc as $un => $pct) {
                    $monto = round($b['monto'] * $fraccion * $pct / 100, 2);
                    if ($monto <= 0.005) continue;
                    $mov[] = [
                        'un_origen'     => $b['un'],       'cuenta_origen'  => $cuenta14,
                        'un_destino'    => (string) $un,   'cuenta_destino' => $cuenta61,
                        'monto'         => $monto,         'cedula'         => (string) $ced,
                    ];
                }
            }
        }
        return $mov;
    }

    /**
     * Retiro de MO por (UN|cuenta 14) de los terceros registrados, para descontarlo del saldo
     * de las bolsas de Operaciones. Usa el MISMO corte ACUMULADO AL MES que el saldo de la
     * bolsa (meses anteriores + mes filtrado), no solo el mes exacto, para que el retiro
     * coincida con lo que la bolsa arrastra aunque la MO se haya cargado en un mes previo.
     * Solo la MO directa (cuyo tercero ES la persona registrada); la seguridad social viene a
     * nombre del fondo y no se retira por aquí.
     *
     * @param array|null $codigos  bolsas a considerar (por defecto todas las UnBolsa)
     * @return array<string, float>  [ "un|cuenta" => monto ]
     */
    public function retiroAcumuladoPorUnCuenta(int $anio, int $mes, ?array $codigos = null): array
    {
        $maestro = ManoObraEspecial::where('activo', true)->get();
        $codigos = $codigos ?? UnBolsa::codigos();
        if ($maestro->isEmpty() || empty($codigos)) {
            return [];
        }
        [$porCed, $porNom] = $this->indicesMaestro($maestro);

        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $codigos)
            ->whereIn('cuenta_contable', self::CUENTAS_MO)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)->orWhere(function ($q2) use ($anio, $mes) {
                    $q2->where('anio', $anio)->where('mes', '<=', $mes);
                });
            })
            ->selectRaw('codigo_proyecto as un, cuenta_contable as cuenta, tercero_dcto, razon_social, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'razon_social')
            ->get();

        $out = [];
        foreach ($filas as $r) {
            $ced = $this->cruzar($r->tercero_dcto, $r->razon_social, $porCed, $porNom);
            if ($ced === null) continue;
            $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0;
            if ($m <= 0.005) continue;
            $k = $r->un.'|'.$r->cuenta;
            $out[$k] = ($out[$k] ?? 0) + $m;
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
        $maestro = ManoObraEspecial::where('activo', true)->get();
        [$porCed, $porNom] = $this->indicesMaestro($maestro);

        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);
        if (empty($cuentasMO) || empty($unBolsa)) {
            return [];
        }

        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $unBolsa)
            ->whereIn('cuenta_contable', $cuentasMO)
            ->where('mes', $mes)->where('anio', $anio)
            ->selectRaw('tercero_dcto, razon_social, SUM(estado_er) as saldo')
            ->groupBy('tercero_dcto', 'razon_social')
            ->get();

        $sin = [];
        foreach ($filas as $r) {
            $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0;
            if ($m <= 0.005) continue;
            if ($this->cruzar($r->tercero_dcto, $r->razon_social, $porCed, $porNom) !== null) continue;
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
