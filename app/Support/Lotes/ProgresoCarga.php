<?php

namespace App\Support\Lotes;

/**
 * Registro de progreso de una carga por lotes. Lo implementan tanto CargaPorLote
 * (autoliquidación / movimiento) como CargaFinanciera (cierre contable), para que el
 * motor de lotes (MotorLotes) trabaje igual con cualquiera de los dos.
 */
interface ProgresoCarga
{
    public function getMes(): int;

    public function getAnio(): int;

    public function getTotalFilas(): int;

    public function setTotalFilas(int $n): void;

    public function getFilasProcesadas(): int;

    public function setFilasProcesadas(int $n): void;

    public function getFilasInsertadas(): int;

    public function setFilasInsertadas(int $n): void;

    /** @return array<string,mixed> */
    public function getMetaLotes(): array;

    /** @param array<string,mixed> $meta */
    public function setMetaLotes(array $meta): void;

    public function getEstado(): string;

    public function setEstado(string $estado): void;

    public function setError(?string $error): void;

    public function guardar(): void;
}
