<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FichaProyecto extends Model
{
    protected $table = 'ficha_proyectos';

    protected $fillable = [
        'codigo_proyecto',
        'cliente',
        'nombre_obra',
        'area',
        'valor_contratado',
        'costo_estimado',
        'margen_ofertado',
        'utilidad_ofertada',
        'responsable_obra',
        'responsable_comercial',
        'origen',
        'user_id',
    ];

    protected $casts = [
        'valor_contratado'  => 'float',
        'costo_estimado'    => 'float',
        'margen_ofertado'   => 'float',
        'utilidad_ofertada' => 'float',
    ];
}