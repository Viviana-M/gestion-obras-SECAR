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
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Procesa la planilla de autoliquidación EN SEGUNDO PLANO (igual que el cierre financiero).
 *
 * Toda la lectura del Excel ocurre AQUÍ (en el worker), no en la petición web: leer miles de
 * filas toma ~20s y en la web el navegador o el túnel cortan antes. La carga web solo guarda el
 * archivo y encola este job. Aquí se reconocen las columnas (por nombre), se detecta el período
 * (columna Fecha) y se importa reemplazando ese período.
 */
class ProcesarAutoliquidacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(
        private string $rutaArchivo,
    ) {}

    public function handle(): void
    {
        // El log de queries se come la memoria en importaciones largas.
        DB::connection()->disableQueryLog();

        $archivo = storage_path('app/'.$this->rutaArchivo);
        if (! is_file($archivo)) {
            throw new \RuntimeException("No encuentro el archivo: {$archivo}");
        }

        // Encabezado + filas para reconocer columnas y detectar el período.
        $reader = IOFactory::createReaderForFile($archivo);
        $reader->setReadDataOnly(true);
        $filas = $reader->load($archivo)->getSheet(0)->toArray(null, true, false, false);

        $mapa = AutoliquidacionImport::mapaColumnas($filas[0] ?? []);
        if (! isset($mapa['empleado'], $mapa['aporte_empresa'], $mapa['fecha'])) {
            Log::warning('Autoliquidación: el archivo no tiene el formato PILA (falta Empleado/Aporte empresa/Fecha).', [
                'archivo' => $this->rutaArchivo,
            ]);

            return;
        }

        [$mes, $anio] = $this->periodo($filas, $mapa['fecha']);
        if (! $mes || ! $anio) {
            Log::warning('Autoliquidación: no pude leer el período de la columna Fecha.', [
                'archivo' => $this->rutaArchivo,
            ]);

            return;
        }

        // Recargar REEMPLAZA el período: se borra y se vuelve a insertar.
        AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)->delete();
        Excel::import(new AutoliquidacionImport($mes, $anio, $mapa), $archivo);
    }

    /**
     * Primer par [mes, anio] legible de la columna Fecha; [null, null] si ninguna es válida.
     *
     * @return array{0:?int,1:?int}
     */
    private function periodo(array $filas, int $colFecha): array
    {
        foreach ($filas as $i => $f) {
            if ($i === 0) {
                continue;
            }
            $fc = AutoliquidacionImport::parsearFecha($f[$colFecha] ?? null);
            if ($fc) {
                return [(int) $fc->month, (int) $fc->year];
            }
        }

        return [null, null];
    }
}
