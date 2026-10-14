<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almacenamiento del "a distribuir"/observaciones por (UN, cuenta, TERCERO). Antes era por (UN,
 * cuenta); ahora se guarda por tercero para poder mostrar, editar y recordar cada línea del detalle
 * de la bolsa. El plano para contabilidad/ERP sigue saliendo AGREGADO por (UN, cuenta): simplemente
 * se suman las líneas de tercero. `tercero` nullable para no romper los registros por cuenta ya
 * guardados (tercero = '' representa el agregado antiguo de la cuenta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bolsa_montos', function (Blueprint $table) {
            $table->string('tercero')->default('')->after('cuenta_14');
            $table->dropUnique('bolsa_monto_unico');
            $table->unique(['mes', 'anio', 'un_codigo', 'cuenta_14', 'tercero'], 'bolsa_monto_unico');
        });
    }

    public function down(): void
    {
        Schema::table('bolsa_montos', function (Blueprint $table) {
            $table->dropUnique('bolsa_monto_unico');
            $table->unique(['mes', 'anio', 'un_codigo', 'cuenta_14'], 'bolsa_monto_unico');
            $table->dropColumn('tercero');
        });
    }
};
