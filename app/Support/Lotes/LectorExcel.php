<?php

namespace App\Support\Lotes;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lectura de Excel por VENTANAS de filas, pensada para archivos grandes que no caben en
 * una sola petición. Usa listWorksheetInfo() (que NO carga celdas) para contar filas, y un
 * read filter para leer solo el bloque de filas que toca en cada lote.
 */
class LectorExcel
{
    /**
     * Info barata de una hoja SIN cargar celdas: nombre real, total de filas y última columna.
     *
     * @return array{nombre:string, total_filas:int, ultima_columna:string}
     */
    public function infoHoja(string $rutaAbsoluta, ?string $hoja = null): array
    {
        $reader = IOFactory::createReaderForFile($rutaAbsoluta);
        $reader->setReadDataOnly(true);
        $infos = $reader->listWorksheetInfo($rutaAbsoluta);

        $info = null;
        if ($hoja !== null) {
            foreach ($infos as $i) {
                if (($i['worksheetName'] ?? null) === $hoja) {
                    $info = $i;
                    break;
                }
            }
        }
        $info ??= $infos[0] ?? null;

        if ($info === null) {
            throw new \RuntimeException('El archivo no tiene hojas legibles.');
        }

        return [
            'nombre'         => (string) ($info['worksheetName'] ?? ''),
            'total_filas'    => (int) ($info['totalRows'] ?? 0),
            'ultima_columna' => (string) ($info['lastColumnLetter'] ?? 'A'),
        ];
    }

    /**
     * Filas de datos (total de la hoja menos las filas de encabezado/preámbulo).
     */
    public function contarFilasDatos(string $rutaAbsoluta, ?string $hoja, int $filaEncabezado): int
    {
        $total = $this->infoHoja($rutaAbsoluta, $hoja)['total_filas'];
        return max(0, $total - $filaEncabezado);
    }

    /**
     * Lee las filas [desde, hasta] (1-based, worksheet) de una hoja, indexadas por offset de
     * columna (0-based). Opcionalmente limita las columnas cargadas (por letra) para acelerar.
     *
     * @param  array<string,bool>|null  $letras
     * @return array<int, array<int, mixed>>
     */
    public function leerVentana(
        string $rutaAbsoluta,
        ?string $hoja,
        int $desde,
        int $hasta,
        ?array $letras,
        string $ultimaColumna,
    ): array {
        $reader = IOFactory::createReaderForFile($rutaAbsoluta);
        $reader->setReadDataOnly(true);
        if ($hoja !== null && method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$hoja]);
        }
        $reader->setReadFilter(new VentanaReadFilter($desde, $hasta, $letras));

        $ss    = $reader->load($rutaAbsoluta);
        $sheet = $hoja !== null ? $ss->getSheetByName($hoja) : $ss->getSheet(0);
        if ($sheet === null) {
            $sheet = $ss->getSheet(0);
        }

        $rango = 'A'.$desde.':'.$ultimaColumna.$hasta;
        // rangeToArray(..., $nullValue=null, $calculateFormulas=true, $formatData=false, $returnCellRef=false)
        $filas = $sheet->rangeToArray($rango, null, true, false, false);

        $ss->disconnectWorksheets();
        unset($ss);

        return $filas;
    }
}
