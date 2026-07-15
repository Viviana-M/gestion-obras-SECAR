<?php

namespace App\Console\Commands;

use App\Jobs\Financiero\ProcesarArchivoFinanciero;
use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use App\Models\SaldoBalance;
use Illuminate\Console\Command;

/**
 * Carga un archivo de BIABLE desde el disco, sin pasar por el formulario web.
 *
 * Útil para archivos grandes (la terminal no tiene el límite de subida de PHP) y para
 * cargar desde scripts. Hace exactamente lo mismo que la pantalla de Cierre de mes.
 *
 * Ejemplos:
 *   php artisan biable:cargar "C:/biable/junio.xlsx" --mes=6 --anio=2026
 *   php artisan biable:cargar ~/Descargas/mayo.xlsx --mes=5 --anio=2026 --force
 */
class BiableCargar extends Command
{
    protected $signature = 'biable:cargar
                            {archivo   : Ruta del Excel de BIABLE en tu disco}
                            {--mes=    : Mes del período (1-12)}
                            {--anio=   : Año del período}
                            {--force   : No pide confirmación}
                            {--cola    : Manda a la cola en vez de procesar aquí mismo}';

    protected $description = 'Carga un archivo de BIABLE desde el disco (equivale a la pantalla de Cierre de mes)';

    public function handle(): int
    {
        $origen = $this->argument('archivo');
        $mes    = (int) $this->option('mes');
        $anio   = (int) $this->option('anio');

        // ── Validaciones ──
        if (!is_file($origen)) {
            $this->error("No encuentro el archivo: {$origen}");
            $this->line('Usa la ruta completa, entre comillas si tiene espacios.');
            return self::FAILURE;
        }

        $ext = strtolower(pathinfo($origen, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            $this->error("Extensión no soportada: .{$ext}. Debe ser xlsx, xls o csv.");
            return self::FAILURE;
        }

        if ($mes < 1 || $mes > 12) {
            $this->error('Indica el mes con --mes=N (entre 1 y 12).');
            return self::FAILURE;
        }

        if ($anio < 2020) {
            $this->error('Indica el año con --anio=AAAA.');
            return self::FAILURE;
        }

        // ── Qué se va a reemplazar ──
        $regsActuales = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->count();
        $salActuales  = SaldoBalance::where('mes', $mes)->where('anio', $anio)->count();
        $periodo      = str_pad((string) $mes, 2, '0', STR_PAD_LEFT) . '/' . $anio;
        $peso         = round(filesize($origen) / 1048576, 1);

        $this->newLine();
        $this->line("  Archivo : " . basename($origen) . " ({$peso} MB)");
        $this->line("  Período : {$periodo}");
        $this->newLine();

        if ($regsActuales > 0 || $salActuales > 0) {
            $this->warn('Este período YA tiene datos y se van a REEMPLAZAR:');
            $this->line('    · ' . number_format($regsActuales, 0, ',', '.') . ' registros financieros');
            $this->line('    · ' . number_format($salActuales, 0, ',', '.') . ' saldos de balance');
            $this->newLine();
        }

        if (!$this->option('force') && !$this->confirm("¿Cargar {$periodo}?", false)) {
            $this->line('Cancelado. No se tocó nada.');
            return self::SUCCESS;
        }

        // ── Copiar al storage, igual que hace el formulario web ──
        $dir = storage_path('app/financiero');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $nombre = uniqid() . '.' . $ext;
        if (!copy($origen, $dir . DIRECTORY_SEPARATOR . $nombre)) {
            $this->error('No pude copiar el archivo a storage/app/financiero.');
            return self::FAILURE;
        }
        $ruta = 'financiero/' . $nombre;

        $carga = CargaFinanciera::create([
            'mes'              => $mes,
            'anio'             => $anio,
            'archivo_original' => basename($origen),
            'ruta_archivo'     => $ruta,
            'estado'           => 'procesando',
            'user_id'          => null,   // cargado por consola
        ]);

        // ── Procesar ──
        if ($this->option('cola')) {
            ProcesarArchivoFinanciero::dispatch($ruta, $mes, $anio, $carga->id);
            $this->info("Carga #{$carga->id} enviada a la cola.");
            $this->warn('Necesitas tener corriendo: php artisan queue:work');
            return self::SUCCESS;
        }

        $this->line('Procesando… (puede tardar varios minutos en archivos grandes)');
        $t0 = microtime(true);

        try {
            // dispatchSync: se procesa aquí mismo, sin necesidad de queue:work
            ProcesarArchivoFinanciero::dispatchSync($ruta, $mes, $anio, $carga->id);
        } catch (\Throwable $e) {
            // dispatchSync no dispara failed(): actualizamos el estado a mano.
            $carga->update(['estado' => 'error', 'error' => $e->getMessage()]);
            $this->newLine();
            $this->error('Falló la carga: ' . $e->getMessage());
            return self::FAILURE;
        }

        $seg  = round(microtime(true) - $t0, 1);
        $regs = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->count();
        $sal  = SaldoBalance::where('mes', $mes)->where('anio', $anio)->count();

        $this->newLine();
        $this->info("✓ {$periodo} cargado (carga #{$carga->id}, {$seg}s)");
        $this->line('    · ' . number_format($regs, 0, ',', '.') . ' registros financieros');
        $this->line('    · ' . number_format($sal, 0, ',', '.') . ' saldos de balance');

        if ($regs === 0) {
            $this->newLine();
            $this->warn('Ojo: quedaron 0 registros. Revisa que el Excel tenga las columnas que espera el importador.');
        }

        return self::SUCCESS;
    }
}