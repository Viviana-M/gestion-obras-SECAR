<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maestro Grupo B: personal especial cuya mano de obra se redistribuye por % entre bolsas.
 */
class ManoObraEspecial extends Model
{
    protected $table = 'mano_obra_especial';

    protected $fillable = [
        'cedula',
        'nombre',
        'activo',
        'user_id',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
