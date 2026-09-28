<?php

namespace App\Services;

use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\UnBolsa;

/**
 * Distribución de mano de obra por tercero → obra: genera el plano 14→61 de lo asignado y su
 * resumen por obra. El desglose por tercero de las bolsas (para el grid) lo calcula
 * DistribucionService::manoObraPorTercero; aquí solo se construye el plano y el resumen a partir de
 * lo guardado en mano_obra_asignacion (una fila por UN, cuenta 14, tercero SIESA, obra).
 */
class DistribucionManoObraService
{
    /** Centro de costos de la cuenta 6 según el departamento de la bolsa. */
    private const CENTRO_COSTOS = [
        'mantenimiento' => '30020105',
        'instalaciones' => '30010103',
    ];

    /**
     * Líneas del plano 14→61 (SIESA parcial) de una UN: CR la 14 en la bolsa (conserva el tercero)
     * y DB la 61 homologada en la obra destino (mismo tercero, con centro de costos del depto).
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
     * Resumen del costo de MO por obra destino (de una UN): total por obra y desglose por
     * tercero/cuenta.
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
