<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObraEstado extends Model
{
    protected $table = 'obras_estado';
    protected $fillable = ['codigo_proyecto', 'estado', 'user_id'];
}