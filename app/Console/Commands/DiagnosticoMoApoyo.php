<?php

namespace App\Console\Commands;

use App\Models\Homologacion;
use App\Models\ManoObraDirecta;
use App\Models\RegistroFinanciero;
use App\Services\RedistribucionMoEspecialService;
use Illuminate\Console\Command;

/**
 * Diagnóstico del cruce MO Apoyo: revisa por qué el plano no encuentra mano de obra.
 *   php artisan mo:diagnostico 8 2026
 */
class DiagnosticoMoApoyo extends Command
{
    protected $signature = 'mo:diagnostico {mes=8} {anio=2026}';

    protected $description = 'Revisa el cruce de MO Apoyo (cierre + autoliquidación + maestro + homologación)';

    public function handle(RedistribucionMoEspecialService $svc): int
    {
        $mes = (int) $this->argument('mes');
        $anio = (int) $this->argument('anio');
        $this->info("=== Diagnóstico MO Apoyo {$mes}/{$anio} ===");

        $cuentasMO = RedistribucionMoEspecialService::CUENTAS_MO;

        $rf   = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->count();
        $rfMo = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)
            ->whereIn('cuenta_contable', $cuentasMO)->count();
        $this->line("1) Cierre (registro_financieros): {$rf} filas del período; de ellas {$rfMo} son cuentas de MO (14).");
        if ($rf === 0) {
            $this->error('   ⚠ El cierre NO está cargado para este período. Vuelve a cargar el cierre (Contabilidad → Carga).');
        }

        $mod = ManoObraDirecta::where('activo', true)->count();
        $this->line("2) Mano de Obra Directa (activos): {$mod} personas.");
        $this->line('   Cédulas (muestra): '.ManoObraDirecta::where('activo', true)->pluck('cedula')->take(8)->implode(', '));

        $homol = Homologacion::count();
        $this->line("3) Homologaciones: {$homol} registros.");

        // Terceros (personas) en las líneas de MO del cierre — para comparar con el maestro.
        $tercerosMo = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)
            ->whereIn('cuenta_contable', $cuentasMO)
            ->distinct()->pluck('tercero_dcto')->filter()->values();
        $this->line("4) Terceros distintos en líneas de MO del cierre: {$tercerosMo->count()}.");
        $this->line('   Muestra: '.$tercerosMo->take(8)->implode(', '));

        $costo = $svc->costoPorPersona($mes, $anio);
        $conMo = collect($costo)->filter(fn ($p) => (float) $p['total'] > 0.005);
        $sum   = collect($costo)->sum(fn ($p) => (float) $p['total']);
        $this->line("5) costoPorPersona: {$conMo->count()} personas con MO > 0 (de ".count($costo)." en el maestro). Total: ".number_format($sum, 0));
        foreach ($conMo->take(8) as $ced => $p) {
            $this->line("   - {$ced} {$p['nombre']}: directo=".number_format($p['directo'], 0).' ss='.number_format($p['ss'], 0));
        }

        $mov = $svc->movimientosRedistribucion($mes, $anio);
        $this->line('6) movimientosRedistribucion (líneas del plano): '.count($mov));

        if (empty($mov)) {
            $this->error('   ⚠ Vacío → por eso sale "no hay mano de obra para reclasificar".');
            if ($rf === 0) {
                $this->line('   Causa: falta cargar el CIERRE del período.');
            } elseif ($conMo->isEmpty()) {
                $this->line('   Causa: ninguna cédula del maestro coincide con los terceros de las líneas de MO del cierre (compara el punto 2 con el 4).');
            }
        } else {
            $this->info('   ✅ El plano SÍ tiene movimientos: '.count($mov).' líneas.');
        }

        return 0;
    }
}
