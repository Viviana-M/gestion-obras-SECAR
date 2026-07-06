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
use App\Models\SaldoBalance;
use App\Imports\Financiero\BalanceImport;
class ProcesarArchivoFinanciero implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;
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
// Segunda pasada: cuentas de balance (clases 1, 2, 3) a su tabla aparte
        SaldoBalance::where('mes', $this->mes)
            ->where('anio', $this->anio)
            ->delete();

        Excel::import(
            new BalanceImport($this->mes, $this->anio),
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