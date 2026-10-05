<?php

namespace App\Console\Commands;

use App\Models\RegistroFinanciero;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recálculo ÚNICO del saldo de cuenta 14 a Libro 1 (PCGA = débito − crédito), para no tener que
 * recargar todos los meses de BIABLE. Sobre los registros con origen='biable':
 *   - movto_libro2 = valor_debito − valor_credito (Libro 1; ya no el libro 2 NIIF).
 *   - estado_er    = −(valor_debito − valor_credito) si la cuenta empieza en 14, 61 o 4; si no, +.
 *   - Elimina las filas con valor_debito − valor_credito = 0 (las que solo tenían valor en libro 2).
 * NO toca las filas generadas por el sistema (planos u otros orígenes), solo origen='biable'.
 */
class RecalcularPcga extends Command
{
    protected $signature = 'financiero:recalcular-pcga {--force : Ejecuta sin pedir confirmación}';

    protected $description = 'Recalcula estado_er/movto_libro2 de los registros BIABLE a Libro 1 (débito − crédito) y elimina las filas fantasma (neto 0)';

    public function handle(): int
    {
        // Las filas fantasma: solo tenían valor en libro 2 (NIIF); su débito − crédito es 0.
        $fantasma = RegistroFinanciero::where('origen', 'biable')
            ->whereRaw('ABS(valor_debito - valor_credito) < 0.005');
        $base = RegistroFinanciero::where('origen', 'biable');

        $totalBiable = (clone $base)->count();
        $aEliminar   = (clone $fantasma)->count();
        $aRecalcular = $totalBiable - $aEliminar;

        $this->info("Registros BIABLE: {$totalBiable}");
        $this->line("  · A recalcular (neto ≠ 0): {$aRecalcular}");
        $this->line("  · A eliminar (fantasma, neto = 0): {$aEliminar}");

        if ($totalBiable === 0) {
            $this->warn('No hay registros con origen=biable. Nada que hacer.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Continuar? Esto modifica/elimina registros BIABLE (respalda database.sqlite antes).')) {
            $this->warn('Cancelado. No se tocó nada.');
            return self::SUCCESS;
        }

        [$recalculadas, $eliminadas] = DB::transaction(function () {
            // 1) Elimina las fantasma (neto 0): mismo criterio que el importador (movto == 0 → no se carga).
            $eliminadas = RegistroFinanciero::where('origen', 'biable')
                ->whereRaw('ABS(valor_debito - valor_credito) < 0.005')
                ->delete();

            // 2) Recalcula las restantes a Libro 1. estado_er con la misma regla del importador:
            //    14x | 61x | 4x → −(déb − cré); el resto → (déb − cré). (signo_contable se deja igual.)
            $recalculadas = RegistroFinanciero::where('origen', 'biable')->update([
                'movto_libro2' => DB::raw('(valor_debito - valor_credito)'),
                'estado_er'    => DB::raw(
                    "CASE WHEN cuenta_contable LIKE '14%' OR cuenta_contable LIKE '61%' OR cuenta_contable LIKE '4%' "
                    .'THEN -(valor_debito - valor_credito) ELSE (valor_debito - valor_credito) END'
                ),
            ]);

            return [$recalculadas, $eliminadas];
        });

        $this->newLine();
        $this->info("✔ Recálculo PCGA terminado:");
        $this->line("  · Filas recalculadas: {$recalculadas}");
        $this->line("  · Filas eliminadas (fantasma): {$eliminadas}");

        return self::SUCCESS;
    }
}
