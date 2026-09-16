<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisiones persistentes (costo en tránsito). Una provisión se registra UNA vez
 * (Débito cuenta 14 elegida / Crédito cuenta 26) y se ARRASTRA activa cada mes hasta
 * que la reversen; al reversar se hace el asiento inverso (Débito 26 / Crédito 14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisiones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proyecto');
            $table->string('departamento', 20)->nullable();
            $table->string('cuenta_14');                    // débito (depende de lo que provisionan)
            $table->string('cuenta_26')->default('26050604'); // contrapartida (crédito)
            $table->decimal('monto', 15, 2);
            $table->string('descripcion')->nullable();
            $table->unsignedTinyInteger('mes');             // período en que se registró (se contabiliza)
            $table->unsignedSmallInteger('anio');
            $table->string('estado', 12)->default('activa'); // activa | reversada
            $table->unsignedTinyInteger('reversada_mes')->nullable();
            $table->unsignedSmallInteger('reversada_anio')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('reversada_por')->nullable();
            $table->timestamps();

            $table->index(['codigo_proyecto', 'estado']);
            $table->index(['departamento', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisiones');
    }
};
