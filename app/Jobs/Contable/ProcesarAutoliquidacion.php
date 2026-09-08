<?php

namespace App\Jobs\Contable;

use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Procesa la planilla de autoliquidación EN SEGUNDO PLANO (igual que el cierre financiero).
 *
 * La importación de miles de filas no cabe bien en una petición web (memoria/tiempo del servidor
 * o del túnel), así que la carga solo mueve el archivo y encola este job; aquí se hace el trabajo
 * pesado sin límites de la web. El período (mes/anio) y el mapa de columnas ya vienen resueltos
 * desde el controlador (lectura liviana del encabezado).
 */
class ProcesarAutoliquidacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    /**
     * @param  array<string,int>  $mapa  campo canónico → índice de columna
     */
    public function __construct(
        private string $rutaArchivo,
        private int $mes,
        private int $anio,
        private array $mapa,
    ) {}

    public function handle(): void
    {
        // El log de queries se come la memoria en importaciones largas.
        DB::connection()->disableQueryLog();

        $archivo = storage_path('app/'.$this->rutaArchivo);
        if (! is_file($archivo)) {
            throw new \RuntimeException("No encuentro el archivo: {$archivo}");
        }

        // Recargar REEMPLAZA el período: se borra y se vuelve a insertar.
        AutoliquidacionAporte::where('mes', $this->mes)->where('anio', $this->anio)->delete();

        Excel::import(new AutoliquidacionImport($this->mes, $this->anio, $this->mapa), $archivo);
    }
}
