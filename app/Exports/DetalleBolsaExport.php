<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Detalle por tercero de una bolsa de área (tabla plana de "Ver detalle por cuenta"), con el cruce
 * FIFO general ya aplicado (todos los negativos de la cuenta cruzan los positivos más antiguos).
 * Recibe el arreglo (encabezado + filas) desde el controlador.
 */
class DetalleBolsaExport implements FromArray, WithTitle, WithColumnFormatting
{
    /** @param array $filas [ [U.N., Fecha, Desc. U.N., Nombre proyecto, Auxiliar, Desc. auxiliar, Nit, Razón social, Docto., Saldo, A distribuir, Queda, Observaciones], … ] */
    public function __construct(private array $filas) {}

    public function title(): string
    {
        return 'Detalle por cuenta';
    }

    public function array(): array
    {
        return $this->filas;
    }

    /** Formato de miles para Saldo (J), A distribuir (K) y Queda mes sig. (L). */
    public function columnFormats(): array
    {
        return ['J' => '#,##0', 'K' => '#,##0', 'L' => '#,##0'];
    }
}
