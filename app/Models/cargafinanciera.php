<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargaFinanciera extends Model
{
    protected $fillable = [
        'mes',
        'anio',
        'archivo_original',
        'ruta_archivo',
        'estado',
        'registros',
        'error',
        'user_id',
    ];
}