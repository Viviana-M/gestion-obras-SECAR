<?php

namespace App\Support;

use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use Illuminate\Support\Facades\DB;

/**
 * Aplica un plano (reverso o distribución) en la cuenta 14 del sistema: toma las líneas del plano
 * SIESA (las mismas que se exportan a Excel), se queda con las que afectan "Costos por aplicar"
 * (cuentas 1420…) y crea los movimientos equivalentes en `registro_financieros`.
 *
 * Convención de signo: idéntica al importador BIABLE (MovimientoBiableImport). Para la cuenta 14
 * el saldo se lee como SUM(estado_er) con estado_er = -(débito − crédito), de modo que un crédito
 * a la 14 (una reversión/distribución) deja estado_er positivo y baja el pendiente por distribuir.
 * NO se tocan la clasificación ni el estado_er de los movimientos existentes: solo se insertan
 * filas nuevas marcadas con su `origen` propio y el id de la aplicación que las generó.
 */
trait AplicaPlanoCuenta14
{
    /**
     * Cuentas que afectan "Costos por aplicar" (la cuenta 14 que ve Operaciones). Las líneas de un
     * plano solo tocan cuentas 14, 61 y 26; nos quedamos con las 14 (todas sus subcuentas: 1420,
     * 1435, …) y descartamos las 61/26, que no cuentan en el saldo de la 14.
     */
    private function afectaCuenta14(string $cuenta): bool
    {
        return str_starts_with($cuenta, '14');
    }

    /**
     * Filtra, de las líneas del plano SIESA (arrays con 'cuenta','tercero','unidad','debito',
     * 'credito'), solo las que afectan la cuenta 14.
     *
     * @param  array<int,array<string,mixed>>  $lineasPlano
     * @return array<int,array<string,mixed>>
     */
    protected function lineasCuenta14DePlano(array $lineasPlano): array
    {
        return array_values(array_filter(
            $lineasPlano,
            fn ($l) => $this->afectaCuenta14((string) ($l['cuenta'] ?? ''))
        ));
    }

    /**
     * Aplica (idempotente) las líneas de cuenta 14 de un plano. Reemplaza la aplicación vigente del
     * mismo origen (misma distribución, o mismo corte de reverso) borrando sus movimientos, y vuelve
     * a insertar. Devuelve el registro `PlanoAplicado` resultante.
     *
     * @param  array{tipo:string,distribucion_id:?int,corte_mes:?int,corte_anio:?int,mes:int,anio:int,numero_documento:?int,referencia:?string,origen:string,user_id:?int}  $meta
     * @param  array<int,array<string,mixed>>  $lineas14
     */
    protected function aplicarPlanoEnSistema(array $meta, array $lineas14): PlanoAplicado
    {
        return DB::transaction(function () use ($meta, $lineas14) {
            // Idempotencia: borra la aplicación vigente del mismo plano y sus movimientos.
            $previas = PlanoAplicado::query()
                ->where('tipo', $meta['tipo'])
                ->when($meta['tipo'] === 'distribucion',
                    fn ($q) => $q->where('distribucion_id', $meta['distribucion_id']))
                ->when($meta['tipo'] === 'reverso', function ($q) use ($meta) {
                    $q->whereNull('distribucion_id');
                    isset($meta['corte_mes']) && $meta['corte_mes'] !== null
                        ? $q->where('corte_mes', $meta['corte_mes'])
                        : $q->whereNull('corte_mes');
                    isset($meta['corte_anio']) && $meta['corte_anio'] !== null
                        ? $q->where('corte_anio', $meta['corte_anio'])
                        : $q->whereNull('corte_anio');
                })
                ->get();

            foreach ($previas as $p) {
                RegistroFinanciero::where('plano_aplicado_id', $p->id)->delete();
                $p->delete();
            }

            $plano = PlanoAplicado::create([
                'tipo'             => $meta['tipo'],
                'distribucion_id'  => $meta['distribucion_id'] ?? null,
                'corte_mes'        => $meta['corte_mes'] ?? null,
                'corte_anio'       => $meta['corte_anio'] ?? null,
                'mes'              => $meta['mes'],
                'anio'             => $meta['anio'],
                'numero_documento' => $meta['numero_documento'] ?? null,
                'referencia'       => $meta['referencia'] ?? null,
                'total_debito'     => 0,
                'total_credito'    => 0,
                'n_lineas'         => 0,
                'user_id'          => $meta['user_id'] ?? null,
            ]);

            // Nombres de obra para que los reportes se lean bien (display, no afecta el saldo).
            $obras   = array_values(array_unique(array_map(fn ($l) => (string) $l['unidad'], $lineas14)));
            $nombres = empty($obras) ? collect() : RegistroFinanciero::whereIn('codigo_proyecto', $obras)
                ->selectRaw('codigo_proyecto, MAX(nombre_proyecto) as n, MAX(unidad_unificada) as u')
                ->groupBy('codigo_proyecto')->get()->keyBy('codigo_proyecto');

            $ahora    = now();
            $periodo  = sprintf('%04d%02d', $meta['anio'], $meta['mes']);
            $documento = $meta['numero_documento'] ? (string) $meta['numero_documento'] : null;
            $totalDeb = 0.0;
            $totalCre = 0.0;
            $rows = [];

            foreach ($lineas14 as $l) {
                $cuenta  = (string) $l['cuenta'];
                $obra    = (string) $l['unidad'];
                $debito  = round((float) ($l['debito'] ?? 0), 2);
                $credito = round((float) ($l['credito'] ?? 0), 2);
                $movto   = round($debito - $credito, 2);
                $tercero = isset($l['tercero']) && $l['tercero'] !== null ? (string) $l['tercero'] : null;

                $totalDeb += $debito;
                $totalCre += $credito;

                $rows[] = [
                    'codigo_proyecto'   => $obra,
                    'nombre_proyecto'   => $nombres[$obra]->n ?? null,
                    'cuenta_contable'   => $cuenta,
                    'descripcion'       => $meta['referencia'] ?? null,
                    'tercero_dcto'      => $tercero,
                    'razon_social'      => null,
                    'documento'         => $documento,
                    'dedup_hash'        => null,
                    'valor_debito'      => $debito,
                    'valor_credito'     => $credito,
                    'movto_libro2'      => $movto,
                    // Clasificación de la cuenta 14, igual que el importador (no se altera nada existente).
                    'cuenta_mayor'      => 'Costos por aplicar',
                    'signo_contable'    => (str_starts_with($cuenta, '1420') || str_starts_with($cuenta, '6130')) ? -1 : 1,
                    // estado_er de la 14 = -(débito − crédito), misma convención que BIABLE.
                    'estado_er'         => -$movto,
                    'unidad_unificada'  => $nombres[$obra]->u ?? $obra,
                    'periodo'           => $periodo,
                    'mes'               => $meta['mes'],
                    'anio'              => $meta['anio'],
                    'origen'            => $meta['origen'],
                    'plano_aplicado_id' => $plano->id,
                    'created_at'        => $ahora,
                    'updated_at'        => $ahora,
                ];
            }

            if (! empty($rows)) {
                DB::table('registro_financieros')->insert($rows);
            }

            $plano->update([
                'total_debito'  => round($totalDeb, 2),
                'total_credito' => round($totalCre, 2),
                'n_lineas'      => count($rows),
            ]);

            return $plano->fresh();
        });
    }
}
