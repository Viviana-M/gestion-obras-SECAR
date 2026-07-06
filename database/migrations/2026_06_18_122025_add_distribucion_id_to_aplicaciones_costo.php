<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aplicaciones_costo', function (Blueprint $table) {
            $table->unsignedBigInteger('distribucion_id')->nullable()->after('id')->index();
        });

        // Backfill: un borrador por cada período ya guardado, y se le asignan sus líneas
        $grupos = DB::table('aplicaciones_costo')->whereNull('distribucion_id')
            ->select('mes', 'anio')->distinct()->get();
        foreach ($grupos as $g) {
            $id = DB::table('distribuciones')->insertGetId([
                'mes' => $g->mes, 'anio' => $g->anio, 'estado' => 'borrador',
                'edicion_habilitada' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('aplicaciones_costo')->where('mes', $g->mes)->where('anio', $g->anio)
                ->whereNull('distribucion_id')->update(['distribucion_id' => $id]);
        }
    }
    public function down(): void
    {
        Schema::table('aplicaciones_costo', function (Blueprint $table) {
            $table->dropColumn('distribucion_id');
        });
    }
};