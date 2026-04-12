<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroFinanciero extends Model
{
protected $fillable = [
    'codigo_proyecto',
    'nombre_proyecto',
    'cuenta_contable',
    'descripcion',
    'valor_debito',
    'valor_credito',
    'movto_libro2',
    'cuenta_mayor',
    'signo_contable',
    'estado_er',
    'unidad_unificada',
    'periodo',
    'mes',
    'anio',
    'origen',
];
}