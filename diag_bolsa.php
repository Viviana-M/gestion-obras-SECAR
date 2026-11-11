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
    $otrosNeto = (float) ($bolsa['otros_neto'] ?? $otros);
    $rev   = (float) ($bolsa['total_reversado'] ?? 0);
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

    $diff = round($sumDet - $otrosNeto, 2);
    echo str_repeat('=', 64).PHP_EOL;
    echo strtoupper($dep)." ($anio/$mes)".PHP_EOL;
    echo str_repeat('=', 64).PHP_EOL;
    echo "  Total bolsa ...................... ".$fmt($total).PHP_EOL;
    echo "  Mano de obra extraída (tarjeta) .. ".$fmt($mo)."   [nómina bruta: sueldo ".$fmt($dir)." + SS ".$fmt($ss)."]".PHP_EOL;
    echo "  Otros costos disponible .......... ".$fmt($otros).PHP_EOL;
    echo "  Reversado de más (solo lectura) .. ".$fmt($rev).PHP_EOL;
    echo "  Otros costos NETO (tarjeta) ...... ".$fmt($otrosNeto)."   <-- objetivo (lo que ve la tarjeta)".PHP_EOL;
    echo "  Detalle (suma de saldos) ......... ".$fmt($sumDet).PHP_EOL;
    echo "  Detalle (suma a distribuir) ...... ".$fmt($sumDist).PHP_EOL;
    echo "  A distribuir (tarjeta) ........... ".$fmt($aDist).PHP_EOL;
    echo "  DIFERENCIA detalle - otros neto .. ".$fmt($diff)."   ".(abs($diff) < 1000 ? '>> CUADRA' : '>> DESCUADRE')." ".PHP_EOL;
    echo PHP_EOL;

    if ($dep !== 'mantenimiento') continue;

    // ---- Diagnóstico por llave: ¿cuánto quiere quitar la tarjeta vs cuánto QUEDA en el detalle? ----
    // Saldo que quedó en el detalle (ya restada la MO) indexado igual que el cruce del sueldo.
    $detKey = [];   // un|cuenta|tercero  => saldo restante
    $detNom = [];   // tercero (dcto o razon) => saldo restante (para ubicar SS por fondo)
    foreach ($det as $ls) foreach ($ls as $l) {
        $terc = trim((string) $l['tercero_dcto']) ?: trim((string) $l['razon_social']);
        $k = $l['un_codigo'].'|'.$l['cuenta_14'].'|'.$terc;
        $detKey[$k] = ($detKey[$k] ?? 0) + (float) $l['saldo'];
        foreach ([trim((string) $l['tercero_dcto']), trim((string) $l['razon_social'])] as $c) {
            if ($c !== '') $detNom[$c] = ($detNom[$c] ?? 0) + (float) $l['saldo'];
        }
    }

    // Buckets de la tarjeta: sueldo por llave exacta, SS por fondo (nit y nombre).
    $sal = []; $ssF = [];
    foreach ($svc->manoObraDirectaPorPersona($codes, $anio, $mes) as $per) {
        foreach (($per['buckets'] ?? []) as $b) {
            if (($b['tipo'] ?? '') === 'ss') {
                $nom = trim((string) ($b['tercero_nom'] ?? '')) ?: trim((string) ($b['tercero_nit'] ?? '')) ?: trim((string) $b['tercero']);
                $ssF[$nom] = ($ssF[$nom] ?? 0) + (float) $b['monto'];
            } else {
                $terc = trim((string) $b['tercero']);
                $k = trim((string) $b['un']).'|'.trim((string) $b['cuenta']).'|'.$terc;
                $sal[$k] = ($sal[$k] ?? 0) + (float) $b['monto'];
            }
        }
    }
    arsort($sal); arsort($ssF);

    echo "  -- SUELDO: lo que la tarjeta quita vs lo que AÚN queda en el detalle con esa misma llave --".PHP_EOL;
    $faltaSal = 0;
    foreach ($sal as $k => $m) {
        $queda = $detKey[$k] ?? 0;
        if ($queda > 1) $faltaSal += min($m, $queda);
        echo "     ".str_pad($k, 40)." tarjeta=".str_pad($fmt($m), 12)." queda_detalle=".$fmt($queda).PHP_EOL;
    }
    echo "  -- SS por fondo: monto maestro vs saldo que queda bajo ese fondo --".PHP_EOL;
    foreach ($ssF as $nom => $m) {
        $queda = $detNom[$nom] ?? 0;
        echo "     ".str_pad(substr($nom, 0, 38), 40)." tarjeta=".str_pad($fmt($m), 12)." queda_detalle=".$fmt($queda).PHP_EOL;
    }
    echo PHP_EOL;
}
