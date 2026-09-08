<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado de edición de la Distribución por período. Contabilidad abre el "cierre" de un
 * mes para que Operaciones pueda editar; mientras esté cerrado (o sin registro), la
 * distribución es de solo lectura.
 */
class CierrePeriodo extends Model
{
    protected $table = 'cierre_periodos';

    protected $fillable = [
        'mes', 'anio', 'abierto',
        'abierto_por', 'abierto_at', 'cerrado_por', 'cerrado_at',
    ];

    protected function casts(): array
    {
        return [
            'mes'        => 'integer',
            'anio'       => 'integer',
            'abierto'    => 'boolean',
            'abierto_at' => 'datetime',
            'cerrado_at' => 'datetime',
        ];
    }

    /** ¿El período (mes, año) está abierto para editar? Sin registro = cerrado. */
    public static function estaAbierto(int $mes, int $anio): bool
    {
        return static::where('mes', $mes)->where('anio', $anio)->where('abierto', true)->exists();
    }
}
