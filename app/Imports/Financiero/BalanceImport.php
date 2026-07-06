<?php

namespace App\Imports\Financiero;

use App\Models\SaldoBalance;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

class BalanceImport implements ToModel, WithHeadingRow, WithChunkReading, WithBatchInserts
{
    protected $mes;
    protected $anio;

    public function __construct($mes, $anio)
    {
        $this->mes  = $mes;
        $this->anio = $anio;
    }

    public function chunkSize(): int { return 500; }
    public function batchSize(): int { return 500; }

    public function model(array $row)
    {
        $cuenta = trim($row['cuenta'] ?? '');
        if ($cuenta === '') return null;

        // SOLO cuentas de balance: clases 1 (activo), 2 (pasivo), 3 (patrimonio)
        $clase = substr($cuenta, 0, 1);
        if (!in_array($clase, ['1', '2', '3'])) return null;

        $debito  = $this->limpiarNumero($row['debitos']  ?? 0);
        $credito = $this->limpiarNumero($row['creditos'] ?? 0);
        $movto   = $this->limpiarNumero($row['movto_libro2'] ?? 0);
        if ($movto == 0) $movto = $debito - $credito;
        if ($debito == 0 && $credito == 0) return null; // sin movimiento

        // NO aplicamos el filtro de prefijos de obra:
        // las cuentas globales (pasivo/patrimonio) vienen sin proyecto y deben entrar.
        $unidad       = trim($row['unidad_de_negocio'] ?? '');
        $nombreUnidad = trim($row['nombre_unidad_de_negocio'] ?? '');

        return new SaldoBalance([
            'cuenta_contable' => $cuenta,
            'descripcion'     => trim($row['nombre_auxiliar'] ?? ''),
            'clase'           => $clase,
            'codigo_proyecto' => $unidad ?: null,
            'nombre_proyecto' => $nombreUnidad ?: null,
            'valor_debito'    => $debito,
            'valor_credito'   => $credito,
            'movto'           => $movto,
            'periodo'         => trim($row['periodo'] ?? ''),
            'mes'             => $this->mes,
            'anio'            => $this->anio,
            'origen'          => 'biable',
        ]);
    }

    private function limpiarNumero($valor): float
    {
        if (is_numeric($valor)) return floatval($valor);
        $valor = str_replace(['$', '.', ' '], '', (string)$valor);
        $valor = str_replace(',', '.', $valor);
        return is_numeric($valor) ? floatval($valor) : 0;
    }
}