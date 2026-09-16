<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;

/**
 * Facturado del período agrupado por tipo de obra (Obras, Contratos, Reparaciones,
 * Garantías, Otros) más el total general.
 */
class FacturadoTipoExport implements FromArray, WithHeadings, WithTitle, WithColumnWidths
{
    /** @param array<int,array{label:string,total:float}> $filas */
    public function __construct(
        private array $filas,
        private float $total,
        private string $periodo,
    ) {}

    public function array(): array
    {
        $rows = [];
        foreach ($this->filas as $f) {
            $rows[] = [$f['label'], round((float) $f['total'], 2)];
        }
        $rows[] = ['TOTAL', round($this->total, 2)];
        return $rows;
    }

    public function headings(): array
    {
        return ['Tipo de obra', 'Total facturado ('.$this->periodo.')'];
    }

    public function columnWidths(): array
    {
        return ['A' => 24, 'B' => 26];
    }

    public function title(): string
    {
        return 'Facturado';
    }
}
