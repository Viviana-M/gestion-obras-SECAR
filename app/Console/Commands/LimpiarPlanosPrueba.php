<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Distribucion;
use App\Models\DistribucionVersion;
use App\Models\AplicacionCosto;

class LimpiarPlanosPrueba extends Command
{
    protected $signature = 'planos:limpiar {--force : Ejecutar sin pedir confirmación}';

    protected $description = 'Borra los planos de distribución de prueba (borradores, envíos, versiones y líneas aplicadas). NO toca datos financieros, estados de obra ni observaciones.';

    public function handle(): int
    {
        $nPlanos    = Distribucion::count();
        $nVersiones = DistribucionVersion::count();
        $nLineas    = AplicacionCosto::count();

        if ($nPlanos === 0 && $nVersiones === 0 && $nLineas === 0) {
            $this->info('No hay planos que borrar. Ya está todo limpio.');
            return self::SUCCESS;
        }

        $this->warn('Se van a borrar:');
        $this->line("  - {$nPlanos} planos de distribución");
        $this->line("  - {$nVersiones} versiones de trazabilidad");
        $this->line("  - {$nLineas} líneas de costo aplicado");
        $this->line('');
        $this->info('NO se tocan: registros financieros, estados de obra ni observaciones.');
        $this->line('');

        if (!$this->option('force') && !$this->confirm('¿Seguro que quieres borrar todo esto? Esta acción no se puede deshacer.')) {
            $this->info('Cancelado. No se borró nada.');
            return self::SUCCESS;
        }

        // Orden: primero las hijas, luego las principales
        AplicacionCosto::query()->delete();
        DistribucionVersion::query()->delete();
        Distribucion::query()->delete();

        $this->info('✔ Listo. Planos de prueba borrados. Puedes empezar desde cero.');
        return self::SUCCESS;
    }
}