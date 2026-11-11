<?php
/**
 * Verificación de cuadre de las bolsas de distribución (sin tinker/psysh, para hosts
 * que tienen proc_open deshabilitado). Uso:
 *
 *     php diag_bolsa.php            → mantenimiento e instalaciones, 2026/10
 *     php diag_bolsa.php 2026 9     → el período que se indique
 *
 * Debe cumplirse:  DETALLE (total de saldos)  ==  TARJETA "Otros costos".
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Homologacion;
use App\Models\UnBolsa;
use App\Services\DistribucionService;

$anio = (int) ($argv[1] ?? 2026);
$mes  = (int) ($argv[2] ?? 10);
$periodo = Homologacion::periodo($anio, $mes);
$svc = app(DistribucionService::class);

$fmt = fn ($n) => number_format((float) $n, 0, ',', '.');

foreach (['mantenimiento', 'instalaciones'] as $dep) {
    $codes = UnBolsa::where('activo', true)->where('departamento', $dep)->pluck('codigo')->all();

    // Tarjeta
    $bolsa = collect($svc->bolsasGrandes($dep, $periodo, $anio, $mes))->firstWhere('codigo', $dep);
    $otros = (float) ($bolsa['otros_costos'] ?? 0);
    $mo    = (float) ($bolsa['mano_obra'] ?? 0);
    $total = (float) ($bolsa['total'] ?? 0);
    $aDist = (float) ($bolsa['a_distribuir'] ?? 0);

    // Detalle (lo que suma la tabla "Ver detalle por cuenta")
    $det = $svc->detalleBolsaTerceros($codes, $periodo, $anio, $mes, 'todo');
    $sumDet = 0.0; $sumDist = 0.0;
    foreach ($det as $ls) foreach ($ls as $l) { $sumDet += (float) $l['saldo']; $sumDist += (float) $l['monto_distribuir']; }

    // Desglose de la MO de la tarjeta (sueldo vs seguridad social)
    $dir = 0.0; $ss = 0.0;
    foreach ($svc->manoObraDirectaPorPersona($codes, $anio, $mes) as $per) {
        foreach (($per['buckets'] ?? []) as $b) {
            if (($b['tipo'] ?? '') === 'ss') $ss += (float) $b['monto']; else $dir += (float) $b['monto'];
        }
    }

    $diff = round($sumDet - $otros, 2);
    echo str_repeat('=', 64).PHP_EOL;
    echo strtoupper($dep)." ($anio/$mes)".PHP_EOL;
    echo str_repeat('=', 64).PHP_EOL;
    echo "  Total bolsa .................. ".$fmt($total).PHP_EOL;
    echo "  Mano de obra (tarjeta) ....... ".$fmt($mo)."   [sueldo ".$fmt($dir)." + SS ".$fmt($ss)."]".PHP_EOL;
    echo "  Otros costos (tarjeta) ....... ".$fmt($otros)."   <-- objetivo".PHP_EOL;
    echo "  Detalle (suma de saldos) ..... ".$fmt($sumDet).PHP_EOL;
    echo "  Detalle (suma a distribuir) .. ".$fmt($sumDist).PHP_EOL;
    echo "  A distribuir (tarjeta) ....... ".$fmt($aDist).PHP_EOL;
    echo "  DIFERENCIA detalle - otros ... ".$fmt($diff)."   ".(abs($diff) < 1000 ? '>> CUADRA' : '>> DESCUADRE')." ".PHP_EOL;
    echo PHP_EOL;
}
