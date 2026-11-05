<?php

namespace App\Support\Lotes;

/**
 * Contrato de un importador que se procesa POR LOTES (ventanas de filas leídas del Excel
 * en varias peticiones). Cada importador (cierre, autoliquidación, movimiento) sabe:
 *  - reconocer el archivo y su período,  (preparar)
 *  - borrar el período que va a reemplazar,  (dentro de preparar)
 *  - transformar un bloque de filas crudas en inserciones,  (procesarFilas)
 *  - y armar el resumen final.  (resumen)
 *
 * "Filas crudas" = arreglo de filas, cada una indexada por el OFFSET de columna (0-based),
 * tal como las devuelve LectorExcel::leerVentana().
 */
interface ImportadorLotes
{
    public function tipo(): string;

    /**
     * Reconoce el archivo (encabezados, hoja, período) y BORRA los datos del período que
     * se va a reemplazar. Debe lanzar \RuntimeException con un mensaje claro si el archivo
     * no tiene el formato esperado.
     *
     * @param  array<string,mixed>  $contexto  datos externos (p. ej. mes/anio del formulario en el cierre)
     * @return array{mes:int,anio:int,meta:array<string,mixed>}
     */
    public function preparar(string $rutaAbsoluta, array $contexto = []): array;

    /**
     * Procesa un bloque de filas crudas y las inserta. Devuelve cuántas insertó y,
     * opcionalmente, contadores acumulables para el resumen (p. ej. filas saltadas).
     *
     * @param  array<int, array<int, mixed>>  $filas
     * @param  array<string,mixed>  $meta
     * @return array{insertadas:int, contadores?:array<string,mixed>}
     */
    public function procesarFilas(array $filas, array $meta, int $mes, int $anio): array;

    /**
     * Mensaje/resumen final para mostrar al usuario.
     *
     * @param  array<string,mixed>  $meta  incluye 'contadores' acumulados durante el proceso
     * @return array{mensaje:string, warning?:?string}
     */
    public function resumen(int $mes, int $anio, array $meta): array;

    /** Nombre de la hoja a leer (null = la primera). */
    public function hoja(array $meta): ?string;

    /** Fila (1-based) del encabezado; los datos empiezan en la siguiente. */
    public function filaEncabezado(array $meta): int;

    /**
     * Letras de columna a cargar para acelerar la lectura (null = todas). Útil en hojas
     * anchas (movimiento de almacén ~128 columnas).
     *
     * @return array<string,bool>|null
     */
    public function letras(array $meta): ?array;
}
