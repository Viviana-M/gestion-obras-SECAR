<?php

namespace App\Services;

use App\Models\RegistroFinanciero;

/**
 * REPARTO FIFO DE TERCEROS
 *
 * El coordinador distribuye un monto por (obra, cuenta 14). Pero SIESA necesita saber
 * A QUE PROVEEDOR corresponde cada peso: si la cuenta 14250101 de la OT MO2546 se le
 * compro a Pepito Perez, al pasar a la 61 debe ir a nombre de Pepito Perez.
 *
 * COMO SE RESUELVE
 *   1. Se toman todas las lineas de cuenta 14 de esa obra, de la mas ANTIGUA a la mas
 *      nueva. Las entradas (compras) tienen estado_er negativo y traen tercero.
 *   2. Lo que ya se aplico en planos anteriores (estado_er positivo) se consume primero,
 *      tambien en FIFO. Hay que hacerlo asi porque esas aplicaciones se registraron a
 *      nombre de SECAR y no se pueden emparejar hacia atras.
 *   3. Lo que queda es el saldo pendiente, ya repartido por proveedor. El monto que se
 *      distribuye este mes se consume de ahi, otra vez FIFO.
 *
 * El total siempre cuadra: lo repartido es exactamente lo que se distribuyo.
 *
 * NOTA: las cuentas de mano de obra y prestaciones (14200530, 14200536, 14200533, etc.)
 * no traen proveedor en SIESA, y esta bien: son costos internos. Esos montos quedan a
 * nombre de SECAR.
 */
class RepartoFifoTerceros
{
    /** NIT de SECAR. Se usa cuando una linea no trae proveedor. */
    public const NIT_SECAR = '890319324';

    /** Colas FIFO por "OBRA|CUENTA" => [ ['tercero','razon','saldo'], ... ] */
    private array $colas = [];

    /** Montos que no tenian respaldo de proveedor, por obra (para avisar). */
    private array $sinRespaldo = [];

    public function __construct(array $obras)
    {
        if (empty($obras)) return;

        $lineas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->whereIn('codigo_proyecto', $obras)
            ->orderBy('anio')
            ->orderBy('mes')
            ->orderBy('id')
            ->get(['id', 'codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'razon_social', 'estado_er']);

        $entradas = [];   // compras: estado_er < 0
        $aplicado = [];   // ya cruzado a la 61 en planos anteriores: estado_er > 0

        foreach ($lineas as $l) {
            $clave = $this->clave((string) $l->codigo_proyecto, (string) $l->cuenta_contable);
            $v     = (float) $l->estado_er;

            if ($v < -0.005) {
                $entradas[$clave][] = [
                    'tercero' => trim((string) $l->tercero_dcto) ?: null,
                    'razon'   => trim((string) $l->razon_social) ?: null,
                    'saldo'   => abs($v),
                ];
            } elseif ($v > 0.005) {
                $aplicado[$clave] = ($aplicado[$clave] ?? 0) + $v;
            }
        }

        // Consumir en FIFO lo que ya se aplico en meses anteriores.
        foreach ($entradas as $clave => $cola) {
            $porConsumir = $aplicado[$clave] ?? 0;

            foreach ($cola as $i => $e) {
                if ($porConsumir <= 0.005) break;
                $usar = min($porConsumir, $e['saldo']);
                $cola[$i]['saldo'] -= $usar;
                $porConsumir       -= $usar;
            }

            $this->colas[$clave] = array_values(
                array_filter($cola, fn($e) => $e['saldo'] > 0.005)
            );
        }
    }

    /**
     * Reparte un monto entre los proveedores de esa (obra, cuenta 14), en FIFO.
     *
     * @return array<int, array{tercero:string, razon:string|null, monto:float}>
     */
    public function repartir(string $obra, string $cuenta14, float $monto): array
    {
        $clave    = $this->clave($obra, $cuenta14);
        $restante = round($monto, 2);
        $porNit   = [];

        if (isset($this->colas[$clave])) {
            foreach ($this->colas[$clave] as $i => $e) {
                if ($restante <= 0.005) break;

                $usar = min($restante, $e['saldo']);
                $this->colas[$clave][$i]['saldo'] -= $usar;
                $restante -= $usar;

                $nit = $e['tercero'] ?: self::NIT_SECAR;
                if (!isset($porNit[$nit])) {
                    $porNit[$nit] = ['razon' => $e['razon'], 'monto' => 0.0];
                }
                $porNit[$nit]['monto'] += $usar;
            }
        }

        // Si el monto distribuido supera lo que respaldan las lineas de la 14, el resto
        // va a SECAR: asi el plano SIEMPRE cuadra, y el sobrante queda reportado.
        if ($restante > 0.005) {
            $nit = self::NIT_SECAR;
            if (!isset($porNit[$nit])) {
                $porNit[$nit] = ['razon' => 'SECAR INGENIEROS SA', 'monto' => 0.0];
            }
            $porNit[$nit]['monto'] += $restante;

            $this->sinRespaldo[$obra] = ($this->sinRespaldo[$obra] ?? 0) + $restante;
        }

        $salida = [];
        foreach ($porNit as $nit => $d) {
            $m = round($d['monto'], 2);
            if ($m <= 0.005) continue;
            $salida[] = [
                'tercero' => (string) $nit,
                'razon'   => $d['razon'],
                'monto'   => $m,
            ];
        }

        return $salida;
    }

    /** Obras donde hubo monto sin proveedor detras (se cargo a SECAR). */
    public function sinRespaldo(): array
    {
        return array_filter($this->sinRespaldo, fn($v) => $v > 0.5);
    }

    private function clave(string $obra, string $cuenta): string
    {
        return $obra . '|' . $cuenta;
    }
}