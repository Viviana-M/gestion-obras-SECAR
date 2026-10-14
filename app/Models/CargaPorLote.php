<?php

namespace App\Models;

use App\Support\Lotes\ProgresoCarga;
use Illuminate\Database\Eloquent\Model;

/**
 * Progreso de una carga procesada por lotes (autoliquidación PILA y movimiento de almacén).
 * El cierre contable usa CargaFinanciera (que implementa el mismo contrato ProgresoCarga).
 */
class CargaPorLote extends Model implements ProgresoCarga
{
    protected $table = 'cargas_por_lotes';

    protected $fillable = [
        'tipo', 'mes', 'anio', 'archivo_original', 'ruta_archivo',
        'total_filas', 'filas_procesadas', 'filas_insertadas',
        'meta_lotes', 'estado', 'error', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'meta_lotes'       => 'array',
            'total_filas'      => 'integer',
            'filas_procesadas' => 'integer',
            'filas_insertadas' => 'integer',
        ];
    }

    public function getMes(): int
    {
        return (int) $this->mes;
    }

    public function getAnio(): int
    {
        return (int) $this->anio;
    }

    public function getTotalFilas(): int
    {
        return (int) $this->total_filas;
    }

    public function setTotalFilas(int $n): void
    {
        $this->total_filas = $n;
    }

    public function getFilasProcesadas(): int
    {
        return (int) $this->filas_procesadas;
    }

    public function setFilasProcesadas(int $n): void
    {
        $this->filas_procesadas = $n;
    }

    public function getFilasInsertadas(): int
    {
        return (int) $this->filas_insertadas;
    }

    public function setFilasInsertadas(int $n): void
    {
        $this->filas_insertadas = $n;
    }

    public function getMetaLotes(): array
    {
        return $this->meta_lotes ?? [];
    }

    public function setMetaLotes(array $meta): void
    {
        $this->meta_lotes = $meta;
    }

    public function getEstado(): string
    {
        return (string) $this->estado;
    }

    public function setEstado(string $estado): void
    {
        $this->estado = $estado;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function guardar(): void
    {
        $this->save();
    }
}
