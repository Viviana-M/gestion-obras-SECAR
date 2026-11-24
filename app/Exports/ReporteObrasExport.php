<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Reporte por obra de una distribución: los componentes de costo del mes (propio, otros costos,
 * MO, provisión), el total distribuido, el costo total, el margen (que incluye MO y provisión) y
 * el saldo de inventario en tránsito. Recibe el arreglo ya armado (encabezado + filas + total),
 * el mapa de celdas a pintar con el semáforo y las columnas de dinero.
 */
class ReporteObrasExport implements FromArray, WithTitle, WithEvents, WithColumnFormatting
{
    /**
     * @param array $filas     encabezado + filas de datos + total.
     * @param array $pintar    lista de ['fila'=>int, 'col'=>string, 'bg'=>hex, 'fg'=>hex].
     * @param array $moneyCols columnas con formato de miles.
     */
    public function __construct(private array $filas, private array $pintar = [], private array $moneyCols = []) {}

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
        return array_fill_keys($this->moneyCols, '#,##0');
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
                $sheet->getStyle('A1:'.$cols.'1')->getAlignment()->setWrapText(true);

                // Última fila (TOTAL) en negrita con fondo suave.
                $sheet->getStyle('A'.$filas.':'.$cols.$filas)->getFont()->setBold(true);
                $sheet->getStyle('A'.$filas.':'.$cols.$filas)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');

                // Celdas de "Margen del mes (%)" pintadas con el color del semáforo.
                foreach ($this->pintar as $p) {
                    $ref = $p['col'].$p['fila'];
                    $sheet->getStyle($ref)->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($p['bg']);
                    $sheet->getStyle($ref)->getFont()->setBold(true)->getColor()->setRGB($p['fg']);
                }

                foreach (range('A', $cols) as $c) {
                    $sheet->getColumnDimension($c)->setAutoSize(true);
                }
            },
        ];
    }
}
