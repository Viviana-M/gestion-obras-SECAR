<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos campos del archivo de cierre (BIABLE) que necesita el detalle por cuenta de las bolsas:
 *   - fecha_dcto:      fecha del documento (col "Fecha_dcto", AAAAMMDD) → se muestra en la columna
 *                      Fecha y ORDENA el cruce FIFO de SECAR (lo más antiguo primero).
 *   - tipo_documento:  tipo del documento (prefijo CCC/FSP/NQ/APP…), para mostrar el documento
 *                      completo como TIPO-NÚMERO (ej. CCC-00000339), no solo el consecutivo.
 * Nullable: los registros ya cargados quedan sin estos datos hasta recargar BIABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->date('fecha_dcto')->nullable()->after('documento');
            $table->string('tipo_documento', 20)->nullable()->after('documento');
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->dropColumn(['fecha_dcto', 'tipo_documento']);
        });
    }
};
