<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos_balance', function (Blueprint $table) {
            $table->id();
            $table->string('cuenta_contable')->index();
            $table->string('descripcion')->nullable();
            $table->string('clase', 1)->index();           // 1=Activo, 2=Pasivo, 3=Patrimonio
            $table->string('codigo_proyecto')->nullable(); // las globales vienen sin proyecto
            $table->string('nombre_proyecto')->nullable();
            $table->decimal('valor_debito', 18, 2)->default(0);
            $table->decimal('valor_credito', 18, 2)->default(0);
            $table->decimal('movto', 18, 2)->default(0);    // debito - credito
            $table->string('periodo')->nullable();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('origen')->default('biable');
            $table->index(['mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_balance');
    }
};