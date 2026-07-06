<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observaciones_obra', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proyecto');
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->text('observacion')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->unique(['codigo_proyecto', 'mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observaciones_obra');
    }
};