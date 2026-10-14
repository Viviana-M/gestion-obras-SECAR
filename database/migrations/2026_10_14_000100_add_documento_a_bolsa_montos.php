<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El detalle de la bolsa ahora muestra UNA FILA POR DOCUMENTO que sobrevive al cruce (los antiguos se
 * consumen y quedan en $0; sobreviven los más recientes con su propio remanente). El "a distribuir"/
 * observación por tanto se guarda por (UN, cuenta, TERCERO, DOCUMENTO), no solo por tercero. Se agrega
 * la columna `documento` y se amplía la llave única. El plano para el ERP sigue saliendo AGREGADO por
 * (UN, cuenta): se suman las líneas. `documento` nullable/'' para no romper lo ya guardado por tercero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bolsa_montos', 'documento')) {
            Schema::table('bolsa_montos', function (Blueprint $table) {
                $table->string('documento')->default('')->after('tercero');
                $table->dropUnique('bolsa_monto_unico');
                $table->unique(['mes', 'anio', 'un_codigo', 'cuenta_14', 'tercero', 'documento'], 'bolsa_monto_unico');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bolsa_montos', 'documento')) {
            Schema::table('bolsa_montos', function (Blueprint $table) {
                $table->dropUnique('bolsa_monto_unico');
                $table->unique(['mes', 'anio', 'un_codigo', 'cuenta_14', 'tercero'], 'bolsa_monto_unico');
                $table->dropColumn('documento');
            });
        }
    }
};
