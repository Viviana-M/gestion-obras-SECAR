<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número de documento (Docto.) de cada movimiento de la cuenta 14 (y demás), para poder
 * reconciliar contra el ERP documento por documento. Se llena al (re)cargar cada mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->string('documento')->nullable()->after('razon_social');
            $table->index('documento');
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->dropIndex(['documento']);
            $table->dropColumn('documento');
        });
    }
};
