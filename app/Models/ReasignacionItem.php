<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historial de reasignaciones de un ítem de una obra a otra (Fase D). Se refleja en
 * el plano como una reclasificación 14→14 (misma cuenta contable, cambia la UN/obra).
 */
class ReasignacionItem extends Model
{
    protected $table = 'reasignaciones_item';

    protected $fillable = [
        'item_distribucion_id',
        'mes',
        'anio',
        'codigo_obra_origen',
        'codigo_obra_destino',
        'cuenta',
        'item',
        'costo',
        'naturaleza',
        'motivo',
        'user_id',
        'user_nombre',
    ];

    protected $casts = [
        'costo' => 'decimal:2',
    ];
}
