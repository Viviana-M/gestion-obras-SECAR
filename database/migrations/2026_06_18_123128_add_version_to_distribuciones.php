<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('anio');
        });

        // Numerar las versiones existentes por período, según orden de creación
        $periodos = DB::table('distribuciones')->select('mes', 'anio')->distinct()->get();
        foreach ($periodos as $p) {
            $rows = DB::table('distribuciones')->where('mes', $p->mes)->where('anio', $p->anio)
                ->orderBy('created_at')->orderBy('id')->get();
            $i = 0;
            foreach ($rows as $r) {
                $i++;
                DB::table('distribuciones')->where('id', $r->id)->update(['version' => $i]);
            }
        }
    }
    public function down(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};