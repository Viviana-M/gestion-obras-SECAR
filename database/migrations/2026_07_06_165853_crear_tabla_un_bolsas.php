<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('un_bolsas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();      // INS00099, MTO00001...
            $table->string('nombre')->nullable();          // descripción
            $table->string('departamento', 20);            // mantenimiento | instalaciones
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Precargar las bolsas conocidas
        $ahora = now();
        $bolsas = [
            // Instalaciones
            ['INS00001', 'Instalaciones - bolsa 1', 'instalaciones'],
            ['INS00002', 'Instalaciones - bolsa 2', 'instalaciones'],
            ['INS00003', 'Instalaciones - bolsa 3', 'instalaciones'],
            ['INS00004', 'Instalaciones - bolsa 4', 'instalaciones'],
            ['INS00005', 'Facturas de logística para instalaciones', 'instalaciones'],
            ['INS00006', 'Instalaciones - bolsa 6', 'instalaciones'],
            ['INS00099', 'Instalaciones administración', 'instalaciones'],
            // Mantenimiento
            ['MTO00001', 'TNL capacitaciones', 'mantenimiento'],
            ['MTO00002', 'TNL incapacidades', 'mantenimiento'],
            ['MTO00003', 'TNL permiso remunerado', 'mantenimiento'],
            ['MTO00004', 'Mantenimiento - bolsa 4', 'mantenimiento'],
            ['MTO00005', 'Facturas de logística para mantenimiento', 'mantenimiento'],
            ['MTO00006', 'Mantenimiento - bolsa 6', 'mantenimiento'],
            ['MTO00007', 'Mantenimiento - bolsa 7', 'mantenimiento'],
            ['MTO00008', 'Mantenimiento - bolsa 8', 'mantenimiento'],
            ['MTO00009', 'Vacaciones', 'mantenimiento'],
            ['MTO00099', 'Mantenimiento administración', 'mantenimiento'],
        ];

        $filas = [];
        foreach ($bolsas as $b) {
            $filas[] = [
                'codigo' => $b[0], 'nombre' => $b[1], 'departamento' => $b[2],
                'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }
        DB::table('un_bolsas')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('un_bolsas');
    }
};