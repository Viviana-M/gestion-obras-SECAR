<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cliente (NIT) de una obra.
 *
 * Se deriva de las lineas de ingreso de registro_financieros (columna tercero_dcto),
 * pero se puede corregir a mano. Lo manual manda siempre: el comando de derivacion
 * nunca pisa una fila con origen = 'manual'.
 */
class ObraCliente extends Model
{
    protected $table = 'obra_clientes';

    /** NIT que NO son clientes: la propia empresa y los genericos contables. */
    public const NIT_EXCLUIDOS = [
        '890319324',   // SECAR INGENIEROS SA (la propia empresa)
        '22222222',    // CUANTIAS MENORES (generico)
    ];

    protected $fillable = [
        'codigo_proyecto',
        'nit',
        'razon_social',
        'origen',
        'revisar',
        'motivo_revision',
        'candidatos',
        'facturado',
        'derivado_at',
        'user_id',
    ];

    protected $casts = [
        'revisar'     => 'boolean',
        'candidatos'  => 'array',
        'facturado'   => 'float',
        'derivado_at' => 'datetime',
    ];

    public function esManual(): bool
    {
        return $this->origen === 'manual';
    }

    /** Esta lista para ir al plano de SIESA? */
    public function tieneNit(): bool
    {
        return $this->nit !== null && trim((string) $this->nit) !== '';
    }

    /** El NIT de una obra, o null si no lo tiene. */
    public static function nitDe(string $codigoProyecto): ?string
    {
        $c = static::where('codigo_proyecto', $codigoProyecto)->first();
        return $c && $c->tieneNit() ? $c->nit : null;
    }

    /** Mapa codigo_proyecto => ObraCliente, para no consultar en bucle. */
    public static function mapa()
    {
        return static::all()->keyBy(fn($c) => (string) $c->codigo_proyecto);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}