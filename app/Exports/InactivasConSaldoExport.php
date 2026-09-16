<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Obras marcadas inactivas en el maestro que aún tienen saldo en la cuenta 14. Recibe el
 * arreglo (encabezado + filas + total) desde el controlador.
 */
class InactivasConSaldoExport implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Inactivas con saldo';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Formato de miles para la columna Saldo cuenta 14. */
    public function columnFormats(): array
    {
        return ['D' => '#,##0'];
    }
}
