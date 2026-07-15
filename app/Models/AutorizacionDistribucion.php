<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutorizacionDistribucion extends Model
{
    protected $table = 'autorizaciones_distribucion';

    protected $fillable = [
        'codigo_proyecto',
        'mes',
        'anio',
        'estado',
        'motivo',
        'solicitado_por',
        'solicitado_at',
        'resuelto_por',
        'resuelto_at',
        'comentario_gerencia',
    ];

    protected function casts(): array
    {
        return [
            'mes'           => 'integer',
            'anio'          => 'integer',
            'solicitado_at' => 'datetime',
            'resuelto_at'   => 'datetime',
        ];
    }

    public const PENDIENTE = 'pendiente';
    public const APROBADA  = 'aprobada';
    public const RECHAZADA = 'rechazada';

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function resolvedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    /** Scope: autorizaciones pendientes de resolver. */
    public function scopePendientes($query)
    {
        return $query->where('estado', self::PENDIENTE);
    }

    /** Scope: la autorización de un proyecto en un mes/año concreto. */
    public function scopeDelProyecto($query, string $codigo, int $mes, int $anio)
    {
        return $query->where('codigo_proyecto', $codigo)
            ->where('mes', $mes)
            ->where('anio', $anio);
    }

    /** ¿Está aprobado este proyecto para el mes/año? */
    public static function estaAprobado(string $codigo, int $mes, int $anio): bool
    {
        return static::query()
            ->delProyecto($codigo, $mes, $anio)
            ->where('estado', self::APROBADA)
            ->exists();
    }

    /** Lista de codigo_proyecto aprobados en un mes/año (para chequeos en lote). */
    public static function aprobadosEn(int $mes, int $anio): array
    {
        return static::query()
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->where('estado', self::APROBADA)
            ->pluck('codigo_proyecto')
            ->all();
    }
}
