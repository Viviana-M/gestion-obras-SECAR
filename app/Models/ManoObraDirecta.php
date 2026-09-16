<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManoObraDirecta extends Model
{
    protected $table = 'mano_obra_directa';

    protected $fillable = [
        'cedula',
        'nombre',
        'pct_mantenimiento',
        'pct_instalaciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'pct_mantenimiento' => 'float',
            'pct_instalaciones' => 'float',
            'activo'            => 'boolean',
        ];
    }

    /** Solo el personal activo. */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
