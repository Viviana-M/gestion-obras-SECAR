<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProyectoCerrado extends Model
{
    protected $table = 'proyectos_cerrados';

    protected $fillable = [
        'codigo_proyecto',
        'nombre_proyecto',
        'fecha_cierre',
        'tipo_cierre',
        'observacion',
        'origen',
        'user_id',
    ];

    protected $casts = [
        'fecha_cierre' => 'date',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}