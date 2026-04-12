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
        $table->decimal('movto_libro2', 18, 2)->default(0)->after('valor_credito');
        $table->string('cuenta_mayor')->nullable()->after('movto_libro2');
        $table->integer('signo_contable')->default(1)->after('cuenta_mayor');
        $table->decimal('estado_er', 18, 2)->default(0)->after('signo_contable');
        $table->string('unidad_unificada')->nullable()->after('estado_er');
        $table->string('periodo')->nullable()->after('unidad_unificada');
    });
}

public function down(): void
{
    Schema::table('registro_financieros', function (Blueprint $table) {
        $table->dropColumn([
            'movto_libro2', 'cuenta_mayor', 'signo_contable',
            'estado_er', 'unidad_unificada', 'periodo'
        ]);
    });
}
};
