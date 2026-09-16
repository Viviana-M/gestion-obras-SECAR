<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Monto "a distribuir" (editable en el cierre) por cuenta de una UN, por período.
 * El disponible de la bolsa grande = suma de estos montos.
 */
class BolsaMonto extends Model
{
    protected $table = 'bolsa_montos';

    protected $fillable = [
        'mes', 'anio', 'un_codigo', 'cuenta_14', 'monto_distribuir', 'observaciones', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'mes'              => 'integer',
            'anio'             => 'integer',
            'monto_distribuir' => 'float',
        ];
    }
}
