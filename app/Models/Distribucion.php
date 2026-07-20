<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Distribucion extends Model
{
    protected $table = 'distribuciones';
    protected $fillable = ['mes', 'anio', 'departamento', 'version', 'estado', 'reemplazada', 'edicion_habilitada', 'guardado_por', 'enviado_por', 'enviado_at'];
    protected $casts = ['edicion_habilitada' => 'boolean', 'reemplazada' => 'boolean', 'enviado_at' => 'datetime'];

    public function versiones()
    {
        return $this->hasMany(DistribucionVersion::class, 'distribucion_id')->orderByDesc('created_at');
    }
}