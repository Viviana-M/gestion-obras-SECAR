<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoContable extends Model
{
    protected $fillable = [
        'mes', 'anio', 'codigo_proyecto',
        'cuenta_origen', 'cuenta_destino', 'monto',
        'descripcion', 'estado',
        'user_operativo_id', 'user_contable_id',
    ];

    public function usuarioOperativo()
    {
        return $this->belongsTo(User::class, 'user_operativo_id');
    }

    public function usuarioContable()
    {
        return $this->belongsTo(User::class, 'user_contable_id');
    }
}