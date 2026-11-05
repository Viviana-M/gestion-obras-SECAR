<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unifica el maestro de personal de mano de obra en UNO solo: mano_obra_directa.
 * mano_obra_especial era un duplicado sin uso real (sin controlador, vista ni rutas).
 * Se migran sus filas (por cédula, sin duplicar) a mano_obra_directa y se elimina la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mano_obra_especial') && Schema::hasTable('mano_obra_directa')) {
            $existentes = DB::table('mano_obra_directa')->pluck('cedula')
                ->map(fn ($c) => trim((string) $c))->flip();

            foreach (DB::table('mano_obra_especial')->get() as $r) {
                $ced = trim((string) $r->cedula);
                if ($ced === '' || isset($existentes[$ced])) {
                    continue; // ya existe en el maestro unificado
                }
                DB::table('mano_obra_directa')->insert([
                    'cedula'     => $ced,
                    'nombre'     => $r->nombre,
                    'activo'     => $r->activo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $existentes[$ced] = true;
            }
        }

        Schema::dropIfExists('mano_obra_especial');
    }

    public function down(): void
    {
        // Se recrea vacía (los datos ya viven en mano_obra_directa).
        if (! Schema::hasTable('mano_obra_especial')) {
            Schema::create('mano_obra_especial', function (Blueprint $table) {
                $table->id();
                $table->string('cedula')->unique();
                $table->string('nombre');
                $table->boolean('activo')->default(true);
                $table->foreignId('user_id')->nullable();
                $table->timestamps();
                $table->index('activo');
            });
        }
    }
};
