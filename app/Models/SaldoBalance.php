<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaldoBalance extends Model
{
    protected $table = 'saldos_balance';
    public $timestamps = false;

    protected $fillable = [
        'cuenta_contable', 'descripcion', 'clase',
        'codigo_proyecto', 'nombre_proyecto',
        'valor_debito', 'valor_credito', 'movto',
        'periodo', 'mes', 'anio', 'origen',
    ];
}