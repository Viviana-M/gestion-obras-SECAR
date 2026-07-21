<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reasignaciones_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_distribucion_id')->nullable()->index(); // ítem reasignado
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('codigo_obra_origen')->index();
            $table->string('codigo_obra_destino')->index();
            $table->string('cuenta');                 // misma cuenta contable (se reclasifica la UN)
            $table->string('item')->nullable();
            $table->decimal('costo', 18, 2)->default(0);
            $table->string('naturaleza')->nullable();
            $table->string('motivo')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre')->nullable();
            $table->timestamps();

            $table->index(['anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reasignaciones_item');
    }
};
