<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ficha_proyectos', function (Blueprint $table) {
            $table->string('area')->nullable()->after('nombre_obra');
        });
    }

    public function down(): void
    {
        Schema::table('ficha_proyectos', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }
};