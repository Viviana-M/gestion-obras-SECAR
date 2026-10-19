<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de cuenta 14 generados por el sistema al APLICAR un plano (reverso o distribución).
 *
 * El cliente contabiliza los planos en el ERP pero no vuelve a cargar ese mes desde BIABLE, así
 * que el sistema debe aplicar esos planos en su propia cuenta 14 para que el saldo coincida con el
 * ERP. Para eso:
 *   1) `registro_financieros.origen` pasa de enum('biable','manual') a string, para admitir
 *      'reverso_plano' y 'distribucion_plano' (movimientos generados, nunca 'biable').
 *   2) `registro_financieros.plano_aplicado_id` referencia la aplicación que los originó, para
 *      idempotencia (re-aplicar reemplaza) y para poder deshacerlos.
 *   3) `planos_aplicados` es el registro de auditoría de cada aplicación (una fila por plano
 *      aplicado y vigente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            // De enum a string: los orígenes generados por el sistema no caben en el enum original.
            $table->string('origen', 30)->default('biable')->change();
            // Aplicación de plano que generó el movimiento. Null = biable u otro origen externo.
            $table->unsignedBigInteger('plano_aplicado_id')->nullable()->after('origen')->index();
        });

        Schema::create('planos_aplicados', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);                              // 'reverso' | 'distribucion'
            $table->unsignedBigInteger('distribucion_id')->nullable()->index(); // plano de distribución
            $table->unsignedTinyInteger('corte_mes')->nullable();    // reverso: corte acumulado usado
            $table->smallInteger('corte_anio')->nullable();
            $table->unsignedTinyInteger('mes');                      // período destino (lo que contabiliza el ERP)
            $table->smallInteger('anio');
            $table->integer('numero_documento')->nullable();         // n° de documento SIESA
            $table->string('referencia')->nullable();                // descripción legible
            $table->decimal('total_debito', 18, 2)->default(0);
            $table->decimal('total_credito', 18, 2)->default(0);
            $table->integer('n_lineas')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['tipo', 'corte_anio', 'corte_mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_aplicados');
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->dropColumn('plano_aplicado_id');
            $table->enum('origen', ['biable', 'manual'])->default('biable')->change();
        });
    }
};
