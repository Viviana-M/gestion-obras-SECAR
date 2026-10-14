<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Reporte de obras con saldo abierto/parcial en la cuenta 14 (inventario en tránsito),
 * con todos los datos de la tarjeta y los márgenes pintados con el color del semáforo.
 * Recibe las filas ya armadas y un mapa de celdas a pintar (fila, columna, fondo, texto).
 */
class ReporteSaldos14Export implements FromArray, WithTitle, WithEvents, WithColumnFormatting
{
    /**
     * @param array $filas  encabezado + filas de datos.
     * @param array $pintar lista de ['fila'=>int, 'col'=>string, 'bg'=>hex, 'fg'=>hex].
     * @param array $moneyCols columnas con formato de miles.
     */
    public function __construct(private array $filas, private array $pintar, private array $moneyCols) {}

    public function title(): string
    {
        return 'Saldos cuenta 14';
    }

    public function array(): array
    {
        return $this->filas;
    }

    public function columnFormats(): array
    {
        $fmt = '#,##0';
        return array_fill_keys($this->moneyCols, $fmt);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $cols  = $sheet->getHighestColumn();

                // Encabezado azul, negrita, texto blanco.
                $sheet->getStyle('A1:'.$cols.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:'.$cols.'1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1B3F6E');
                $sheet->getStyle('A1:'.$cols.'1')->getAlignment()->setWrapText(true);

                // Celdas de margen pintadas con el color del semáforo.
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
