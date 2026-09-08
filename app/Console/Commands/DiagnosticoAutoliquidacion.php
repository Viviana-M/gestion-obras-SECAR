<?php

namespace App\Console\Commands;

use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Diagnóstico: corre el import de autoliquidación EN LA TERMINAL sobre el último archivo subido
 * (guardado en storage/app/autoliquidacion), para ver el resultado o el error exacto sin que
 * quede oculto en el procesamiento en segundo plano.
 *
 *   php artisan autoliq:diagnostico            (usa mes/anio del archivo, por la columna Fecha)
 *   php artisan autoliq:diagnostico 8 2026      (fuerza el período)
 */
class DiagnosticoAutoliquidacion extends Command
{
    protected $signature = 'autoliq:diagnostico {mes? : Mes (1-12)} {anio? : Año}';

    protected $description = 'Importa el último archivo de autoliquidación subido y muestra resultado o error';

    public function handle(): int
    {
        DB::connection()->disableQueryLog();

        $dir = storage_path('app/autoliquidacion');
        if (! is_dir($dir)) {
            $this->error("No existe la carpeta {$dir}. Sube primero la autoliquidación desde la web.");

            return 1;
        }

        $archivos = glob($dir.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        if (empty($archivos)) {
            $this->error("No hay archivos en {$dir}. Sube primero la autoliquidación desde la web.");

            return 1;
        }
        usort($archivos, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $ruta = $archivos[0];
        $this->info('Archivo: '.$ruta.'  ('.round(filesize($ruta) / 1024).' KB)');

        try {
            // Encabezado → mapa de columnas (por nombre).
            $reader = IOFactory::createReaderForFile($ruta);
            $reader->setReadDataOnly(true);
            $encabezado = $reader->load($ruta)->getSheet(0)->toArray(null, true, false, false)[0] ?? [];
            $mapa = AutoliquidacionImport::mapaColumnas($encabezado);
            $this->info('Columnas reconocidas: '.implode(', ', array_keys($mapa)));

            if (! isset($mapa['empleado'], $mapa['aporte_empresa'], $mapa['fecha'])) {
                $this->error('El archivo NO trae Empleado / Aporte empresa / Fecha reconocibles.');

                return 1;
            }

            // Período: de los argumentos o de la primera fecha válida.
            $mes = (int) ($this->argument('mes') ?: 0);
            $anio = (int) ($this->argument('anio') ?: 0);
            if (! $mes || ! $anio) {
                $filas = $reader->load($ruta)->getSheet(0)->toArray(null, true, false, false);
                foreach ($filas as $i => $f) {
                    if ($i === 0) {
                        continue;
                    }
                    $fc = AutoliquidacionImport::parsearFecha($f[$mapa['fecha']] ?? null);
                    if ($fc) {
                        $mes = (int) $fc->month;
                        $anio = (int) $fc->year;
                        break;
                    }
                }
            }
            if (! $mes || ! $anio) {
                $this->error('No pude determinar el período (columna Fecha vacía). Pásalo: php artisan autoliq:diagnostico 8 2026');

                return 1;
            }
            $this->info("Período: {$mes}/{$anio}");

            AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)->delete();
            Excel::import(new AutoliquidacionImport($mes, $anio, $mapa), $ruta);

            $n = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)->count();
            $tot = (float) AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)->sum('aporte_empresa');
            $this->info("OK ✅  filas={$n}  aporte_empresa=".number_format($tot, 0));

            return 0;
        } catch (\Throwable $e) {
            $this->error('FALLÓ ❌  '.get_class($e));
            $this->error($e->getMessage());
            $this->line('en '.$e->getFile().':'.$e->getLine());

            return 1;
        }
    }
}
