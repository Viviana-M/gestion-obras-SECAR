<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Cruce cuenta 14 vs tercero SECAR: recibe el arreglo (encabezado + filas + total) del controlador.
 */
class CruceSecarExport implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Cruce 14 vs SECAR';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Miles para las tres columnas de saldo (D, E, F). */
    public function columnFormats(): array
    {
        return ['D' => '#,##0', 'E' => '#,##0', 'F' => '#,##0'];
    }
}
