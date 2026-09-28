<?php

namespace App\Services;

use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\TerceroManoObra;
use App\Models\UnBolsa;

/**
 * Distribución de MANO DE OBRA DIRECTA por persona → obra destino.
 *
 * Igual que la mano de obra de apoyo, el costo por persona = su MO (salario) de la bolsa + la
 * seguridad social atribuida por la autoliquidación (PILA). La diferencia es el maestro: aquí son
 * las personas de "Terceros de mano de obra" (TerceroManoObra), no las de apoyo. Solo aparecen las
 * personas del maestro con MO en la bolsa; las demás ya están distribuidas.
 *
 * El detalle se conserva por (cuenta 14, tercero SIESA) — la persona en el salario y el fondo/EPS
 * en la seguridad social — para generar el plano 14→61 preservando tercero y obra.
 */
class DistribucionManoObraService
{
    /** Centro de costos de la cuenta 6 según el departamento de la bolsa. */
    private const CENTRO_COSTOS = [
        'mantenimiento' => '30020105',
        'instalaciones' => '30010103',
    ];

    /**
     * Costo de MO por PERSONA (maestro directa) en una bolsa: salario de la bolsa + SS de PILA
     * atribuida a esa bolsa, del período. Reutiliza el cálculo de apoyo (costoPorPersona) con el
     * maestro de mano de obra directa y filtra a la bolsa. El detalle por (cuenta 14, tercero SIESA)
     * queda en `buckets` para el plano.
     *
     * @return array<int, array{tercero:string,doc:string,nombre:string,saldo:float,buckets:array<int,array{cuenta_14:string,tercero:string,monto:float}>}>
     */
    public function saldosPorTercero(string $bolsa, int $mes, int $anio): array
    {
        $maestro = TerceroManoObra::where('activo', true)->get();
        if ($maestro->isEmpty()) {
            return [];
        }

        $costo = (new RedistribucionMoEspecialService())->costoPorPersona($mes, $anio, $maestro);

        $out = [];
        foreach ($costo as $ced => $p) {
            // Solo los buckets (salario + SS) que caen en ESTA bolsa.
            $buckets = [];
            $saldo   = 0.0;
            foreach ($p['buckets'] as $b) {
                if ((string) $b['un'] !== $bolsa) continue;
                $monto = round((float) $b['monto'], 2);
                if ($monto <= 0.005) continue;
                $saldo += $monto;
                $k = $b['cuenta'].'|'.$b['tercero'];             // cuenta 14 + tercero SIESA (persona o fondo)
                if (! isset($buckets[$k])) {
                    $buckets[$k] = ['cuenta_14' => (string) $b['cuenta'], 'tercero' => (string) $b['tercero'], 'monto' => 0.0];
                }
                $buckets[$k]['monto'] += $monto;
            }
            if ($saldo <= 0.005) continue;

            foreach ($buckets as &$bk) $bk['monto'] = round($bk['monto'], 2);
            unset($bk);

            $out[] = [
                'tercero' => (string) $ced,                       // clave de PERSONA (cédula del maestro)
                'doc'     => (string) ($p['doc'] ?: $ced),
                'nombre'  => (string) $p['nombre'],
                'saldo'   => round($saldo, 2),
                'buckets' => array_values($buckets),
            ];
        }

        usort($out, fn ($a, $b) => $b['saldo'] <=> $a['saldo']);

        return $out;
    }

    /** Total asignado por PERSONA en el período (de mano_obra_asignacion). [persona => monto] */
    public function asignadoPorTercero(string $bolsa, int $mes, int $anio): array
    {
        return ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)
            ->selectRaw('persona, SUM(monto) as m')->groupBy('persona')
            ->pluck('m', 'persona')->map(fn ($v) => round((float) $v, 2))->all();
    }

    /**
     * Líneas del plano 14→61 (formato SIESA parcial) desde las asignaciones guardadas. Por cada
     * asignación: CR 14 en la bolsa (conserva el tercero SIESA: persona en salario, fondo en SS) y
     * DB 61 homologada en la obra destino (mismo tercero, con centro de costos del departamento).
     *
     * @return array<int, array{cuenta:string,tercero:string,unidad:string,centro:?string,debito:float,credito:float}>
     */
    public function lineasPlano(string $bolsa, int $mes, int $anio): array
    {
        $homol  = Homologacion::mapaEn(Homologacion::periodo($anio, $mes));
        $centro = $this->centroCosto($bolsa);

        $mov = [];
        foreach (ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)->get() as $a) {
            $monto = round((float) $a->monto, 2);
            if ($monto <= 0.005) continue;

            $c14 = (string) $a->cuenta_14;
            $c61 = (string) ($homol[$c14]->cuenta_61 ?? $c14);
            $ter = (string) $a->tercero;

            $mov[] = ['cuenta' => $c14, 'tercero' => $ter, 'unidad' => (string) $bolsa,          'centro' => null,    'debito' => 0,      'credito' => $monto];
            $mov[] = ['cuenta' => $c61, 'tercero' => $ter, 'unidad' => (string) $a->obra_destino, 'centro' => $centro, 'debito' => $monto, 'credito' => 0];
        }

        return $mov;
    }

    /**
     * Resumen del costo de MO por obra destino: total por obra y el desglose por persona/cuenta.
     *
     * @return array<int, array{obra:string,total:float,detalle:array<int,array{tercero:string,nombre:string,cuenta:string,monto:float}>}>
     */
    public function resumenPorObra(string $bolsa, int $mes, int $anio): array
    {
        $rows = ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)
            ->orderBy('obra_destino')->get();

        $porObra = [];
        foreach ($rows as $a) {
            $monto = round((float) $a->monto, 2);
            if ($monto <= 0.005) continue;
            $obra = (string) $a->obra_destino;
            if (! isset($porObra[$obra])) {
                $porObra[$obra] = ['obra' => $obra, 'total' => 0.0, 'detalle' => []];
            }
            $porObra[$obra]['total'] += $monto;
            $porObra[$obra]['detalle'][] = [
                'tercero' => (string) ($a->persona ?: $a->tercero),
                'nombre'  => (string) $a->tercero_nombre,
                'cuenta'  => (string) $a->cuenta_14, 'monto' => $monto,
            ];
        }

        foreach ($porObra as &$o) $o['total'] = round($o['total'], 2);
        unset($o);
        uasort($porObra, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_values($porObra);
    }

    /** Centro de costos de la cuenta 6 según el departamento de la bolsa. */
    public function centroCosto(string $bolsa): ?string
    {
        $depto = (string) (UnBolsa::where('codigo', $bolsa)->value('departamento') ?? '');

        return self::CENTRO_COSTOS[$depto] ?? null;
    }
}
