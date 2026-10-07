<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;

/**
 * Costo de mano de obra por obra (Sistema): recibe el arreglo (encabezado + filas).
 */
class ManoObraResumenExport implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'MO por obra';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Miles para la columna Monto (E). */
    public function columnFormats(): array
    {
        return ['E' => '#,##0'];
    }
}
