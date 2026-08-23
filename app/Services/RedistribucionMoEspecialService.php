<?php

namespace App\Services;

use App\Models\AutoliquidacionAporte;
use App\Models\Homologacion;
use App\Models\ManoObraEspecial;
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
    /** Estructuras de homologación que son mano de obra. */
    public const ESTRUCTURAS_MO = ['MOI', 'MOE', 'MOFIJAOPER'];

    /** Cuentas 14 que son mano de obra según la homologación del período. */
    public function cuentasMO(int $mes, int $anio): array
    {
        $periodo = Homologacion::periodo($anio, $mes);
        $mo = [];
        foreach (Homologacion::mapaEn($periodo) as $c14 => $h) {
            if (in_array($h->estructura ?? null, self::ESTRUCTURAS_MO, true)) {
                $mo[] = (string) $c14;
            }
        }
        return $mo;
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

        // Índice CÉDULA normalizada → cédula del maestro. El cruce es SOLO por cédula (ni el
        // financiero ni la autoliquidación cruzan por nombre, porque los nombres difieren en
        // acentos/orden/abreviaturas). Normalizamos el documento quitando puntos/espacios/guiones
        // para que "12.345.678" == "12345678", que es la causa típica de que no cruzara.
        $idx = [];
        foreach ($maestro as $p) {
            $n = $this->normCedula($p->cedula);
            if ($n !== '') {
                $idx[$n] = $p->cedula;
            }
        }
        if (empty($idx)) {
            return [];
        }

        $unBolsa   = UnBolsa::codigos();
        $cuentasMO = $this->cuentasMO($mes, $anio);

        // 1) MO DIRECTA: líneas de bolsa (cuenta 14 MO) del período. Se traen agrupadas por
        //    documento del tercero (tercero_dcto = cédula/NIT) y se atribuyen a la persona por
        //    coincidencia de cédula normalizada (robusto ante diferencias de formato).
        $directo = collect();
        if (! empty($cuentasMO) && ! empty($unBolsa)) {
            $directo = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
                ->whereIn('codigo_proyecto', $unBolsa)
                ->whereIn('cuenta_contable', $cuentasMO)
                ->where('mes', $mes)->where('anio', $anio)
                ->selectRaw('codigo_proyecto as un, cuenta_contable as cuenta, tercero_dcto, SUM(estado_er) as saldo')
                ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto')
                ->get();
        }

        // 2) SEGURIDAD SOCIAL: aporte_empresa de la autoliquidación, cruzado por la CÉDULA del
        //    empleado (en el financiero estos aportes vienen a nombre del fondo/EPS, por eso se
        //    cruzan por la autoliquidación). También por cédula, con la misma normalización.
        $ss = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)
            ->selectRaw('empleado, un_codigo as un, cuenta_contable as cuenta, SUM(aporte_empresa) as monto')
            ->groupBy('empleado', 'un_codigo', 'cuenta_contable')
            ->havingRaw('SUM(aporte_empresa) > 0.005')
            ->get();

        $out = [];
        foreach ($idx as $ced) {
            $out[$ced] = ['cedula' => $ced, 'nombre' => $nombreMae[$ced] ?? $ced,
                'directo' => 0.0, 'ss' => 0.0, 'total' => 0.0, 'buckets' => []];
        }

        foreach ($directo as $r) {
            $ced = $idx[$this->normCedula($r->tercero_dcto)] ?? null;
            if ($ced === null || ! isset($out[$ced])) continue;
            $m = (float) $r->saldo < 0 ? abs((float) $r->saldo) : 0.0; // el costo por repartir va negativo
            if ($m <= 0.005) continue;
            $out[$ced]['directo'] += $m;
            $out[$ced]['total']   += $m;
            $out[$ced]['buckets'][] = ['un' => (string) $r->un, 'cuenta' => (string) $r->cuenta, 'monto' => $m, 'tipo' => 'directo'];
        }

        foreach ($ss as $r) {
            $ced = $idx[$this->normCedula($r->empleado)] ?? null;
            if ($ced === null || ! isset($out[$ced])) continue;
            $m = (float) $r->monto;
            if ($m <= 0.005) continue;
            $out[$ced]['ss']    += $m;
            $out[$ced]['total'] += $m;
            $out[$ced]['buckets'][] = ['un' => (string) ($r->un ?: '—'), 'cuenta' => (string) ($r->cuenta ?: ''), 'monto' => $m, 'tipo' => 'ss'];
        }

        return $out;
    }

    /** Normaliza una cédula/NIT para cruzar terceros: solo alfanuméricos, en minúsculas (quita puntos, espacios y guiones). */
    private function normCedula(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $s)));
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

        $retirado = [];       // por UN de origen (de los buckets de cada persona)
        $redistribuido = [];  // por UN destino (según %)
        foreach ($costo as $ced => $p) {
            if ($p['total'] <= 0.005) continue;
            foreach ($p['buckets'] as $b) {
                $retirado[$b['un']] = ($retirado[$b['un']] ?? 0) + $b['monto'];
            }
            // Redistribución: solo si los % del período suman 100.
            $ptc = $pcts[$ced] ?? [];
            if (abs(array_sum($ptc) - 100) < 0.05) {
                foreach ($ptc as $un => $pct) {
                    $redistribuido[$un] = ($redistribuido[$un] ?? 0) + $p['total'] * $pct / 100;
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
        ];
    }

    /**
     * Tuplas de movimiento 14→14 para el plano: por cada bucket de cada persona con % válidos,
     * CR en la UN de origen y DB en cada UN destino (misma cuenta) según el porcentaje.
     *
     * @return array<int, array{un_origen:string,un_destino:string,cuenta:string,monto:float,cedula:string}>
     */
    public function movimientosRedistribucion(int $mes, int $anio): array
    {
        $costo = $this->costoPorPersona($mes, $anio);
        $pcts  = $this->porcentajes($mes, $anio);

        $mov = [];
        foreach ($costo as $ced => $p) {
            if ($p['total'] <= 0.005) continue;
            $ptc = $pcts[$ced] ?? [];
            if (abs(array_sum($ptc) - 100) >= 0.05) continue; // sin % válidos: no se redistribuye

            foreach ($p['buckets'] as $b) {
                $cuenta = $b['cuenta'] !== '' ? $b['cuenta'] : '14';
                foreach ($ptc as $un => $pct) {
                    $monto = round($b['monto'] * $pct / 100, 2);
                    if ($monto <= 0.005) continue;
                    $mov[] = [
                        'un_origen'  => $b['un'], 'un_destino' => (string) $un,
                        'cuenta'     => (string) $cuenta, 'monto' => $monto, 'cedula' => (string) $ced,
                    ];
                }
            }
        }
        return $mov;
    }
}
