<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Obras marcadas inactivas en el maestro que aún tienen saldo en la cuenta 14. Libro con dos hojas:
 * el resumen por obra y el detalle (despliegue) del saldo por cuenta/tercero/documento/período.
 * Recibe los arreglos (encabezado + filas) desde el controlador.
 */
class InactivasConSaldoExport implements WithMultipleSheets
{
    /**
     * @param array $filas    Resumen: [ [Código, Obra, Cliente, Saldo cuenta 14], … ]
     * @param array $detalle  Detalle: [ [Obra, Cuenta, Concepto, Tercero, Documento, Período, Saldo], … ]
     */
    public function __construct(private array $filas, private array $detalle = []) {}

    public function sheets(): array
    {
        $hojas = [new InactivasResumenSheet($this->filas)];
        if (! empty($this->detalle)) {
            $hojas[] = new InactivasDetalleSheet($this->detalle);
        }
        return $hojas;
    }
}

/** Hoja resumen: una fila por obra con su saldo en cuenta 14. */
class InactivasResumenSheet implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Inactivas con saldo';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Formato de miles para la columna Saldo cuenta 14. */
    public function columnFormats(): array
    {
        return ['D' => '#,##0'];
    }
}

/** Hoja detalle: el despliegue del saldo por obra (cuenta, concepto, tercero, documento, período). */
class InactivasDetalleSheet implements FromArray, WithTitle, WithColumnFormatting
{
    public function __construct(private array $detalle) {}

    public function title(): string
    {
        return 'Detalle';
    }

    public function array(): array
    {
        return $this->detalle;
    }

    /** Formato de miles para la columna Saldo. */
    public function columnFormats(): array
    {
        return ['G' => '#,##0'];
    }
}
