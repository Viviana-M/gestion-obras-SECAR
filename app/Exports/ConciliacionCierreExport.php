<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;

/**
 * Conciliación cuenta 14 y 6 (Sistema vs ERP): recibe el arreglo (encabezado + filas).
 */
class ConciliacionCierreExport implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Conciliacion 14 y 6';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Miles para las columnas numéricas (C..N). */
    public function columnFormats(): array
    {
        $fmt = [];
        foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'] as $c) {
            $fmt[$c] = '#,##0';
        }
        return $fmt;
    }
}
