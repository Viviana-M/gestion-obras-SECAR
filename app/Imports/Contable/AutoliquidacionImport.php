<?php

namespace App\Imports\Contable;

use App\Models\AutoliquidacionAporte;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importa la planilla PILA por posición de columna (orden fijo), en chunks y por
 * lotes (como el importador BIABLE). El período (mes/anio) se fija por fuera y se
 * aplica a todas las filas; la fecha real de cada fila se guarda tal cual.
 *
 * Columnas (0-index):
 *  0 ID Cuenta | 1 Cuenta contable | 2 Id. Tercero Mov (cédula) | 3 Razon Social |
 *  4 Id. U.N. Mov | 5 Fecha | 6 Descripción UN | 7 Descripción Codigo PILA |
 *  8 Empleado (redundante) | 9 Nombre del empl (redundante) |
 *  10 Aporte del empl | 11 Aporte empresa | 12 Real Descontado
 */
class AutoliquidacionImport implements ToModel, WithChunkReading, WithBatchInserts, WithStartRow
{
    public function __construct(
        private int $mes,
        private int $anio,
    ) {}

    public function startRow(): int
    {
        return 2; // saltar el encabezado
    }

    public function chunkSize(): int
    {
        return 2000;
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function model(array $row)
    {
        $cedula = trim((string) ($row[2] ?? ''));
        if ($cedula === '') {
            return null; // fila vacía / sin persona
        }

        return new AutoliquidacionAporte([
            'id_cuenta'       => $this->texto($row[0] ?? null),
            'cuenta_contable' => $this->texto($row[1] ?? null),
            'cedula'          => $cedula,
            'razon_social'    => $this->texto($row[3] ?? null),
            'un_codigo'       => $this->texto($row[4] ?? null),
            'un_descripcion'  => $this->texto($row[6] ?? null),
            'concepto_pila'   => $this->texto($row[7] ?? null),
            'aporte_empleado' => $this->num($row[10] ?? 0),
            'aporte_empresa'  => $this->num($row[11] ?? 0),
            'real_descontado' => $this->num($row[12] ?? 0),
            'fecha'           => $this->fecha($row[5] ?? null),
            'mes'             => $this->mes,
            'anio'            => $this->anio,
        ]);
    }

    private function texto($v): ?string
    {
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }

    private function num($v): float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }
        // Texto tipo "1.234,56" o "1,234.56": quitar separadores de miles.
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace([' ', '$'], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            // el último separador es el decimal
            $s = (strrpos($s, ',') > strrpos($s, '.'))
                ? str_replace('.', '', $s)               // 1.234,56
                : str_replace(',', '', $s);              // 1,234.56
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function fecha($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        // Serial de Excel (número de días).
        if (is_numeric($v)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d'] as $f) {
            $d = \DateTime::createFromFormat($f, $s);
            $err = \DateTime::getLastErrors();
            $ok = $err === false || (empty($err['warning_count']) && empty($err['error_count']));
            if ($d !== false && $ok) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }
}
