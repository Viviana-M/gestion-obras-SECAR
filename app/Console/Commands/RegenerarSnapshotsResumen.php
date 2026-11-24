<?php

namespace App\Console\Commands;

use App\Http\Controllers\Operativo\DistribucionCostosController;
use App\Models\Distribucion;
use App\Models\DistribucionVersion;
use Illuminate\Console\Command;

/**
 * Regenera el snapshot congelado del resumen de las versiones de distribución (la "Trazabilidad
 * del plano"). Se usa después de corregir el cálculo del resumen (p. ej. incluir provisión y mano
 * de obra): las versiones ya guardadas conservan su snapshot viejo, así que su trazabilidad sigue
 * mostrando el total incompleto. Este comando recalcula el snapshot EN SITIO con la lógica actual
 * (armarSnapshotResumen → construirResumen), sin crear versiones nuevas ni tocar la distribución.
 *
 * Ejemplos:
 *   php artisan distribucion:regenerar-snapshots --dry-run
 *   php artisan distribucion:regenerar-snapshots --mes=10 --anio=2026 --departamento=instalaciones
 *   php artisan distribucion:regenerar-snapshots --id=123
 */
class RegenerarSnapshotsResumen extends Command
{
    protected $signature = 'distribucion:regenerar-snapshots
        {--id= : ID de una DistribucionVersion puntual a regenerar}
        {--mes= : Filtrar por mes de la distribución}
        {--anio= : Filtrar por año de la distribución}
        {--departamento= : Filtrar por departamento (mantenimiento|instalaciones)}
        {--dry-run : Solo muestra qué se regeneraría, sin guardar}';

    protected $description = 'Regenera el snapshot del resumen de las versiones (trazabilidad) con el cálculo actual (incluye provisión y MO).';

    public function handle(): int
    {
        $ctrl = app(DistribucionCostosController::class);
        $dry  = (bool) $this->option('dry-run');

        $query = DistribucionVersion::query()->orderBy('id');

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        } else {
            // Filtra por la distribución asociada (mes/anio/departamento).
            $mes  = $this->option('mes');
            $anio = $this->option('anio');
            $dep  = $this->option('departamento');
            if ($mes !== null || $anio !== null || $dep !== null) {
                $distIds = Distribucion::query()
                    ->when($mes !== null, fn ($q) => $q->where('mes', (int) $mes))
                    ->when($anio !== null, fn ($q) => $q->where('anio', (int) $anio))
                    ->when($dep !== null, fn ($q) => $q->where('departamento', $dep))
                    ->pluck('id');
                $query->whereIn('distribucion_id', $distIds);
            }
        }

        $versiones = $query->get();
        if ($versiones->isEmpty()) {
            $this->warn('No hay versiones que coincidan con el filtro.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '').'Regenerando '.$versiones->count().' snapshot(s)…');

        $ok = 0; $saltadas = 0;
        foreach ($versiones as $v) {
            $dist = Distribucion::find($v->distribucion_id);
            if (! $dist) {
                $this->line("  · v{$v->id}: sin distribución asociada → se salta");
                $saltadas++;
                continue;
            }

            $nuevo = $ctrl->armarSnapshotResumen($dist);
            $antes = (float) ($v->snapshot['todo']['costo_total'] ?? 0);
            $ahora = (float) ($nuevo['todo']['costo_total'] ?? 0);

            $this->line(sprintf(
                '  · v%d (%s %s/%s): costo_total %s → %s',
                $v->id, $dist->departamento, $dist->mes, $dist->anio,
                number_format($antes, 0, ',', '.'), number_format($ahora, 0, ',', '.')
            ));

            if (! $dry) {
                $v->snapshot = $nuevo;
                $v->save();
            }
            $ok++;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '')."Listo: {$ok} regenerada(s)".($saltadas ? ", {$saltadas} saltada(s)" : '').'.');
        if ($dry) {
            $this->comment('Sin cambios guardados (dry-run). Quita --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }
}
