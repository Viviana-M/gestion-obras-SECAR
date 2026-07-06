<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones_costo', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('codigo_proyecto')->index();
            $table->string('cuenta_14');
            $table->string('cuenta_61')->nullable();
            $table->string('categoria')->nullable();
            $table->string('nombre')->nullable();
            $table->decimal('monto_aplicar', 18, 2)->default(0);
            $table->boolean('es_provision')->default(false);
            $table->string('descripcion')->nullable();
            $table->string('estado')->default('borrador');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_costo');
    }
};