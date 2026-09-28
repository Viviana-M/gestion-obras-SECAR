<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cierre de conciliación de un período (mes, año). Su existencia marca el período como conciliado y
 * BLOQUEADO: no se puede recargar BIABLE ni aplicar/deshacer planos con destino en ese mes hasta
 * reabrirlo (borrar la fila).
 */
class CierreConciliacion extends Model
{
    protected $table = 'cierres_conciliacion';

    protected $fillable = [
        'mes', 'anio', 'saldo_sistema_14', 'saldo_sistema_6', 'saldo_erp_14', 'saldo_erp_6',
        'diferencia', 'user_id', 'cerrado_at',
    ];

    protected function casts(): array
    {
        return [
            'mes'              => 'integer',
            'anio'             => 'integer',
            'saldo_sistema_14' => 'float',
            'saldo_sistema_6'  => 'float',
            'saldo_erp_14'     => 'float',
            'saldo_erp_6'      => 'float',
            'diferencia'       => 'float',
            'cerrado_at'       => 'datetime',
        ];
    }

    /** ¿El período (mes, año) está cerrado (conciliado y bloqueado)? */
    public static function estaCerrado(int $mes, int $anio): bool
    {
        return static::where('mes', $mes)->where('anio', $anio)->exists();
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
