<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Reconciliación cuenta 14 (Sistema vs ERP): recibe el arreglo (encabezado + filas + total).
 */
class Recon14Export implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Reconciliacion 14';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Miles para Saldo ERP (C), Saldo sistema (D) y Diferencia (E). */
    public function columnFormats(): array
    {
        return ['C' => '#,##0', 'D' => '#,##0', 'E' => '#,##0'];
    }
}
