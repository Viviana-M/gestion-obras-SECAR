<?php

namespace App\Imports\Contable;

use App\Models\AutoliquidacionAporte;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * Importa la planilla PILA por posición de columna (orden fijo), en chunks y por
 * lotes (como el importador BIABLE). El período (mes/anio) se fija por fuera (se toma
 * del nombre del archivo) y se aplica a todas las filas.
 *
 * Estructura NUEVA (11 columnas, 0-index):
 *  0 ID Cuenta | 1 Cuenta contable | 2 id. C.O. del Mov (centro operación) |
 *  3 Id. Tercero Mov (cédula) | 4 Razon Social | 5 Id. U.N. Mov |
 *  6 Descripción Codigo PILA | 7 Empleado (redundante) | 8 Nombre del empl (redundante) |
 *  9 NDC | 10 Aporte empresa
 *
 * Ya NO trae Descripción UN, Aporte del empl ni Real Descontado.
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
        $cedula = trim((string) ($row[3] ?? ''));
        if ($cedula === '') {
            return null; // fila vacía / sin persona
        }

        return new AutoliquidacionAporte([
            'id_cuenta'        => $this->texto($row[0] ?? null),
            'cuenta_contable'  => $this->texto($row[1] ?? null),
            'centro_operacion' => $this->texto($row[2] ?? null),
            'cedula'           => $cedula,
            'razon_social'     => $this->texto($row[4] ?? null),
            'un_codigo'        => $this->texto($row[5] ?? null),
            'concepto_pila'    => $this->texto($row[6] ?? null),
            'ndc'              => $this->texto($row[9] ?? null),
            'aporte_empresa'   => $this->num($row[10] ?? 0),
            'mes'              => $this->mes,
            'anio'             => $this->anio,
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
}
