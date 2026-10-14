<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detalle de la planilla de autoliquidación de aportes (PILA), una fila por
 * concepto/persona/unidad. ~6.900 filas por mes. Fase 1: solo carga y resumen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autoliquidacion_aportes', function (Blueprint $table) {
            $table->id();
            $table->string('cedula');                               // Id. Tercero Mov
            $table->string('razon_social')->nullable();             // Razon Social
            $table->string('un_codigo')->nullable();                // Id. U.N. Mov (bolsa)
            $table->string('un_descripcion')->nullable();           // Descripción UN
            $table->string('concepto_pila')->nullable();            // Descripción Codigo PILA
            $table->decimal('aporte_empleado', 18, 2)->default(0);  // Aporte del empl
            $table->decimal('aporte_empresa', 18, 2)->default(0);   // Aporte empresa
            $table->decimal('real_descontado', 18, 2)->default(0);  // Real Descontado
            $table->date('fecha')->nullable();                      // Fecha
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('id_cuenta')->nullable();                // ID Cuenta
            $table->string('cuenta_contable')->nullable();          // Cuenta contable
            $table->timestamps();

            $table->index(['anio', 'mes'], 'aa_periodo_idx');
            $table->index(['anio', 'mes', 'un_codigo'], 'aa_periodo_un_idx');
            $table->index('cedula', 'aa_cedula_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autoliquidacion_aportes');
    }
};
