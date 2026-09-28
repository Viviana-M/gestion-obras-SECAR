<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asignación manual de mano de obra de una persona (tercero) de una bolsa a una obra destino, por
 * (tercero, cuenta 14). El detalle por cuenta permite generar el plano 14→61 preservando tercero y
 * obra. Ver DistribucionManoObraService / DistribucionManoObraController.
 */
class ManoObraAsignacion extends Model
{
    protected $table = 'mano_obra_asignacion';

    protected $fillable = [
        'bolsa_un', 'cuenta_14', 'tercero', 'tercero_doc', 'tercero_nombre',
        'obra_destino', 'monto', 'mes', 'anio', 'observacion', 'origen', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'float',
            'mes'   => 'integer',
            'anio'  => 'integer',
        ];
    }
}
