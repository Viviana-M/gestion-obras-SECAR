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
    Schema::table('registro_financieros', function (Blueprint $table) {
        $table->string('nombre_proyecto')->nullable()->after('codigo_proyecto');
    });
}

    /**
     * Reverse the migrations.
     */
  public function down(): void
{
    Schema::table('registro_financieros', function (Blueprint $table) {
        $table->dropColumn('nombre_proyecto');
    });
}
    };
