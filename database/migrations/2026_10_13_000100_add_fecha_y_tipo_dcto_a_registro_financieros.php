<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos campos del archivo de cierre (BIABLE) que necesita el detalle por cuenta de las bolsas:
 *   - fecha_docto:     fecha del documento (AAAAMMDD) → se muestra en la columna Fecha y ORDENA el
 *                      cruce FIFO de SECAR (lo más antiguo primero).
 *   - tipo_documento:  tipo del documento (prefijo CCC/FSP/NQ/APP…), para mostrar el documento
 *                      completo como TIPO-NÚMERO (ej. CCC-00002462), no solo el consecutivo.
 * Nullable: los registros ya cargados quedan sin estos datos hasta recargar BIABLE. Columna única
 * fecha_docto (la duplicada fecha_dcto se consolida/elimina en una migración posterior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            if (! Schema::hasColumn('registro_financieros', 'fecha_docto')) {
                $table->date('fecha_docto')->nullable()->after('documento');
            }
            if (! Schema::hasColumn('registro_financieros', 'tipo_documento')) {
                $table->string('tipo_documento', 20)->nullable()->after('documento');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            foreach (['fecha_docto', 'tipo_documento'] as $c) {
                if (Schema::hasColumn('registro_financieros', $c)) $table->dropColumn($c);
            }
        });
    }
};
