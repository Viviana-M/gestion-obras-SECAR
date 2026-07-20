<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Llave de cuentas por ítem: mapea (tipo de inventario + tipo de movimiento) → cuenta
 * de costo/gasto. Cuando coinciden el tipo de inventario y el tipo de movimiento
 * (motivo), esa es la cuenta a usar. La usará el cargue de ítems (Fase B).
 */
class LlaveItemCuenta extends Model
{
    protected $table = 'llave_items_cuenta';

    protected $fillable = [
        'tipo_inventario',
        'nombre_tipo_inventario',
        'tipo_movimiento',
        'cuenta',
        'naturaleza',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function scopeActivos($q)
    {
        return $q->where('activo', true);
    }

    /** Resuelve la llave (y su cuenta) para un par tipo de inventario + tipo de movimiento. */
    public static function resolver(string $tipoInventario, string $tipoMovimiento): ?self
    {
        return static::activos()
            ->where('tipo_inventario', $tipoInventario)
            ->where('tipo_movimiento', $tipoMovimiento)
            ->first();
    }
}
