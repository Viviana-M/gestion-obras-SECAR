<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Costo de seguridad social por persona: persona, cédula, UN, total Aporte empresa y una
 * columna por cada concepto PILA. Recibe el arreglo de personas (cada una con su desglose).
 */
class SeguridadSocialPersonaExport implements FromArray, WithTitle, WithEvents, WithColumnFormatting
{
    private array $conceptos;   // conceptos únicos (columnas dinámicas), ordenados
    private int $numColsMoney;  // # de columnas numéricas (total + conceptos)

    public function __construct(private array $personas, private float $total)
    {
        // Conceptos únicos, ordenados por aporte total descendente (para las columnas).
        $acum = [];
        foreach ($personas as $p) {
            foreach ($p['conceptos'] as $c) {
                $acum[$c['concepto']] = ($acum[$c['concepto']] ?? 0) + $c['aporte'];
            }
        }
        arsort($acum);
        $this->conceptos = array_keys($acum);
    }

    public function title(): string
    {
        return 'Por persona';
    }

    public function array(): array
    {
        $head = array_merge(['Persona', 'Cédula', 'Unidad de negocio', 'Total Aporte empresa'], $this->conceptos);
        $filas = [$head];

        foreach ($this->personas as $p) {
            $porConcepto = [];
            foreach ($p['conceptos'] as $c) {
                $porConcepto[$c['concepto']] = $c['aporte'];
            }
            $fila = [$p['nombre'], $p['cedula'], $p['un'], round($p['total'])];
            foreach ($this->conceptos as $con) {
                $v = $porConcepto[$con] ?? 0;
                $fila[] = $v > 0.005 ? round($v) : null; // 0 → celda vacía
            }
            $filas[] = $fila;
        }

        // Total general.
        $totalRow = ['TOTAL', '', '', round($this->total)];
        foreach ($this->conceptos as $con) {
            $s = 0.0;
            foreach ($this->personas as $p) {
                foreach ($p['conceptos'] as $c) {
                    if ($c['concepto'] === $con) $s += $c['aporte'];
                }
            }
            $totalRow[] = $s > 0.005 ? round($s) : null;
        }
        $filas[] = $totalRow;

        return $filas;
    }

    /** Formato de miles para el total y todas las columnas de concepto (D en adelante). */
    public function columnFormats(): array
    {
        $fmt = '#,##0';
        $cols = [];
        $ultima = 4 + count($this->conceptos); // D=4 (total) + conceptos
        for ($i = 4; $i <= $ultima; $i++) {
            $cols[Coordinate::stringFromColumnIndex($i)] = $fmt;
        }
        return $cols;
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
                $sheet->getStyle('A1:'.$cols.'1')->getAlignment()->setWrapText(true);

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
