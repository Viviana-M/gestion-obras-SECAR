<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerceroManoObra extends Model
{
    protected $table = 'terceros_mano_obra';

    protected $fillable = ['cedula', 'nombre', 'departamento', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    // Cédulas de las personas que se reparten por porcentaje
    public static function cedulas(): array
    {
        return static::where('activo', true)->pluck('cedula')->all();
    }
}