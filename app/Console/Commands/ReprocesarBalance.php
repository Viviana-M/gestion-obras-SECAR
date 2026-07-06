<?php

namespace App\Console\Commands;

use App\Imports\Financiero\BalanceImport;
use App\Models\CargaFinanciera;
use App\Models\SaldoBalance;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ReprocesarBalance extends Command
{
    protected $signature = 'balance:reprocesar
                            {--mes= : Procesar solo este mes (opcional)}
                            {--anio= : Procesar solo este año (opcional)}';

    protected $description = 'Reprocesa los Excel ya cargados para llenar saldos_balance (clases 1, 2, 3)';

    public function handle(): int
    {
        $query = CargaFinanciera::where('estado', 'completado')
            ->orderBy('anio')
            ->orderBy('mes');

        if ($this->option('mes'))  $query->where('mes', $this->option('mes'));
        if ($this->option('anio')) $query->where('anio', $this->option('anio'));

        $cargas = $query->get();

        if ($cargas->isEmpty()) {
            $this->warn('No hay cargas que reprocesar con esos filtros.');
            return self::SUCCESS;
        }

        $this->info("Cargas a reprocesar: {$cargas->count()}");
        $bar = $this->output->createProgressBar($cargas->count());
        $bar->start();

        $ok = 0;
        $fallidas = [];

        foreach ($cargas as $c) {
            $ruta = storage_path('app/' . $c->ruta_archivo);

            if (!is_file($ruta)) {
                $fallidas[] = "{$c->anio}-{$c->mes} (archivo no encontrado: {$c->ruta_archivo})";
                $bar->advance();
                continue;
            }

            try {
                // Repetible: borra lo de ese mes/año antes de reinsertar
                SaldoBalance::where('mes', $c->mes)->where('anio', $c->anio)->delete();

                Excel::import(new BalanceImport($c->mes, $c->anio), $ruta);
                $ok++;
            } catch (\Throwable $e) {
                $fallidas[] = "{$c->anio}-{$c->mes} ({$e->getMessage()})";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Listas: {$ok} de {$cargas->count()}");

        if (!empty($fallidas)) {
            $this->warn('Con problemas:');
            foreach ($fallidas as $f) $this->line('  - ' . $f);
        }

        return self::SUCCESS;
    }
}