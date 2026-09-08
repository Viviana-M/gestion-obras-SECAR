<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Monto a distribuir por persona y período en MO Apoyo administrativo y operativo. Contabilidad
 * decide distribuir la totalidad o una porción del saldo retirado del tercero; el resto queda
 * pendiente. Si no hay registro del período, por defecto se distribuye el total.
 */
class MontoDistribuirMoEspecial extends Model
{
    protected $table = 'monto_distribuir_mo_especial';

    protected $fillable = [
        'cedula',
        'mes',
        'anio',
        'monto_distribuir',
        'user_id',
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
