<?php

namespace App\Models;

use App\Support\Lotes\ProgresoCarga;
use Illuminate\Database\Eloquent\Model;

class CargaFinanciera extends Model implements ProgresoCarga
{
    protected $fillable = [
        'mes',
        'anio',
        'archivo_original',
        'ruta_archivo',
        'estado',
        'registros',
        'error',
        'user_id',
        'total_filas',
        'filas_procesadas',
        'meta_lotes',
    ];

    protected function casts(): array
    {
        return [
            'meta_lotes'       => 'array',
            'registros'        => 'integer',
            'total_filas'      => 'integer',
            'filas_procesadas' => 'integer',
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    // ─────────────── Contrato ProgresoCarga (procesamiento por lotes) ───────────────
    // 'registros' hace de "filas insertadas" (ya era el total de registros del cierre).

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
        return (int) $this->registros;
    }

    public function setFilasInsertadas(int $n): void
    {
        $this->registros = $n;
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
