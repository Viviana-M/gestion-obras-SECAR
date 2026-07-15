<?php

namespace App\Jobs\Financiero;

use App\Imports\Financiero\MovimientoBiableImport;
use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use App\Models\SaldoBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Procesa un archivo de BIABLE.
 *
 * ANTES: leía el Excel DOS VECES (RegistroFinancieroImport + BalanceImport), cada una
 * recorriendo todas las filas del archivo para descartar la mayoría. Con WithChunkReading,
 * PhpSpreadsheet reabre y reparsea el archivo en CADA chunk, así que dos pasadas con
 * chunks de 500 sobre ~20.000 filas útiles significaban ~80 aperturas del Excel.
 *
 * AHORA: una sola pasada (MovimientoBiableImport) que reparte cada fila a la tabla que
 * corresponda, con inserts crudos por lotes y chunks de 2000.
 */
class ProcesarArchivoFinanciero implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries   = 1;

    protected $rutaArchivo;
    protected $mes;
    protected $anio;
    protected $cargaId;

    public function __construct($rutaArchivo, $mes, $anio, $cargaId)
    {
        $this->rutaArchivo = $rutaArchivo;
        $this->mes         = $mes;
        $this->anio        = $anio;
        $this->cargaId     = $cargaId;
    }

    public function handle(): void
    {
        // El log de queries se come la memoria en importaciones largas.
        DB::connection()->disableQueryLog();

        $archivo = storage_path('app/' . $this->rutaArchivo);

        if (!is_file($archivo)) {
            throw new \RuntimeException("No encuentro el archivo: {$archivo}");
        }

        // Reprocesar REEMPLAZA el período: se borra y se vuelve a insertar.
        RegistroFinanciero::where('mes', $this->mes)
            ->where('anio', $this->anio)
            ->delete();

        SaldoBalance::where('mes', $this->mes)
            ->where('anio', $this->anio)
            ->delete();

        // UNA sola lectura del Excel: el importador reparte cada fila a su tabla.
        $import = new MovimientoBiableImport($this->mes, $this->anio);
        Excel::import($import, $archivo);

        CargaFinanciera::where('id', $this->cargaId)->update([
            'estado'    => 'completado',
            'error'     => null,
            'registros' => RegistroFinanciero::where('mes', $this->mes)
                            ->where('anio', $this->anio)->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        CargaFinanciera::where('id', $this->cargaId)->update([
            'estado' => 'error',
            'error'  => $e->getMessage(),
        ]);
    }
}