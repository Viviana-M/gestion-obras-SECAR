<?php

namespace App\Support\Lotes;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Read filter que carga SOLO una ventana de filas [desde, hasta] y, opcionalmente, solo
 * ciertas columnas (por su letra). Así cada lote reabre el Excel pero parsea muy pocas
 * celdas, y ninguna petición se demora lo suficiente para que el servidor la corte.
 */
class VentanaReadFilter implements IReadFilter
{
    /** @param array<string,bool>|null $letras */
    public function __construct(
        private int $desde,
        private int $hasta,
        private ?array $letras = null,
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($row < $this->desde || $row > $this->hasta) {
            return false;
        }
        if ($this->letras === null) {
            return true;
        }
        return isset($this->letras[$columnAddress]);
    }
}
