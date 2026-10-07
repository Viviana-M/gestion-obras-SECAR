<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monto "a distribuir" editable por cuenta de cada UN de una bolsa, por período. En el
 * cierre, Contabilidad/Operaciones definen cuánto de cada cuenta se carga este mes
 * (ej. de $20M totales, solo $10M). El disponible de la bolsa grande = suma de estos
 * montos. Sin registro para una cuenta = se toma su saldo completo por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bolsa_montos', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('un_codigo');                          // UN (bolsa) dueña de la cuenta
            $table->string('cuenta_14');                          // cuenta contable (14…)
            $table->decimal('monto_distribuir', 18, 2)->default(0);
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->unique(['mes', 'anio', 'un_codigo', 'cuenta_14'], 'bolsa_monto_unico');
            $table->index(['mes', 'anio'], 'bolsa_monto_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bolsa_montos');
    }
};
