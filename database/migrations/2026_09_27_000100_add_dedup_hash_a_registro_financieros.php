<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Huella (hash) de la combinación que identifica un movimiento duplicado dentro de una carga:
 * codigo_proyecto + cuenta_contable + tercero + documento + valor_debito + valor_credito + periodo.
 * Se usa como candado anti-duplicados en el importador (chequeo eficiente por lotes). No cambia
 * la clasificación ni el estado_er.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->string('dedup_hash', 32)->nullable()->after('documento');
            $table->index(['anio', 'mes', 'dedup_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->dropIndex(['anio', 'mes', 'dedup_hash']);
            $table->dropColumn('dedup_hash');
        });
    }
};
