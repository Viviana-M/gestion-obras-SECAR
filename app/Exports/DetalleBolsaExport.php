<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Detalle de Otros Costos por cuenta (tabla plana de "Ver detalle por cuenta"), formateado y listo
 * para imprimir: encabezado azul SECAR, fecha/hora de extracción, totales, pie de impresión, orientación
 * horizontal ajustada al ancho, fila de encabezado repetida en cada página y encabezado congelado.
 *
 * Mantiene el mismo cruce/orden del detalle (por documento, saldos reales tras el cruce FIFO por
 * (U.N., cuenta)); el controlador ya filtró las líneas en $0 salvo "mostrar lo ya cruzado".
 */
class DetalleBolsaExport implements FromArray, WithTitle, WithEvents
{
    private const AZUL   = '1B3F6E'; // azul SECAR
    private const AZUL2  = '2A5C8A'; // azul subtítulo
    private const GRIS   = 'EEF1F6';
    private const BORDE  = 'D4DBE6';

    /** Fila (1-indexada) donde arranca la tabla: título, subtítulo, extracción, blanco, encabezado. */
    private const FILA_ENCABEZADO = 5;

    /**
     * @param array<int, array<int, mixed>> $filas  solo datos (13 columnas), sin encabezado ni totales.
     * @param array{saldo:float,distribuir:float,queda:float} $totales
     */
    public function __construct(
        private string $titulo,
        private string $subtitulo,
        private array $headers,
        private array $filas,
        private array $totales,
        private int $lineasConSaldo,
        private string $usuario,
        private \DateTimeInterface $extraidoEn,
    ) {}

    public function title(): string
    {
        return 'Otros Costos';
    }

    public function array(): array
    {
        $fechaHora = $this->extraidoEn->format('d/m/Y H:i');

        $grid = [
            [$this->titulo],
            [$this->subtitulo],
            ['Extraído: '.$fechaHora.'   ·   Líneas con saldo: '.$this->lineasConSaldo],
            [''],               // fila en blanco (un string vacío para que NO se colapse la fila)
            $this->headers,     // encabezado de columnas (FILA_ENCABEZADO)
        ];

        foreach ($this->filas as $f) {
            $grid[] = array_values($f);
        }

        // Fila de totales al final.
        $grid[] = [
            'TOTALES', '', '', '', '', '', '', '', '',
            round($this->totales['saldo'], 2),
            round($this->totales['distribuir'], 2),
            round($this->totales['queda'], 2),
            '',
        ];

        return $grid;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $hEnc    = self::FILA_ENCABEZADO;                 // fila de encabezado de columnas
                $ultCol  = $sheet->getHighestColumn();            // 'M' (13 columnas)
                $primData = $hEnc + 1;
                $ultData  = $primData + count($this->filas) - 1;
                $filaTot  = $ultData + (empty($this->filas) ? 1 : 1); // la fila siguiente a los datos
                if (empty($this->filas)) {
                    $ultData = $hEnc;        // sin datos: totales justo bajo el encabezado
                    $filaTot = $hEnc + 1;
                }

                // ── Banda de título (azul SECAR, texto blanco, negrita) ──
                $sheet->mergeCells('A1:'.$ultCol.'1');
                $sheet->mergeCells('A2:'.$ultCol.'2');
                $sheet->mergeCells('A3:'.$ultCol.'3');

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL);
                $sheet->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(1)->setRowHeight(26);

                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL2);
                $sheet->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(2)->setRowHeight(18);

                $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('6B7686');

                // ── Encabezado de columnas (azul, blanco, negrita, centrado, con borde) ──
                $rangoEnc = 'A'.$hEnc.':'.$ultCol.$hEnc;
                $sheet->getStyle($rangoEnc)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($rangoEnc)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL);
                $sheet->getStyle($rangoEnc)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension($hEnc)->setRowHeight(28);

                // ── Datos: bordes suaves y formato de miles en Saldo (J), A distribuir (K), Queda (L) ──
                if (! empty($this->filas)) {
                    $rangoDatos = 'A'.$primData.':'.$ultCol.$ultData;
                    $sheet->getStyle($rangoDatos)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDE);
                    $sheet->getStyle('J'.$primData.':L'.$ultData)->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle('J'.$primData.':L'.$ultData)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                    // Filas alternas (cebra) para legibilidad en impresión.
                    for ($r = $primData; $r <= $ultData; $r++) {
                        if (($r - $primData) % 2 === 1) {
                            $sheet->getStyle('A'.$r.':'.$ultCol.$r)->getFill()
                                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F6F9FD');
                        }
                    }
                }

                // ── Fila de totales (negrita, fondo gris, formato de miles) ──
                $rangoTot = 'A'.$filaTot.':'.$ultCol.$filaTot;
                $sheet->getStyle($rangoTot)->getFont()->setBold(true);
                $sheet->getStyle($rangoTot)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS);
                $sheet->getStyle($rangoTot)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::AZUL);
                $sheet->getStyle('J'.$filaTot.':L'.$filaTot)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('J'.$filaTot.':L'.$filaTot)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // ── Anchos de columna ──
                $anchos = ['A' => 12, 'B' => 11, 'C' => 20, 'D' => 24, 'E' => 12, 'F' => 22,
                           'G' => 13, 'H' => 26, 'I' => 14, 'J' => 14, 'K' => 14, 'L' => 14, 'M' => 30];
                foreach ($anchos as $col => $w) {
                    $sheet->getColumnDimension($col)->setWidth($w);
                }

                // ── Congelar encabezado en pantalla (todo lo de arriba del primer dato) ──
                $sheet->freezePane('A'.$primData);

                // ── Configuración de impresión ──
                $ps = $sheet->getPageSetup();
                $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $ps->setPaperSize(PageSetup::PAPERSIZE_A4);
                $ps->setFitToWidth(1);
                $ps->setFitToHeight(0);           // alto libre: cuantas páginas haga falta
                $ps->setRowsToRepeatAtTopByStartAndEnd($hEnc, $hEnc); // el encabezado se repite en cada página
                $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.3)->setRight(0.3);

                // ── Pie de impresión: "Extraído por: … · fecha/hora" (izq) y "Página X de Y" (der) ──
                $pie = 'Extraído por: '.$this->usuario.'   ·   '.$this->extraidoEn->format('d/m/Y H:i');
                $sheet->getHeaderFooter()->setOddFooter('&L&8'.$this->escapar($pie).'&R&8Página &P de &N');
                $sheet->getHeaderFooter()->setEvenFooter('&L&8'.$this->escapar($pie).'&R&8Página &P de &N');
            },
        ];
    }

    /** Escapa '&' para que no se interprete como código de encabezado/pie de PhpSpreadsheet. */
    private function escapar(string $s): string
    {
        return str_replace('&', '&&', $s);
    }
}
