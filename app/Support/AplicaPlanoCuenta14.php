<?php

namespace App\Support;

use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use Illuminate\Support\Facades\DB;

/**
 * Aplica un plano (reverso o distribución) en el sistema: toma las líneas del plano SIESA (las
 * mismas que se exportan a Excel) y crea la PARTIDA DOBLE completa en `registro_financieros` —
 * la pata de cuenta 14 (Costos por aplicar) Y su contrapartida de cuenta 6 (Costos aplicados,
 * 6130…)— para que tanto la 14 como la 6 (y por tanto la utilidad: ingreso − costo aplicado −
 * costo por aplicar) coincidan con el ERP sin recargar BIABLE.
 *
 * Convención de signo/clasificación: idéntica al importador BIABLE (clasificarCuenta + estado_er +
 * signo_contable), de modo que cada pata queda con el mismo signo que si viniera de BIABLE. Solo se
 * insertan las cuentas que el importador llevaría a `registro_financieros` (las de resultado: 14,
 * 6, 4, 5, 7); las de balance (2/3 y activos 1x distintos de 14) se excluyen igual que en el
 * importador (van a saldos_balance, no aquí). NO se toca la clasificación ni el estado_er de los
 * movimientos existentes: solo se insertan filas nuevas marcadas con su `origen` propio y el id de
 * la aplicación que las generó.
 */
trait AplicaPlanoCuenta14
{
    /** Clasificación de cuenta mayor, idéntica a la del importador (MovimientoBiableImport). */
    private function clasificarCuentaPlano(string $cuenta): string
    {
        if (str_starts_with($cuenta, '1420')) return 'Costos por aplicar';
        if (str_starts_with($cuenta, '6'))    return 'Costos aplicados';
        if (str_starts_with($cuenta, '1'))    return 'Activo';
        if (str_starts_with($cuenta, '2'))    return 'Pasivo';
        if (str_starts_with($cuenta, '3'))    return 'Patrimonio';
        if (str_starts_with($cuenta, '4'))    return 'Ingreso';
        if (str_starts_with($cuenta, '5'))    return 'Gasto';
        if (str_starts_with($cuenta, '7'))    return 'Costos';
        return 'No clasificado';
    }

    /**
     * ¿Esta cuenta va a `registro_financieros`? Mismas exclusiones que paraRegistro del importador:
     * las de balance (Activo/Pasivo/Patrimonio) y las no clasificadas no entran (p. ej. la 26 de
     * las provisiones o una 61 sin homologar). Así la partida que se inserta es coherente con BIABLE.
     */
    private function cuentaVaARegistro(string $cuenta): bool
    {
        return ! in_array($this->clasificarCuentaPlano($cuenta),
            ['Activo', 'Pasivo', 'Patrimonio', 'No clasificado'], true);
    }

    /**
     * Filtra las líneas del plano SIESA (arrays con 'cuenta','tercero','unidad','debito','credito')
     * quedándose con las que van a `registro_financieros` (cuentas de resultado: 14, 6, 4, 5, 7).
     *
     * @param  array<int,array<string,mixed>>  $lineasPlano
     * @return array<int,array<string,mixed>>
     */
    protected function lineasContablesDePlano(array $lineasPlano): array
    {
        return array_values(array_filter(
            $lineasPlano,
            fn ($l) => $this->cuentaVaARegistro((string) ($l['cuenta'] ?? ''))
        ));
    }

    /**
     * Verificación de cuadre de la partida doble sobre TODAS las líneas del plano (antes de filtrar):
     * la suma de débitos debe igualar la de créditos.
     *
     * @param  array<int,array<string,mixed>>  $lineasPlano
     */
    protected function planoCuadra(array $lineasPlano): bool
    {
        $deb = round(array_sum(array_map(fn ($l) => (float) ($l['debito'] ?? 0), $lineasPlano)), 2);
        $cre = round(array_sum(array_map(fn ($l) => (float) ($l['credito'] ?? 0), $lineasPlano)), 2);

        return abs($deb - $cre) <= 0.5;
    }

    /**
     * Aplica (idempotente) las líneas contables de un plano. Reemplaza la aplicación vigente del
     * mismo origen (misma distribución, o mismo corte de reverso) borrando sus movimientos, y vuelve
     * a insertar. Devuelve el registro `PlanoAplicado` resultante.
     *
     * @param  array{tipo:string,distribucion_id:?int,corte_mes:?int,corte_anio:?int,mes:int,anio:int,numero_documento:?int,referencia:?string,origen:string,user_id:?int}  $meta
     * @param  array<int,array<string,mixed>>  $lineas  líneas contables ya filtradas (14 y 6)
     */
    protected function aplicarPlanoEnSistema(array $meta, array $lineas): PlanoAplicado
    {
        return DB::transaction(function () use ($meta, $lineas) {
            // Idempotencia: borra la aplicación vigente del mismo plano y sus movimientos.
            $previas = PlanoAplicado::query()
                ->where('tipo', $meta['tipo'])
                ->when($meta['tipo'] === 'distribucion',
                    fn ($q) => $q->where('distribucion_id', $meta['distribucion_id']))
                ->when($meta['tipo'] === 'mo_distribucion',
                    fn ($q) => $q->where('bolsa_un', $meta['bolsa_un'] ?? null)
                        ->where('mes', $meta['mes'])->where('anio', $meta['anio']))
                ->when(in_array($meta['tipo'], ['reverso', 'reverso_apoyo'], true), function ($q) use ($meta) {
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
                // Control "Afectar el sistema": quién lo marcó y cuándo. Solo se persiste un plano
                // cuando afecta (si no, el plano solo se genera para el ERP y no llega aquí).
                'afecta_sistema'   => $meta['afecta_sistema'] ?? true,
                'afectado_por'     => $meta['afectado_por'] ?? ($meta['user_id'] ?? null),
                'afectado_en'      => $meta['afectado_en'] ?? now(),
                'distribucion_id'  => $meta['distribucion_id'] ?? null,
                'bolsa_un'         => $meta['bolsa_un'] ?? null,
                'corte_mes'        => $meta['corte_mes'] ?? null,
                'corte_anio'       => $meta['corte_anio'] ?? null,
                'mes'              => $meta['mes'],
                'anio'             => $meta['anio'],
                'numero_documento' => $meta['numero_documento'] ?? null,
                'documento_ccc'    => $meta['documento_ccc'] ?? null,  // el CCC con que se contabiliza en el ERP
                'referencia'       => $meta['referencia'] ?? null,
                'total_debito'     => 0,
                'total_credito'    => 0,
                'n_lineas'         => 0,
                'user_id'          => $meta['user_id'] ?? null,
            ]);

            // Nombres de obra para que los reportes se lean bien (display, no afecta el saldo).
            $obras   = array_values(array_unique(array_map(fn ($l) => (string) $l['unidad'], $lineas)));
            $nombres = empty($obras) ? collect() : RegistroFinanciero::whereIn('codigo_proyecto', $obras)
                ->selectRaw('codigo_proyecto, MAX(nombre_proyecto) as n, MAX(unidad_unificada) as u')
                ->groupBy('codigo_proyecto')->get()->keyBy('codigo_proyecto');

            $ahora     = now();
            $periodo   = sprintf('%04d%02d', $meta['anio'], $meta['mes']);
            // Documento del movimiento: el CCC con que se contabiliza en el ERP si viene; si no, el n°.
            $documento = ! empty($meta['documento_ccc'])
                ? (string) $meta['documento_ccc']
                : ($meta['numero_documento'] ? (string) $meta['numero_documento'] : null);
            $totalDeb  = 0.0;
            $totalCre  = 0.0;
            $rows = [];

            foreach ($lineas as $l) {
                $cuenta  = (string) $l['cuenta'];
                $obra    = (string) $l['unidad'];
                $debito  = round((float) ($l['debito'] ?? 0), 2);
                $credito = round((float) ($l['credito'] ?? 0), 2);
                $movto   = round($debito - $credito, 2);
                $tercero = isset($l['tercero']) && $l['tercero'] !== null ? (string) $l['tercero'] : null;

                // Mismo cálculo de signo/estado_er que el importador BIABLE, por cuenta:
                //   estado_er = (14x | 61x | 4x) ? -movto : movto ;  signo = (1420|6130) ? -1 : 1.
                $estadoER = (str_starts_with($cuenta, '14') || str_starts_with($cuenta, '61') || str_starts_with($cuenta, '4'))
                    ? -$movto : $movto;
                $signo = (str_starts_with($cuenta, '1420') || str_starts_with($cuenta, '6130')) ? -1 : 1;

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
                    'cuenta_mayor'      => $this->clasificarCuentaPlano($cuenta),
                    'signo_contable'    => $signo,
                    'estado_er'         => $estadoER,
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
