<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AplicacionCosto extends Model
{
    protected $table = 'aplicaciones_costo';

    protected $fillable = [
        'distribucion_id',
        'mes',
        'anio',
        'codigo_proyecto',
        'cuenta_14',
        'origen_bolsa',
        'cuenta_61',
        'categoria',
        'nombre',
        'monto_aplicar',
        'es_provision',
        'descripcion',
        'estado',
        'user_id',
    ];

    protected $casts = [
        'es_provision'  => 'boolean',
        'monto_aplicar' => 'decimal:2',
    ];
}