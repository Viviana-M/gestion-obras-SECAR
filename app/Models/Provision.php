<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Provisión persistente (costo en tránsito). Se registra una vez (Débito cuenta 14 /
 * Crédito cuenta 26) y se arrastra ACTIVA cada mes hasta que la reversen.
 */
class Provision extends Model
{
    protected $table = 'provisiones';

    protected $fillable = [
        'codigo_proyecto', 'departamento', 'cuenta_14', 'cuenta_26', 'monto', 'descripcion',
        'mes', 'anio', 'estado', 'reversada_mes', 'reversada_anio', 'user_id', 'reversada_por',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'float',
            'mes'   => 'integer',
            'anio'  => 'integer',
            'reversada_mes'  => 'integer',
            'reversada_anio' => 'integer',
        ];
    }

    /** Período contable AAAAMM en que se registró. */
    public function periodo(): int
    {
        return $this->anio * 100 + $this->mes;
    }

    /**
     * Provisiones ACTIVAS a un período dado: registradas en ese período o antes, y aún no
     * reversadas (o reversadas en un período posterior). Son las que se "arrastran".
     */
    public function scopeActivasEn($query, int $mes, int $anio)
    {
        $periodo = $anio * 100 + $mes;
        return $query
            ->whereRaw('anio * 100 + mes <= ?', [$periodo])
            ->where(function ($q) use ($periodo) {
                $q->where('estado', 'activa')
                    ->orWhereRaw('COALESCE(reversada_anio,0) * 100 + COALESCE(reversada_mes,0) > ?', [$periodo]);
            });
    }
}
