<?php

namespace App\Console\Commands;

use App\Models\ObraCliente;
use App\Models\RegistroFinanciero;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deriva el NIT del cliente de cada obra a partir de sus líneas de ingreso.
 *
 * REGLAS
 *   · Se miran solo las líneas con cuenta_mayor = 'Ingreso'.
 *   · Se excluyen los NIT que no son clientes: la propia SECAR y "Cuantías menores".
 *   · UN solo NIT   -> se asigna, origen = 'derivado'.
 *   · VARIOS NIT    -> se deja SIN NIT y se marca para revisar.
 *   · NINGÚN NIT    -> se deja sin NIT y se marca para revisar.
 *   · Lo que alguien escribió a mano (origen = 'manual') NUNCA se sobrescribe.
 */
class ClientesDerivar extends Command
{
    protected $signature = 'clientes:derivar
                            {--reporte : Solo muestra lo que haría, sin escribir en la base}
                            {--force   : No pide confirmación}';

    protected $description = 'Deriva el NIT del cliente de cada obra desde sus líneas de ingreso';

    public function handle(): int
    {
        $soloReporte = (bool) $this->option('reporte');

        $this->newLine();
        $this->line('Leyendo las líneas de ingreso…');

        // NIT por obra, con su facturación acumulada.
        $filas = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->whereNotNull('tercero_dcto')
            ->where('tercero_dcto', '<>', '')
            ->whereNotIn('tercero_dcto', ObraCliente::NIT_EXCLUIDOS)
            ->selectRaw('codigo_proyecto, tercero_dcto, MAX(razon_social) as razon_social,
                         ROUND(SUM(ABS(estado_er)), 2) as facturado')
            ->groupBy('codigo_proyecto', 'tercero_dcto')
            ->orderBy('codigo_proyecto')
            ->get()
            ->groupBy('codigo_proyecto');

        // Todas las obras que tienen ingreso (aunque sea sin tercero).
        $todasLasObras = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->distinct()
            ->pluck('codigo_proyecto');

        // Lo escrito a mano no se toca.
        $manuales = ObraCliente::where('origen', 'manual')
            ->pluck('codigo_proyecto')
            ->flip();

        $unico = []; $varios = []; $sinNit = []; $protegidas = 0;

        foreach ($todasLasObras as $cod) {
            $cod = (string) $cod;

            if (isset($manuales[$cod])) { $protegidas++; continue; }

            $candidatos = $filas[$cod] ?? collect();

            if ($candidatos->isEmpty()) {
                $sinNit[] = $cod;
            } elseif ($candidatos->count() === 1) {
                $unico[$cod] = $candidatos->first();
            } else {
                $varios[$cod] = $candidatos->sortByDesc('facturado')->values();
            }
        }

        // ── Resumen ──
        $this->newLine();
        $this->table(
            ['Resultado', 'Obras'],
            [
                ['Un solo cliente -> se asigna',         number_format(count($unico),  0, ',', '.')],
                ['Varios clientes -> a revisar',         number_format(count($varios), 0, ',', '.')],
                ['Sin NIT en los ingresos -> a revisar', number_format(count($sinNit), 0, ',', '.')],
                ['Editadas a mano -> no se tocan',       number_format($protegidas,    0, ',', '.')],
            ]
        );

        // ── Los conflictos, con detalle ──
        if (!empty($varios)) {
            $this->newLine();
            $this->warn('Obras con VARIOS clientes (quedan sin NIT, hay que revisarlas):');
            $this->newLine();

            $detalle = [];
            foreach ($varios as $cod => $cands) {
                foreach ($cands as $i => $c) {
                    $detalle[] = [
                        $i === 0 ? $cod : '',
                        $c->tercero_dcto,
                        \Illuminate\Support\Str::limit((string) $c->razon_social, 34),
                        '$' . number_format((float) $c->facturado, 0, ',', '.'),
                    ];
                }
                $detalle[] = new \Symfony\Component\Console\Helper\TableSeparator();
            }
            array_pop($detalle);

            $this->table(['Obra', 'NIT', 'Razon social', 'Facturado'], $detalle);
            $this->line('Probablemente sean errores de digitacion en SIESA. Revisalas con contabilidad.');
        }

        if ($soloReporte) {
            $this->newLine();
            $this->info('Modo reporte: no se escribio nada.');
            return self::SUCCESS;
        }

        $aEscribir = count($unico) + count($varios) + count($sinNit);

        $this->newLine();
        if (!$this->option('force') && !$this->confirm("Guardar el cliente de {$aEscribir} obra(s)?", false)) {
            $this->line('Cancelado. No se toco nada.');
            return self::SUCCESS;
        }

        // ── Escribir ──
        $ahora = now();
        $n = 0;

        DB::transaction(function () use ($unico, $varios, $sinNit, $ahora, &$n) {

            foreach ($unico as $cod => $c) {
                ObraCliente::updateOrCreate(
                    ['codigo_proyecto' => $cod],
                    [
                        'nit'             => (string) $c->tercero_dcto,
                        'razon_social'    => (string) $c->razon_social,
                        'origen'          => 'derivado',
                        'revisar'         => false,
                        'motivo_revision' => null,
                        'candidatos'      => null,
                        'facturado'       => (float) $c->facturado,
                        'derivado_at'     => $ahora,
                    ]
                );
                $n++;
            }

            foreach ($varios as $cod => $cands) {
                ObraCliente::updateOrCreate(
                    ['codigo_proyecto' => $cod],
                    [
                        'nit'             => null,   // a proposito: mejor vacio que adivinado
                        'razon_social'    => null,
                        'origen'          => 'derivado',
                        'revisar'         => true,
                        'motivo_revision' => 'Los ingresos tienen ' . $cands->count() . ' NIT distintos',
                        'candidatos'      => $cands->map(fn($c) => [
                            'nit'          => (string) $c->tercero_dcto,
                            'razon_social' => (string) $c->razon_social,
                            'facturado'    => (float) $c->facturado,
                        ])->all(),
                        'facturado'       => 0,
                        'derivado_at'     => $ahora,
                    ]
                );
                $n++;
            }

            foreach ($sinNit as $cod) {
                ObraCliente::updateOrCreate(
                    ['codigo_proyecto' => $cod],
                    [
                        'nit'             => null,
                        'razon_social'    => null,
                        'origen'          => 'derivado',
                        'revisar'         => true,
                        'motivo_revision' => 'Ninguna linea de ingreso trae NIT de cliente',
                        'candidatos'      => null,
                        'facturado'       => 0,
                        'derivado_at'     => $ahora,
                    ]
                );
                $n++;
            }
        });

        $this->newLine();
        $this->info("Listo: {$n} obra(s) actualizadas.");

        $pendientes = ObraCliente::where('revisar', true)->count();
        if ($pendientes > 0) {
            $this->warn("{$pendientes} obra(s) quedaron SIN NIT y hay que revisarlas a mano.");
            $this->line('Esas obras no pueden ir al plano de SIESA hasta que tengan cliente.');
        }

        return self::SUCCESS;
    }
}