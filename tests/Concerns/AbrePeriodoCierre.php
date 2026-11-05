<?php

namespace Tests\Concerns;

use App\Models\CierrePeriodo;

/**
 * Abre el cierre del período 7/2026 (el que usan las pruebas de distribución) para que
 * guardar/enviar/asignar no queden bloqueados por el control de solo lectura.
 * Laravel invoca setUp{TraitBasename}() automáticamente tras migrar la BD.
 */
trait AbrePeriodoCierre
{
    protected function setUpAbrePeriodoCierre(): void
    {
        CierrePeriodo::updateOrCreate(['mes' => 7, 'anio' => 2026], ['abierto' => true]);
    }

    protected function abrirCierre(int $mes, int $anio): void
    {
        CierrePeriodo::updateOrCreate(['mes' => $mes, 'anio' => $anio], ['abierto' => true]);
    }
}
