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

    /** Moneda con 2 decimales para Débito/Crédito/Saldo (D,E,F) y el cruce SECAR (G,H,I). */
    public function columnFormats(): array
    {
        $money = '"$" #,##0.00';
        return ['D' => $money, 'E' => $money, 'F' => $money, 'G' => $money, 'H' => $money, 'I' => $money];
    }
}
