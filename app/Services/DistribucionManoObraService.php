<?php

namespace App\Services;

use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\UnBolsa;

/**
 * Distribución de mano de obra directa por persona → obra: genera el plano 14→61 de lo asignado y su
 * resumen por obra. El costo completo por persona (para el grid) lo calcula
 * DistribucionService::manoObraDirectaPorPersona; aquí solo se construye el plano y el resumen a
 * partir de lo guardado en mano_obra_asignacion (una fila por UN, cuenta 14, tercero del ERP, obra,
 * con la persona en la columna persona).
 */
class DistribucionManoObraService
{
    /** Centro de costos de la cuenta 6 según el departamento de la bolsa. */
    private const CENTRO_COSTOS = [
        'mantenimiento' => '30020105',
        'instalaciones' => '30010103',
    ];

    /**
     * Líneas del plano 14→61 (SIESA parcial) de una UN, preservando persona + obra destino:
     *   - CR la cuenta 14 en la bolsa con el TERCERO del ERP (la persona en el salario; el fondo/EPS
     *     en la seguridad social), para que esa 14 baje donde está el costo.
     *   - DB la cuenta 61 homologada en la obra destino con la PERSONA y el centro de costos del depto.
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

            $c14     = (string) $a->cuenta_14;
            $c61     = (string) ($homol[$c14]->cuenta_61 ?? $c14);
            $terCr   = (string) $a->tercero;                        // tercero del ERP (persona o fondo/EPS)
            $persona = (string) ($a->persona ?: $a->tercero);       // la persona (para la 61 de la obra)

            $mov[] = ['cuenta' => $c14, 'tercero' => $terCr,   'unidad' => (string) $bolsa,          'centro' => null,    'debito' => 0,      'credito' => $monto];
            $mov[] = ['cuenta' => $c61, 'tercero' => $persona, 'unidad' => (string) $a->obra_destino, 'centro' => $centro, 'debito' => $monto, 'credito' => 0];
        }

        return $mov;
    }

    /**
     * Resumen del costo de MO por obra destino (de una UN): total por obra y desglose por PERSONA
     * (agrega los buckets — salario y seguridad social — de cada persona).
     *
     * @return array<int, array{obra:string,total:float,detalle:array<int,array{tercero:string,nombre:string,monto:float}>}>
     */
    public function resumenPorObra(string $bolsa, int $mes, int $anio): array
    {
        $rows = ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)
            ->orderBy('obra_destino')->get();

        $porObra = [];
        foreach ($rows as $a) {
            $monto = round((float) $a->monto, 2);
            if ($monto <= 0.005) continue;
            $obra    = (string) $a->obra_destino;
            $persona = (string) ($a->persona ?: $a->tercero);
            if (! isset($porObra[$obra])) {
                $porObra[$obra] = ['obra' => $obra, 'total' => 0.0, 'detalle' => []];
            }
            $porObra[$obra]['total'] += $monto;
            if (! isset($porObra[$obra]['detalle'][$persona])) {
                $porObra[$obra]['detalle'][$persona] = [
                    'tercero' => $persona, 'nombre' => (string) $a->tercero_nombre, 'monto' => 0.0,
                ];
            }
            $porObra[$obra]['detalle'][$persona]['monto'] += $monto;
        }

        foreach ($porObra as &$o) {
            $o['total'] = round($o['total'], 2);
            foreach ($o['detalle'] as &$d) $d['monto'] = round($d['monto'], 2);
            unset($d);
            usort($o['detalle'], fn ($a, $b) => $b['monto'] <=> $a['monto']);
        }
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
