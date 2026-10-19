<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribución de mano de obra (Operaciones): asignación manual de la MO por persona (tercero) de
 * una bolsa a cada obra destino. Guarda el detalle por (tercero, cuenta 14) para poder generar el
 * plano 14→61. Además, `planos_aplicados.bolsa_un` permite aplicar/reemplazar de forma idempotente
 * el plano de una bolsa+período (tipo 'mo_distribucion').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mano_obra_asignacion', function (Blueprint $table) {
            $table->id();
            $table->string('bolsa_un', 30)->index();          // UN de la bolsa origen (MTO000xx…)
            $table->string('cuenta_14', 30);                  // cuenta 14 de MO de origen
            $table->string('tercero', 120);                   // identificador SIESA (doc o, si viene vacío, nombre)
            $table->string('tercero_doc', 60)->nullable();    // documento del tercero (tercero_dcto)
            $table->string('tercero_nombre', 200)->nullable();// razón social (display)
            $table->string('obra_destino', 40);               // obra a la que se carga la MO
            $table->decimal('monto', 18, 2)->default(0);
            $table->unsignedTinyInteger('mes');
            $table->smallInteger('anio');
            $table->string('observacion', 255)->nullable();
            $table->string('origen', 20)->default('manual');  // 'manual' | 'precargado'
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['bolsa_un', 'anio', 'mes']);
        });

        Schema::table('planos_aplicados', function (Blueprint $table) {
            $table->string('bolsa_un', 30)->nullable()->after('distribucion_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('planos_aplicados', function (Blueprint $table) {
            $table->dropColumn('bolsa_un');
        });
        Schema::dropIfExists('mano_obra_asignacion');
    }
};
