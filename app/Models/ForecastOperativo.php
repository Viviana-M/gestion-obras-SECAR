<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForecastOperativo extends Model
{
    protected $table = 'forecast_operativo';

    protected $fillable = [
    'mes', 'anio', 'codigo_proyecto', 'nombre_proyecto',
    'cuenta_contable', 'descripcion', 'saldo_14',
    'monto_a_mover', 'facturado', 'margen_minimo_pct',
    'estado', 'estado_obra', 'avance_pct', 'user_id',
];

    protected $casts = [
        'facturado' => 'boolean',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}