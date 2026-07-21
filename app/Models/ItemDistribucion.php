<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ítem que compone el costo de una obra en un período, ya cruzado a su cuenta de
 * costo (Fase B). El signo lo da la naturaleza: Débito = salida (suma), Crédito =
 * reintegro (resta). El costo se guarda siempre positivo.
 */
class ItemDistribucion extends Model
{
    protected $table = 'items_distribucion';

    protected $fillable = [
        'codigo_obra',
        'mes',
        'anio',
        'cuenta',
        'item',
        'tipo_inventario',
        'codigo_movimiento',
        'tipo_movimiento',
        'naturaleza',
        'tercero',
        'cantidad',
        'fecha',
        'numero_documento',
        'costo',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'costo'    => 'decimal:2',
        'fecha'    => 'date',
    ];

    /** ¿Es un reintegro? (naturaleza Crédito → resta del neto de la cuenta). */
    public function esReintegro(): bool
    {
        return strcasecmp((string) $this->naturaleza, 'Crédito') === 0
            || strcasecmp((string) $this->naturaleza, 'Credito') === 0;
    }

    /** Costo con signo para el neto de la cuenta (reintegro negativo). */
    public function costoNeto(): float
    {
        return $this->esReintegro() ? -abs((float) $this->costo) : abs((float) $this->costo);
    }
}
