<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Utilidades comunes a los controladores que cargan archivos pesados por lotes:
 * guardar el archivo subido, elevar los límites de PHP y el tamaño de lote.
 */
trait ProcesaCargaPorLotes
{
    /** Filas que procesa cada petición AJAX (lote). */
    protected int $loteTam = 1000;

    /** Guarda el archivo subido en storage/app/<carpeta> y devuelve su ruta relativa. */
    protected function guardarArchivoLote(UploadedFile $archivo, string $carpeta): string
    {
        $dir = storage_path('app/'.$carpeta);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $nombre = uniqid().'.xlsx';
        $archivo->move($dir, $nombre);

        return $carpeta.'/'.$nombre;
    }

    /** Sube los límites de PHP para que ni el lote ni el proceso síncrono se corten. */
    protected function elevarLimites(): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }
}
