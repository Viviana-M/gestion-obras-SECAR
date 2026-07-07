<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            if (!Schema::hasColumn('registro_financieros', 'tercero_dcto')) {
                $table->string('tercero_dcto', 30)->nullable()->after('descripcion');
            }
            if (!Schema::hasColumn('registro_financieros', 'razon_social')) {
                $table->string('razon_social')->nullable()->after('tercero_dcto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            foreach (['tercero_dcto', 'razon_social'] as $col) {
                if (Schema::hasColumn('registro_financieros', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};