<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría de un plano APLICADO en la cuenta 14 del sistema (reverso o distribución).
 * Cada fila representa una aplicación vigente; sus movimientos generados en `registro_financieros`
 * se referencian por `plano_aplicado_id`. Al re-aplicar el mismo plano se reemplaza (idempotencia)
 * y al deshacerlo se borran sus movimientos y la fila.
 */
class PlanoAplicado extends Model
{
    protected $table = 'planos_aplicados';

    protected $fillable = [
        'tipo', 'distribucion_id', 'corte_mes', 'corte_anio', 'mes', 'anio',
        'numero_documento', 'referencia', 'total_debito', 'total_credito', 'n_lineas', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'distribucion_id'  => 'integer',
            'corte_mes'        => 'integer',
            'corte_anio'       => 'integer',
            'mes'              => 'integer',
            'anio'             => 'integer',
            'numero_documento' => 'integer',
            'total_debito'     => 'float',
            'total_credito'    => 'float',
            'n_lineas'         => 'integer',
        ];
    }

    public function movimientos()
    {
        return $this->hasMany(RegistroFinanciero::class, 'plano_aplicado_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
