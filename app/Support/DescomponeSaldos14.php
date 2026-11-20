<?php

namespace App\Support;

/**
 * Descompone los saldos de la cuenta 1420 de una obra en los dos planos complementarios que
 * desbloquean el cierre de la OT, con la regla de negocio única:
 *
 *   1) COMPENSAR (14↔14): cruzar hasta min(D, C) la parte en débito contra la parte en crédito,
 *      sin mover costo (nunca se toca la 61).
 *   2) REVERSAR (14↔61): el crédito que quede en EXCESO tras compensar (C − D) se reversa contra
 *      la 61. Lo que quede en débito es pendiente de operaciones (no es plano).
 *
 * Convención de signo del sistema (estado_er en la 14 = crédito − débito):
 *   - saldo > 0  → la subcuenta quedó en CRÉDITO (reversado de más).
 *   - saldo < 0  → la subcuenta quedó en DÉBITO (pendiente).
 * Por obra: C = Σ de los saldos en crédito (positivos); D = Σ |saldos en débito| (negativos).
 *
 * El drenaje de min(D, C) se reparte con un "waterfall" determinista (subcuentas ordenadas por
 * código), de modo que la compensación y el reverso coinciden exactamente: aplicando ambos (en
 * cualquier orden) cada subcuenta 1420 queda en 0, salvo el pendiente débito distribuible.
 */
trait DescomponeSaldos14
{
    /** Umbral para ignorar el ruido de centavos. */
    private function umbral14(): float
    {
        return 0.5;
    }

    /**
     * Agrupa filas de saldo (objetos con ->codigo_proyecto, ->cuenta_contable, ->saldo) por obra.
     *
     * @return array<string,array<string,float>>  [obra => [cuenta1420 => saldo]]
     */
    protected function saldosPorObra14($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r->codigo_proyecto][$r->cuenta_contable] = (float) $r->saldo;
        }

        return $out;
    }

    /**
     * Descompone los saldos 1420 de UNA obra.
     *
     * @param  array<string,float>  $saldos  [cuenta1420 => saldo del sistema (crédito − débito)]
     * @return array{
     *   C: float, D: float, comp: float,
     *   compensacion: array<int,array{cuenta:string,debito:float,credito:float}>,
     *   reverso: array<int,array{cuenta:string,saldo:float}>
     * }
     */
    protected function descomponerObra14(array $saldos): array
    {
        $u = $this->umbral14();
        ksort($saldos); // orden estable → waterfall determinista (coincide entre los dos planos)

        $pos = []; // lado crédito (saldo > 0)
        $neg = []; // lado débito  (saldo < 0)
        $C = 0.0;
        $D = 0.0;
        foreach ($saldos as $cuenta => $saldo) {
            $s = round((float) $saldo, 2);
            if ($s > $u) {
                $pos[$cuenta] = $s;
                $C += $s;
            } elseif ($s < -$u) {
                $neg[$cuenta] = $s;
                $D += -$s;
            }
        }
        $C = round($C, 2);
        $D = round($D, 2);
        $comp = round(min($C, $D), 2);

        $compensacion = [];
        $reverso = [];

        // Lado crédito: se drena `comp` (DB 14) con waterfall; el remanente (C − D) va al reverso.
        $rest = $comp;
        foreach ($pos as $cuenta => $s) {
            $x = round(min($s, max($rest, 0.0)), 2);
            if ($x > $u) {
                $compensacion[] = ['cuenta' => $cuenta, 'debito' => $x, 'credito' => 0.0];
            }
            $rem = round($s - $x, 2);
            if ($rem > $u) {
                $reverso[] = ['cuenta' => $cuenta, 'saldo' => $rem];
            }
            $rest = round($rest - $x, 2);
        }

        // Lado débito: se drena `comp` (CR 14) con waterfall. El resto queda pendiente (operaciones).
        $rest = $comp;
        foreach ($neg as $cuenta => $s) {
            $y = round(min(-$s, max($rest, 0.0)), 2);
            if ($y > $u) {
                $compensacion[] = ['cuenta' => $cuenta, 'debito' => 0.0, 'credito' => $y];
            }
            $rest = round($rest - $y, 2);
        }

        return [
            'C' => $C,
            'D' => $D,
            'comp' => $comp,
            'compensacion' => $compensacion,
            'reverso' => $reverso,
        ];
    }
}
