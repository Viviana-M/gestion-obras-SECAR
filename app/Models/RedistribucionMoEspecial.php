<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Porcentaje de redistribución de la MO de una persona (Grupo B) hacia una bolsa (UN),
 * por período. Por persona y período, la suma de porcentajes debe ser 100%.
 */
class RedistribucionMoEspecial extends Model
{
    protected $table = 'redistribucion_mo_especial';

    protected $fillable = [
        'cedula',
        'mes',
        'anio',
        'un_codigo',
        'porcentaje',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'mes'        => 'integer',
            'anio'       => 'integer',
            'porcentaje' => 'float',
        ];
    }
}
