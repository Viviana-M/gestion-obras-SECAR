<?php

namespace App\Imports\Contable;

use App\Models\AutoliquidacionAporte;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importa la planilla PILA por posición de columna (orden fijo), en chunks y por lotes.
 * El período (mes/anio) se fija por fuera (se toma de la columna Fecha) y se aplica a
 * todas las filas.
 *
 * Estructura ESTÁNDAR (13 columnas, 0-index):
 *  0 ID Cuenta               → id_cuenta
 *  1 Cuenta contable         → cuenta_contable
 *  2 Id. Tercero Mov         → cedula
 *  3 Razon Social            → razon_social
 *  4 Id. U.N. Mov            → un_codigo
 *  5 Fecha                   → (período mes/anio)
 *  6 Descripción UN          → un_descripcion
 *  7 Descripción Codigo PILA → concepto_pila
 *  8 Empleado                → (no se guarda)
 *  9 Nombre del empl         → (no se guarda)
 * 10 Aporte del empl         → aporte_empleado
 * 11 Aporte empresa          → aporte_empresa
 * 12 Real Descontado         → real_descontado
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
            'id_cuenta'        => $this->texto($row[0] ?? null),
            'cuenta_contable'  => $this->texto($row[1] ?? null),
            'cedula'           => $cedula,
            'razon_social'     => $this->texto($row[3] ?? null),
            'un_codigo'        => $this->texto($row[4] ?? null),
            'fecha'            => self::parsearFecha($row[5] ?? null)?->format('Y-m-d'),
            'un_descripcion'   => $this->texto($row[6] ?? null),
            'concepto_pila'    => $this->texto($row[7] ?? null),
            'aporte_empleado'  => $this->num($row[10] ?? 0),
            'aporte_empresa'   => $this->num($row[11] ?? 0),
            'real_descontado'  => $this->num($row[12] ?? 0),
            'mes'              => $this->mes,
            'anio'            => $this->anio,
        ]);
    }

    /**
     * Interpreta la columna Fecha: serial de Excel (número), ISO "2026-04-30" o
     * "dd/mm/aaaa". Devuelve un Carbon o null si no se puede leer.
     */
    public static function parsearFecha($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v));
            } catch (\Throwable $e) {
                return null;
            }
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s)) {
            try {
                return Carbon::createFromFormat('d/m/Y', $s)->startOfDay();
            } catch (\Throwable $e) {
                // sigue al parseo genérico
            }
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable $e) {
            return null;
        }
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
}
