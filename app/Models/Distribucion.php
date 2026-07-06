<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Distribucion extends Model
{
    protected $table = 'distribuciones';
    protected $fillable = ['mes', 'anio', 'version', 'estado', 'edicion_habilitada', 'guardado_por', 'enviado_por', 'enviado_at'];
    protected $casts = ['edicion_habilitada' => 'boolean', 'enviado_at' => 'datetime'];
}