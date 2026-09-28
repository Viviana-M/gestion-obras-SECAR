<?php

namespace App\Services;

use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;

/**
 * Distribución de mano de obra por persona (tercero) → obra destino.
 *
 * La MO se identifica por la homologación: son las cuentas 14 cuya estructura es mano de obra
 * (MOI interna, MOE externa, MOFIJAOPER fija). El saldo por repartir se toma de la cuenta 14 de la
 * bolsa (origen BIABLE) del período, agrupado por tercero. Cada asignación (tercero, cuenta 14 →
 * obra) alimenta el plano 14→61 preservando tercero y obra.
 */
class DistribucionManoObraService
{
    /** Estructuras de homologación que son mano de obra (según DistribucionService::CATEGORIAS). */
    public const ESTRUCTURAS_MO = ['MOI', 'MOE', 'MOFIJAOPER'];

    /** Centro de costos de la cuenta 6 según el departamento de la bolsa. */
    private const CENTRO_COSTOS = [
        'mantenimiento' => '30020105',
        'instalaciones' => '30010103',
    ];

    /** Cuentas 14 de mano de obra vigentes en el período (por su estructura homologada). */
    public function cuentasMo(int $mes, int $anio): array
    {
        return Homologacion::mapaEn(Homologacion::periodo($anio, $mes))
            ->filter(fn ($h) => in_array((string) $h->estructura, self::ESTRUCTURAS_MO, true))
            ->keys()->map(fn ($c) => (string) $c)->all();
    }

    /**
     * Saldo de MO pendiente por tercero en la cuenta 14 de una bolsa (origen BIABLE, del mes),
     * con el detalle por cuenta 14 (para el plano). El saldo se muestra positivo (costo por repartir).
     *
     * @return array<int, array{tercero:string,doc:string,nombre:string,saldo:float,buckets:array<string,float>}>
     */
    public function saldosPorTercero(string $bolsa, int $mes, int $anio): array
    {
        $cuentas = $this->cuentasMo($mes, $anio);
        if (empty($cuentas)) {
            return [];
        }

        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where('codigo_proyecto', $bolsa)
            ->where('mes', $mes)->where('anio', $anio)
            ->where('origen', 'biable')                 // el costo original de nómina (no lo ya aplicado)
            ->whereIn('cuenta_contable', $cuentas)
            ->selectRaw('cuenta_contable, tercero_dcto, razon_social, SUM(estado_er) as er')
            ->groupBy('cuenta_contable', 'tercero_dcto', 'razon_social')
            ->get();

        $porTercero = [];
        foreach ($filas as $r) {
            $saldo = -1 * (float) $r->er;               // estado_er negativo = pendiente → positivo
            if ($saldo <= 0.005) continue;              // sólo lo que está por repartir

            $doc    = trim((string) $r->tercero_dcto);
            $nombre = trim((string) $r->razon_social);
            $key    = $doc !== '' ? $doc : $nombre;      // tercero_dcto; si viene vacío, razón social
            if ($key === '') continue;

            if (! isset($porTercero[$key])) {
                $porTercero[$key] = ['tercero' => $key, 'doc' => $doc, 'nombre' => $nombre,
                    'saldo' => 0.0, 'buckets' => []];
            }
            $porTercero[$key]['saldo'] += $saldo;
            $porTercero[$key]['buckets'][(string) $r->cuenta_contable] =
                ($porTercero[$key]['buckets'][(string) $r->cuenta_contable] ?? 0) + $saldo;
            if ($porTercero[$key]['nombre'] === '' && $nombre !== '') {
                $porTercero[$key]['nombre'] = $nombre;
            }
        }

        foreach ($porTercero as &$t) {
            $t['saldo'] = round($t['saldo'], 2);
            $t['buckets'] = array_map(fn ($v) => round($v, 2), $t['buckets']);
        }
        unset($t);

        // Orden por saldo descendente.
        uasort($porTercero, fn ($a, $b) => $b['saldo'] <=> $a['saldo']);

        return array_values($porTercero);
    }

    /** Total asignado por tercero en el período (de mano_obra_asignacion). [tercero => monto] */
    public function asignadoPorTercero(string $bolsa, int $mes, int $anio): array
    {
        return ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)
            ->selectRaw('tercero, SUM(monto) as m')->groupBy('tercero')
            ->pluck('m', 'tercero')->map(fn ($v) => round((float) $v, 2))->all();
    }

    /**
     * Líneas del plano 14→61 (formato SIESA parcial: cuenta, tercero, unidad, débito, crédito) a
     * partir de las asignaciones guardadas. Por cada asignación: CR 14 en la bolsa (conserva tercero)
     * y DB 61 homologada en la obra destino (mismo tercero, con centro de costos del departamento).
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

            // CR la 14 en la bolsa (origen), DB la 61 en la obra destino (con centro de costos).
            $mov[] = ['cuenta' => $c14, 'tercero' => $ter, 'unidad' => (string) $bolsa,        'centro' => null,    'debito' => 0,      'credito' => $monto];
            $mov[] = ['cuenta' => $c61, 'tercero' => $ter, 'unidad' => (string) $a->obra_destino, 'centro' => $centro, 'debito' => $monto, 'credito' => 0];
        }

        return $mov;
    }

    /**
     * Resumen del costo de MO por obra destino: total por obra y el desglose por (tercero, cuenta).
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
                'tercero' => (string) $a->tercero, 'nombre' => (string) $a->tercero_nombre,
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
