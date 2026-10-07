<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Reporte por obra de una distribución: lo aplicado en cada obra y cómo quedó
 * (ingreso, costo, margen del mes, inventario en tránsito y estado).
 * Recibe el arreglo ya armado (encabezado + filas + total) desde el controlador.
 */
class ReporteObrasExport implements FromArray, WithTitle, WithEvents, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Por obra';
    }

    public function array(): array
    {
        return $this->filas;
    }

    // Formato de miles ($) para las columnas de valores (los datos van como número crudo).
    public function columnFormats(): array
    {
        $fmt = '#,##0';
        return ['D' => $fmt, 'E' => $fmt, 'F' => $fmt, 'G' => $fmt, 'H' => $fmt,
                'I' => $fmt, 'J' => $fmt, 'L' => $fmt, 'M' => $fmt];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $cols  = $sheet->getHighestColumn();
                $filas = $sheet->getHighestRow();

                // Encabezado azul en negrita blanca.
                $sheet->getStyle('A1:'.$cols.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:'.$cols.'1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1B3F6E');

                // Última fila (TOTAL) en negrita con fondo suave.
                $sheet->getStyle('A'.$filas.':'.$cols.$filas)->getFont()->setBold(true);
                $sheet->getStyle('A'.$filas.':'.$cols.$filas)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');

                foreach (range('A', $cols) as $c) {
                    $sheet->getColumnDimension($c)->setAutoSize(true);
                }
            },
        ];
    }
}
