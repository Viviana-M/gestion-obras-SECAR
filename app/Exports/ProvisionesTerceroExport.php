<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Provisiones (cuenta 26) por obra: Código · Nombre · Saldo de provisión (Libro 1 = débito − crédito,
 * tras el cruce FIFO). Recibe el arreglo (encabezado + filas + total) desde el controlador.
 */
class ProvisionesTerceroExport implements FromArray, WithTitle, WithEvents, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Provisiones por obra';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Formato de miles ($) para la columna de saldo. */
    public function columnFormats(): array
    {
        return ['C' => '#,##0'];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $cols  = $sheet->getHighestColumn();
                $filas = $sheet->getHighestRow();

                $sheet->getStyle('A1:'.$cols.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:'.$cols.'1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1B3F6E');

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
