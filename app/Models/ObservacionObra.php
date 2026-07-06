<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObservacionObra extends Model
{
    protected $table = 'observaciones_obra';

    protected $fillable = [
        'codigo_proyecto',
        'mes',
        'anio',
        'observacion',
        'user_id',
    ];
}