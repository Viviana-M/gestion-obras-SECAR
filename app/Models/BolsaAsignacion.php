<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asignación de costo de una bolsa de área (UN) a una obra, por mes/año.
 * Lo asignado sale de la bolsa y entra como costo de la obra destino.
 */
class BolsaAsignacion extends Model
{
    protected $table = 'bolsa_asignaciones';

    protected $fillable = [
        'distribucion_id',
        'mes',
        'anio',
        'departamento',
        'bolsa_codigo',
        'codigo_proyecto',
        'monto',
        'detalle',
        'user_id',
    ];

    protected $casts = [
        'monto'   => 'decimal:2',
        'detalle' => 'array',
    ];
}
