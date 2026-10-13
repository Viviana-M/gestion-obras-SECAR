<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroFinanciero extends Model
{
protected $fillable = [
    'codigo_proyecto',
    'nombre_proyecto',
    'cuenta_contable',
    'descripcion',
    'tercero_dcto',      // ← agregar
    'razon_social',      // ← agregar
    'documento',         // Docto. del BIABLE (para reconciliar contra el ERP)
    'fecha_docto',       // Fecha_docto del cierre (AAAAMMDD) → fecha del movimiento / FIFO SECAR
    'dedup_hash',        // huella anti-duplicados (ver importador)
    'valor_debito',
    'valor_credito',
    'movto_libro2',
    'cuenta_mayor',
    'signo_contable',
    'estado_er',
    'unidad_unificada',
    'periodo',
    'mes',
    'anio',
    'origen',
    'plano_aplicado_id',   // aplicación de plano que generó el movimiento (null = biable)
];
}