<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ResumenDistribucionExport implements FromArray, WithEvents, WithTitle, WithColumnWidths
{
    public function __construct(
        private array $tabla,
        private array $todo,
        private array $tipos,
        private array $categorias,
        private string $periodo
    ) {}

    public function title(): string
    {
        // El título de la hoja no admite algunos caracteres; lo dejamos simple.
        return 'Resumen';
    }

    // Ancho de columnas
    public function columnWidths(): array
    {
        $anchos = ['A' => 20];
        $col = 'B';
        // por cada tipo: valor + part.  y al final total + part.
        $totalCols = (count($this->tabla) + 1) * 2;
        for ($i = 0; $i < $totalCols; $i++) {
            $anchos[$col] = 16;
            $col++;
        }
        return $anchos;
    }

    public function array(): array
    {
        $fmt   = fn($n) => number_format((float) $n, 0, ',', '.');
        $pctFn = fn($p, $t) => ($t != 0) ? number_format($p / $t * 100, 1, ',', '.') . '%' : '—';

        // Encabezado
        $cab = ['ÍTEM'];
        foreach ($this->tabla as $tk => $t) {
            $cab[] = mb_strtoupper($this->tipos[$tk] ?? $tk);
            $cab[] = 'PART.';
        }
        $cab[] = 'TOTAL';
        $cab[] = 'PART.';

        $filas = [$cab];

        // INGRESO
        $fila = ['INGRESO'];
        foreach ($this->tabla as $t) { $fila[] = $fmt($t['ingreso']); $fila[] = ''; }
        $fila[] = $fmt($this->todo['ingreso']); $fila[] = '';
        $filas[] = $fila;

        // Categorías
        foreach ($this->categorias as $ck => $cl) {
            $fila = [$ck];
            foreach ($this->tabla as $t) {
                $fila[] = $fmt($t['cat'][$ck] ?? 0);
                $fila[] = $pctFn($t['cat'][$ck] ?? 0, $t['costo_total']);
            }
            $fila[] = $fmt($this->todo['cat'][$ck] ?? 0);
            $fila[] = $pctFn($this->todo['cat'][$ck] ?? 0, $this->todo['costo_total']);
            $filas[] = $fila;
        }

        // TOTAL COSTO
        $fila = ['TOTAL COSTO'];
        foreach ($this->tabla as $t) {
            $fila[] = $fmt($t['costo_total']);
            $fila[] = $t['costo_total'] != 0 ? '100%' : '—';
        }
        $fila[] = $fmt($this->todo['costo_total']); $fila[] = '100%';
        $filas[] = $fila;

        // MC ($)
        $fila = ['MC ($)'];
        foreach ($this->tabla as $t) { $fila[] = $fmt($t['mc_pesos']); $fila[] = ''; }
        $fila[] = $fmt($this->todo['mc_pesos']); $fila[] = '';
        $filas[] = $fila;

        // MC %
        $fila = ['MC %'];
        foreach ($this->tabla as $t) { $fila[] = $t['mc_pct'] === null ? '—' : $t['mc_pct'] . '%'; $fila[] = ''; }
        $fila[] = $this->todo['mc_pct'] === null ? '—' : $this->todo['mc_pct'] . '%'; $fila[] = '';
        $filas[] = $fila;

        return $filas;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $numCols = (count($this->tabla) + 1) * 2 + 1; // ÍTEM + (tipos+total)*2
                $ultCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($numCols);

                $numFilasCat = count($this->categorias);
                $filaIngreso    = 2;
                $filaCatIni     = 3;
                $filaCatFin     = 2 + $numFilasCat;
                $filaTotalCosto = $filaCatFin + 1;
                $filaMcPesos    = $filaTotalCosto + 1;
                $filaMcPct      = $filaMcPesos + 1;

                // Encabezado: fondo azul, texto blanco, centrado
                $sheet->getStyle("A1:{$ultCol}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B3F6E']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // INGRESO: fondo azul claro
                $sheet->getStyle("A{$filaIngreso}:{$ultCol}{$filaIngreso}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D6E4F7']],
                ]);

                // Categorías: fondo verde muy claro
                $sheet->getStyle("A{$filaCatIni}:{$ultCol}{$filaCatFin}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDF4']],
                ]);

                // TOTAL COSTO: fondo verde
                $sheet->getStyle("A{$filaTotalCosto}:{$ultCol}{$filaTotalCosto}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BBF7D0']],
                ]);

                // MC ($) y MC %: negrita
                $sheet->getStyle("A{$filaMcPesos}:{$ultCol}{$filaMcPct}")->getFont()->setBold(true);
                $sheet->getStyle("A{$filaMcPct}:{$ultCol}{$filaMcPct}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
                ]);

                // Valores a la derecha (de la B en adelante)
                $sheet->getStyle("B1:{$ultCol}{$filaMcPct}")
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // Bordes finos a toda la tabla
                $sheet->getStyle("A1:{$ultCol}{$filaMcPct}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
                ]);
            },
        ];
    }
}