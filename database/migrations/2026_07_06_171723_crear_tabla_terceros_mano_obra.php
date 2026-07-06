<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terceros_mano_obra', function (Blueprint $table) {
            $table->id();
            $table->string('cedula', 20)->unique();
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $ahora = now();
        $personas = [
            ['16706707',   'ARGOTE MONTENEGRO CARLOS ANTONIO'],
            ['1007967330', 'MANZANARES BENITEZ JOSE LUIS'],
            ['79493124',   'MURIEL BALANTA FAUSTINO'],
            ['1062286197', 'TRUJILLO MORALES JOHAN EMMANUEL'],
        ];

        $filas = [];
        foreach ($personas as $p) {
            $filas[] = [
                'cedula' => $p[0], 'nombre' => $p[1], 'activo' => true,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }
        DB::table('terceros_mano_obra')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('terceros_mano_obra');
    }
};