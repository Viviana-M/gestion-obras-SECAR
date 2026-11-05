<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bolsa_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distribucion_id')->nullable()->index();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('departamento', 20)->nullable();
            $table->string('bolsa_codigo', 30)->index();          // UN de origen (MTO00099...)
            $table->string('codigo_proyecto')->index();           // OT destino
            $table->decimal('monto', 18, 2)->default(0);          // asignado desde la bolsa a la obra
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['mes', 'anio', 'bolsa_codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bolsa_asignaciones');
    }
};
