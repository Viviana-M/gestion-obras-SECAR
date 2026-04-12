<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('registro_financieros', function (Blueprint $table) {
        $table->id();
        $table->string('codigo_proyecto');
        $table->string('cuenta_contable');
        $table->string('descripcion')->nullable();
        $table->decimal('valor_debito', 18, 2)->default(0);
        $table->decimal('valor_credito', 18, 2)->default(0);
        $table->tinyInteger('mes');
        $table->year('anio');
        $table->enum('origen', ['biable', 'manual'])->default('biable');
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_financieros');
    }
};
