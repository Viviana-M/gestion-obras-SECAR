<?php

namespace App\Console\Commands;

use App\Jobs\Financiero\ProcesarArchivoFinanciero;
use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use App\Models\SaldoBalance;
use Illuminate\Console\Command;

/**
 * Vuelve a procesar archivos de BIABLE que YA están cargados.
 *
 * Sirve cuando se corrige el importador (columnas nuevas, mapeos, terceros…) y hay que
 * releer los mismos Excel sin volver a subirlos: los archivos siguen en
 * storage/app/financiero/ y la ruta está en carga_financieras.ruta_archivo.
 *
 * El job ProcesarArchivoFinanciero BORRA el período antes de importar, así que
 * reprocesar REEMPLAZA, no duplica.
 *
 * Ejemplos:
 *   php artisan biable:recargar --mes=6 --anio=2026
 *   php artisan biable:recargar --anio=2026
 *   php artisan biable:recargar --id=12
 *   php artisan biable:recargar --todos --force
 */
class BiableRecargar extends Command
{
    protected $signature = 'biable:recargar
                            {--id=    : ID de una carga concreta del historial}
                            {--mes=   : Mes a reprocesar (1-12)}
                            {--anio=  : Año a reprocesar}
                            {--todos  : Reprocesa TODAS las cargas del historial}
                            {--force  : No pide confirmación}
                            {--cola   : Manda a la cola en vez de procesar aquí mismo}';

    protected $description = 'Vuelve a procesar archivos de BIABLE ya cargados (sin volver a subirlos)';

    public function handle(): int
    {
        $cargas = $this->seleccionar();

        if ($cargas === null) {
            return self::FAILURE;
        }

        if ($cargas->isEmpty()) {
            $this->warn('No hay cargas que coincidan con ese filtro.');
            $this->line('Revisa el historial con: php artisan biable:recargar --todos (sin confirmar)');
            return self::SUCCESS;
        }

        // ── Mostrar qué se va a hacer, antes de tocar nada ──
        $filas = [];
        $faltantes = [];

        foreach ($cargas as $c) {
            $ruta   = storage_path('app/' . $c->ruta_archivo);
            $existe = is_file($ruta);

            if (!$existe) $faltantes[] = $c;

            $filas[] = [
                $c->id,
                str_pad((string) $c->mes, 2, '0', STR_PAD_LEFT) . '/' . $c->anio,
                \Illuminate\Support\Str::limit((string) $c->archivo_original, 34),
                number_format(RegistroFinanciero::where('mes', $c->mes)->where('anio', $c->anio)->count(), 0, ',', '.'),
                number_format(SaldoBalance::where('mes', $c->mes)->where('anio', $c->anio)->count(), 0, ',', '.'),
                $existe ? 'OK' : 'FALTA EL ARCHIVO',
            ];
        }

        $this->newLine();
        $this->table(
            ['ID', 'Período', 'Archivo', 'Registros', 'Saldos', 'Estado'],
            $filas
        );

        if (!empty($faltantes)) {
            $this->newLine();
            $this->error('Hay ' . count($faltantes) . ' carga(s) cuyo archivo ya no está en disco. Esas se van a saltar.');
            $this->line('Para esas tendrás que volver a subir el Excel, o usar: php artisan biable:cargar');
        }

        $procesables = $cargas->filter(fn($c) => is_file(storage_path('app/' . $c->ruta_archivo)));

        if ($procesables->isEmpty()) {
            $this->error('No queda ninguna carga procesable.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('OJO: reprocesar BORRA los registros del período y los vuelve a insertar desde el Excel.');
        $this->line('Si alguien ya distribuyó costos sobre esos períodos, los saldos de la cuenta 14 se recalculan.');
        $this->newLine();

        if (!$this->option('force') && !$this->confirm('¿Reprocesar ' . $procesables->count() . ' carga(s)?', false)) {
            $this->line('Cancelado. No se tocó nada.');
            return self::SUCCESS;
        }

        // ── Procesar ──
        $enCola = (bool) $this->option('cola');
        $ok = 0;
        $errores = [];

        foreach ($procesables as $c) {
            $etiqueta = "#{$c->id} · " . str_pad((string) $c->mes, 2, '0', STR_PAD_LEFT) . "/{$c->anio}";

            $c->update(['estado' => 'procesando', 'error' => null]);

            if ($enCola) {
                ProcesarArchivoFinanciero::dispatch($c->ruta_archivo, $c->mes, $c->anio, $c->id);
                $this->line("  → {$etiqueta} enviada a la cola");
                $ok++;
                continue;
            }

            $this->line("  → {$etiqueta} procesando…");
            $t0 = microtime(true);

            try {
                // dispatchSync: se procesa aquí mismo, sin necesidad de queue:work
                ProcesarArchivoFinanciero::dispatchSync($c->ruta_archivo, $c->mes, $c->anio, $c->id);

                $seg  = round(microtime(true) - $t0, 1);
                $regs = RegistroFinanciero::where('mes', $c->mes)->where('anio', $c->anio)->count();
                $sal  = SaldoBalance::where('mes', $c->mes)->where('anio', $c->anio)->count();

                $this->info("     ✓ {$etiqueta}: " . number_format($regs, 0, ',', '.') . " registros · "
                          . number_format($sal, 0, ',', '.') . " saldos · {$seg}s");
                $ok++;

            } catch (\Throwable $e) {
                // dispatchSync no dispara failed(): actualizamos el estado a mano.
                $c->update(['estado' => 'error', 'error' => $e->getMessage()]);
                $errores[] = $etiqueta . ' → ' . $e->getMessage();
                $this->error("     ✗ {$etiqueta}: " . $e->getMessage());
            }
        }

        $this->newLine();

        if ($enCola) {
            $this->info("{$ok} carga(s) enviadas a la cola.");
            $this->warn('Necesitas tener corriendo: php artisan queue:work');
            return self::SUCCESS;
        }

        $this->info("Listo: {$ok} carga(s) reprocesadas correctamente.");

        if (!empty($errores)) {
            $this->newLine();
            $this->error('Con errores (' . count($errores) . '):');
            foreach ($errores as $e) $this->line('  · ' . $e);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Devuelve las cargas a reprocesar, o null si los filtros son inválidos. */
    private function seleccionar()
    {
        $id    = $this->option('id');
        $mes   = $this->option('mes');
        $anio  = $this->option('anio');
        $todos = (bool) $this->option('todos');

        if (!$id && !$mes && !$anio && !$todos) {
            $this->error('Indica qué reprocesar.');
            $this->newLine();
            $this->line('  php artisan biable:recargar --mes=6 --anio=2026    (un período)');
            $this->line('  php artisan biable:recargar --anio=2026            (todo un año)');
            $this->line('  php artisan biable:recargar --id=12                (una carga concreta)');
            $this->line('  php artisan biable:recargar --todos                (todo el historial)');
            return null;
        }

        if ($mes !== null && ($mes < 1 || $mes > 12)) {
            $this->error('El mes debe estar entre 1 y 12.');
            return null;
        }

        $q = CargaFinanciera::query();

        if ($id)   $q->where('id', $id);
        if ($mes)  $q->where('mes', $mes);
        if ($anio) $q->where('anio', $anio);

        return $q->orderBy('anio')->orderBy('mes')->get();
    }
}