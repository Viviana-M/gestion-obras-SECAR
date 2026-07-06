<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Homologacion extends Model
{
    protected $table = 'homologaciones';

    protected $fillable = [
        'cuenta_14',
        'cuenta_61',
        'nombre',
        'estructura',
        'origen',
        'user_id',
    ];
}