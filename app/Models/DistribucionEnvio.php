<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistribucionEnvio extends Model
{
    protected $table = 'distribucion_envios';
    protected $fillable = ['mes', 'anio', 'estado', 'edicion_habilitada', 'enviado_at', 'enviado_por'];
    protected $casts = ['edicion_habilitada' => 'boolean', 'enviado_at' => 'datetime'];
}