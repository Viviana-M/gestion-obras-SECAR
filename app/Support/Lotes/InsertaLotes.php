<?php

namespace App\Support\Lotes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inserción por lotes que, cuando algo falla, LOCALIZA la fila culpable y deja en el log
 * su número y contenido, y lanza un mensaje entendible (no un error técnico de base de datos).
 */
trait InsertaLotes
{
    /**
     * Inserta $datos en $tabla. Si el INSERT masivo falla, reintenta fila por fila para ubicar
     * la culpable, la registra en el log (número + contenido) y lanza un mensaje claro.
     *
     * @param  array<int, array<string,mixed>>  $datos        filas a insertar
     * @param  array<int, int|string>           $filasOrigen  número de fila de origen de cada dato
     */
    protected function insertarLoteSeguro(string $tabla, array $datos, array $filasOrigen): void
    {
        if (empty($datos)) {
            return;
        }

        try {
            DB::table($tabla)->insert($datos);

            return;
        } catch (\Throwable $bulk) {
            // El INSERT masivo falló: encuentra exactamente qué fila lo rompe.
            foreach (array_values($datos) as $k => $fila) {
                try {
                    DB::table($tabla)->insert($fila);
                } catch (\Throwable $e) {
                    $num = $filasOrigen[$k] ?? '?';
                    Log::error("Carga por lotes: la fila {$num} no se pudo guardar en {$tabla}", [
                        'fila'      => $num,
                        'tabla'     => $tabla,
                        'contenido' => $fila,
                        'error'     => $e->getMessage(),
                        'archivo'   => $e->getFile(),
                        'linea'     => $e->getLine(),
                    ]);

                    throw new \RuntimeException(
                        "Error en la fila {$num}: ".$this->mensajeDeDatos($e)." (al guardar en {$tabla}).",
                        0, $e
                    );
                }
            }

            // Si una por una TODAS entraron, el problema del INSERT masivo no era el dato
            // (p. ej. tamaño de paquete): se recupera insertando fila por fila y se deja aviso.
            Log::warning("Carga por lotes: el INSERT masivo en {$tabla} falló pero cada fila entró por separado", [
                'tabla' => $tabla, 'error' => $bulk->getMessage(),
            ]);
        }
    }

    /**
     * Registra en el log una fila que reventó durante el MAPEO (antes de insertar) y lanza un
     * mensaje entendible que apunta al número de fila.
     *
     * @param  array<int,mixed>  $contenido
     */
    protected function fallaDeFila(int|string $num, array $contenido, \Throwable $e): never
    {
        Log::error("Carga por lotes: la fila {$num} no se pudo procesar", [
            'fila'      => $num,
            'contenido' => $contenido,
            'error'     => $e->getMessage(),
            'archivo'   => $e->getFile(),
            'linea'     => $e->getLine(),
        ]);

        throw new \RuntimeException("Error en la fila {$num}: ".$this->mensajeDeDatos($e), 0, $e);
    }

    /** Traduce errores frecuentes (base de datos o mapeo) a un mensaje entendible en español. */
    protected function mensajeDeDatos(\Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'Data too long') || str_contains($m, 'value too long') || str_contains($m, 'String data, right truncated')
                => 'un dato es más largo de lo que admite la columna (revisa textos demasiado largos).',
            str_contains($m, 'Incorrect integer') || str_contains($m, 'Incorrect decimal')
                || str_contains($m, 'Out of range') || str_contains($m, 'Numeric value out of range')
                || str_contains($m, 'Incorrect DOUBLE')
                => 'un número tiene un formato inválido o es demasiado grande.',
            str_contains($m, 'Incorrect date') || str_contains($m, 'Incorrect datetime') || str_contains($m, 'Invalid datetime')
                => 'una fecha tiene un formato inválido.',
            str_contains($m, 'cannot be null') || str_contains($m, 'NOT NULL') || str_contains($m, 'null value in column')
                => 'falta un dato obligatorio (una celda requerida viene vacía).',
            str_contains($m, 'Allowed memory size') || str_contains($m, 'out of memory')
                => 'el lote consumió demasiada memoria; se reintentará con un bloque más pequeño.',
            default => $m,
        };
    }
}
