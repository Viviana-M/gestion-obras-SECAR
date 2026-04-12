<?php

namespace App\Imports\Financiero;

use App\Models\RegistroFinanciero;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;

class RegistroFinancieroImport implements ToModel, WithHeadingRow, WithChunkReading, WithBatchInserts
{
    protected $mes;
    protected $anio;

    private $prefijosValidos = ['O', 'GI', 'MOA', 'MOB', 'MOC', 'MO', 'C', 'R', 'GM'];

    public function __construct($mes, $anio)
    {
        $this->mes  = $mes;
        $this->anio = $anio;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function model(array $row)
    {
        $movto = $this->limpiarNumero($row['movto_libro2'] ?? 0);
        if ($movto == 0) {
            $debito  = $this->limpiarNumero($row['debitos']  ?? 0);
            $credito = $this->limpiarNumero($row['creditos'] ?? 0);
            $movto   = $debito - $credito;
        }
        if ($movto == 0) return null;

        $periodo = trim($row['periodo'] ?? '');
        if (str_ends_with((string)$periodo, '13')) return null;

        $unidad = trim($row['unidad_de_negocio'] ?? '');
        if (!$this->tienePrefijosValido($unidad)) return null;

        $nombreUnidad = trim($row['nombre_unidad_de_negocio'] ?? '');
        $unidadUnificada = $unidad && $nombreUnidad
            ? $unidad . ' - ' . $nombreUnidad
            : ($unidad ?: $nombreUnidad);
        if (str_contains($unidadUnificada, 'COM00099')) return null;

        $cuenta = trim($row['cuenta'] ?? '');
        $cuentaMayor = $this->clasificarCuenta($cuenta);

        $excluir = ['Activo', 'Pasivo', 'Patrimonio', 'No clasificado'];
        if (in_array($cuentaMayor, $excluir)) return null;

        $signo = (str_starts_with($cuenta, '1420') ||
                  str_starts_with($cuenta, '6130')) ? -1 : 1;

        $estadoER = (str_starts_with($cuenta, '14') ||
                     str_starts_with($cuenta, '61') ||
                     str_starts_with($cuenta, '4'))
            ? $movto * -1
            : $movto;

        return new RegistroFinanciero([
            'codigo_proyecto'  => $unidad,
            'nombre_proyecto'  => $nombreUnidad,
            'cuenta_contable'  => $cuenta,
            'descripcion'      => trim($row['nombre_auxiliar'] ?? ''),
            'valor_debito'     => $this->limpiarNumero($row['debitos']  ?? 0),
            'valor_credito'    => $this->limpiarNumero($row['creditos'] ?? 0),
            'movto_libro2'     => $movto,
            'cuenta_mayor'     => $cuentaMayor,
            'signo_contable'   => $signo,
            'estado_er'        => $estadoER,
            'unidad_unificada' => $unidadUnificada,
            'periodo'          => $periodo,
            'mes'              => $this->mes,
            'anio'             => $this->anio,
            'origen'           => 'biable',
        ]);
    }

    private function tienePrefijosValido($unidad): bool
    {
        foreach ($this->prefijosValidos as $prefijo) {
            if (str_starts_with($unidad, $prefijo)) return true;
        }
        return false;
    }

    private function clasificarCuenta($cuenta): string
    {
        if (str_starts_with($cuenta, '1420')) return 'Costos por aplicar';
        if (str_starts_with($cuenta, '6'))    return 'Costos aplicados';
        if (str_starts_with($cuenta, '1'))    return 'Activo';
        if (str_starts_with($cuenta, '2'))    return 'Pasivo';
        if (str_starts_with($cuenta, '3'))    return 'Patrimonio';
        if (str_starts_with($cuenta, '4'))    return 'Ingreso';
        if (str_starts_with($cuenta, '5'))    return 'Gasto';
        if (str_starts_with($cuenta, '7'))    return 'Costos';
        return 'No clasificado';
    }

    private function limpiarNumero($valor): float
    {
        if (is_numeric($valor)) return floatval($valor);
        $valor = str_replace(['$', '.', ' '], '', (string)$valor);
        $valor = str_replace(',', '.', $valor);
        return is_numeric($valor) ? floatval($valor) : 0;
    }
}