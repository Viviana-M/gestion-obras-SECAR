<?php

namespace App\Jobs\Financiero;

use App\Imports\Financiero\RegistroFinancieroImport;
use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ProcesarArchivoFinanciero implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
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
        RegistroFinanciero::where('mes', $this->mes)
            ->where('anio', $this->anio)
            ->delete();

      Excel::import(
    new RegistroFinancieroImport($this->mes, $this->anio),
    storage_path('app/' . $this->rutaArchivo)
);

        CargaFinanciera::where('id', $this->cargaId)->update([
            'estado'    => 'completado',
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