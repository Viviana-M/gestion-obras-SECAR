<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Vigencia y versionado de homologaciones.
 *
 * Antes: una fila por cuenta 14 (unique). Editar la cuenta 61 reescribía el pasado.
 * Ahora: una fila por cada VERSIÓN de la cuenta 14, con período de vigencia AAAAMM.
 *
 * Las filas existentes se migran con vigente_desde = 200001 y vigente_hasta = NULL,
 * es decir, siguen aplicando a todo el histórico. Nada cambia hasta que alguien edite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homologaciones', function (Blueprint $table) {
            // Período de vigencia, en formato AAAAMM (202606 = junio 2026).
            // vigente_hasta NULL = es la versión vigente hoy.
            $table->unsignedInteger('vigente_desde')->default(200001)->after('estructura');
            $table->unsignedInteger('vigente_hasta')->nullable()->after('vigente_desde');
            $table->unsignedInteger('version')->default(1)->after('vigente_hasta');

            // Trazabilidad del cambio
            $table->unsignedBigInteger('reemplaza_a')->nullable()->after('version');
            $table->text('motivo')->nullable()->after('reemplaza_a');

            // Plano de reclasificación (opcional): corrige los costos YA asentados
            // en la cuenta 61 anterior, con un asiento nuevo. No reescribe el pasado.
            $table->boolean('requiere_reclasificacion')->default(false)->after('motivo');
            $table->unsignedInteger('reclasificar_desde')->nullable()->after('requiere_reclasificacion');
            $table->timestamp('reclasificado_at')->nullable()->after('reclasificar_desde');
            $table->unsignedBigInteger('reclasificado_por')->nullable()->after('reclasificado_at');
        });

        // La cuenta 14 deja de ser única: ahora puede tener varias versiones.
        Schema::table('homologaciones', function (Blueprint $table) {
            $table->dropUnique('homologaciones_cuenta_14_unique');
        });

        Schema::table('homologaciones', function (Blueprint $table) {
            // Una sola versión por cuenta y período de inicio.
            $table->unique(['cuenta_14', 'vigente_desde'], 'homol_cuenta_periodo_unique');
            // Índice de búsqueda por vigencia.
            $table->index(['cuenta_14', 'vigente_desde', 'vigente_hasta'], 'homol_vigencia_idx');
            $table->index('vigente_hasta', 'homol_vigente_hasta_idx');
        });

        // Asegurar el estado inicial de las filas que ya existían.
        DB::table('homologaciones')->update([
            'vigente_desde' => 200001,
            'vigente_hasta' => null,
            'version'       => 1,
        ]);
    }

    public function down(): void
    {
        Schema::table('homologaciones', function (Blueprint $table) {
            $table->dropIndex('homol_vigente_hasta_idx');
            $table->dropIndex('homol_vigencia_idx');
            $table->dropUnique('homol_cuenta_periodo_unique');
        });

        // Al revertir, dejamos solo la versión vigente de cada cuenta
        // para poder restaurar el índice único.
        DB::statement('
            DELETE h1 FROM homologaciones h1
            INNER JOIN homologaciones h2
              ON h1.cuenta_14 = h2.cuenta_14
             AND h1.id < h2.id
        ');

        Schema::table('homologaciones', function (Blueprint $table) {
            $table->dropColumn([
                'vigente_desde', 'vigente_hasta', 'version', 'reemplaza_a', 'motivo',
                'requiere_reclasificacion', 'reclasificar_desde',
                'reclasificado_at', 'reclasificado_por',
            ]);
            $table->unique('cuenta_14');
        });
    }
};