<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnBolsa extends Model
{
    protected $table = 'un_bolsas';

    protected $fillable = ['codigo', 'nombre', 'departamento', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    // ¿Un código de proyecto es una bolsa registrada?
    public static function esBolsa(string $codigo): bool
    {
        return static::where('codigo', $codigo)->exists();
    }

    // Lista de códigos de bolsa (para excluirlos de los proyectos reales)
    public static function codigos(): array
    {
        return static::pluck('codigo')->all();
    }
}