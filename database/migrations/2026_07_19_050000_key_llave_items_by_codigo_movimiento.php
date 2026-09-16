<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La llave pasa a ser (tipo_inventario, codigo_movimiento): cruzar por CÓDIGO del
        // motivo es más confiable que por nombre. tipo_movimiento queda como descripción.
        if (! Schema::hasColumn('llave_items_cuenta', 'codigo_movimiento')) {
            Schema::table('llave_items_cuenta', function (Blueprint $table) {
                $table->string('codigo_movimiento')->nullable()->after('tipo_inventario');
            });
        }

        Schema::table('llave_items_cuenta', function (Blueprint $table) {
            $table->dropUnique(['tipo_inventario', 'tipo_movimiento']);
        });

        Schema::table('llave_items_cuenta', function (Blueprint $table) {
            $table->string('tipo_movimiento')->nullable()->change(); // solo descripción
        });

        Schema::table('llave_items_cuenta', function (Blueprint $table) {
            $table->unique(['tipo_inventario', 'codigo_movimiento']);
        });
    }

    public function down(): void
    {
        Schema::table('llave_items_cuenta', function (Blueprint $table) {
            $table->dropUnique(['tipo_inventario', 'codigo_movimiento']);
        });
        Schema::table('llave_items_cuenta', function (Blueprint $table) {
            $table->unique(['tipo_inventario', 'tipo_movimiento']);
        });
        Schema::table('llave_items_cuenta', function (Blueprint $table) {
            $table->dropColumn('codigo_movimiento');
        });
    }
};
