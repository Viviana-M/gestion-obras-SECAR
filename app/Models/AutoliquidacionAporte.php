<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoliquidacionAporte extends Model
{
    protected $table = 'autoliquidacion_aportes';

    protected $fillable = [
        'cedula',
        'razon_social',
        'empleado',
        'empleado_nombre',
        'un_codigo',
        'un_descripcion',
        'concepto_pila',
        'aporte_empleado',
        'aporte_empresa',
        'real_descontado',
        'fecha',
        'mes',
        'anio',
        'id_cuenta',
        'cuenta_contable',
        'centro_operacion',
        'ndc',
    ];

    protected function casts(): array
    {
        return [
            'fecha'           => 'date',
            'mes'             => 'integer',
            'anio'            => 'integer',
            'aporte_empleado' => 'float',
            'aporte_empresa'  => 'float',
            'real_descontado' => 'float',
        ];
    }
}
