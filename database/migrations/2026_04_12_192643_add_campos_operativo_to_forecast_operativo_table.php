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
    Schema::table('forecast_operativo', function (Blueprint $table) {
        $table->enum('estado_obra', ['abierta', 'cerrada_parcial', 'cerrada_total', 'suspendida'])
              ->default('abierta')->after('estado');
        $table->decimal('avance_pct', 5, 2)->default(0)->after('estado_obra');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('forecast_operativo', function (Blueprint $table) {
        $table->dropColumn(['estado_obra', 'avance_pct']);
    });
}
};
