<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PlanoCerradasExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    private $lineas;
    private int $mes;
    private int $anio;

    public function __construct($lineas, int $mes, int $anio)
    {
        $this->lineas = $lineas;
        $this->mes    = $mes;
        $this->anio   = $anio;
    }

    public function array(): array
    {
        $filas = [];
        foreach ($this->lineas as $l) {
            $monto = round((float) $l->monto_aplicar, 2);
            // Débito a la 61 (reconoce el costo)
            $filas[] = [$l->codigo_proyecto, $l->cuenta_61, $l->nombre, 'DB', $monto, 0, $this->mes, $this->anio];
            // Crédito a la 14 (descarga el por aplicar)
            $filas[] = [$l->codigo_proyecto, $l->cuenta_14, $l->nombre, 'CR', 0, $monto, $this->mes, $this->anio];
        }
        return $filas;
    }

    public function headings(): array
    {
        return ['Proyecto', 'Cuenta', 'Concepto', 'Naturaleza', 'Debito', 'Credito', 'Mes', 'Anio'];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}