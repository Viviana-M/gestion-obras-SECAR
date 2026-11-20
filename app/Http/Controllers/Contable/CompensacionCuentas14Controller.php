<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\DistribucionService;
use App\Support\GeneraPlanoSiesa;
use App\Support\AplicaPlanoCuenta14;
use Illuminate\Http\Request;

/**
 * Plano 2 — COMPENSACIÓN entre cuentas 14 (14↔14).
 *
 * Obras NO cerradas cuyo neto de la cuenta 1420 ≈ 0 pero descuadrado POR DENTRO: una subcuenta en
 * débito y otra en crédito que se compensan, sin que haya que mover costo (la 61 ya está correcta).
 * Se compensan las subcuentas 14 ENTRE SÍ (nunca se toca la 61), para que cada una quede en 0 y la
 * OT se pueda cerrar. Ejemplo R0040284: 14200105 y 14203010 opuestas (± 6.350.000), neto 0.
 */
class CompensacionCuentas14Controller extends Controller
{
    use GeneraPlanoSiesa;
    use AplicaPlanoCuenta14;

    private const TIPO_DOC  = 'CCC';
    private const NIT_SECAR = '890319324';

    /** Bolsas de apoyo (ADM/COM/CNT) que NO son obras: nunca entran al plano. */
    private const BOLSAS_APOYO_EXCLUIDAS = ['ADM00099', 'COM00099', 'CNT00099'];

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = $request->filled('mes') ? (int) $request->get('mes') : null;
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        if ($mes === null || $anio === null) { $mes = null; $anio = null; }

        $filas = $this->filasCompensables($mes, $anio);
        $total = array_sum(array_map(fn ($f) => abs($f['saldo']), $filas));

        $periodos = RegistroFinanciero::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.compensacion-14', compact('filas', 'total', 'mes', 'anio', 'periodos'));
    }

    public function exportarPlano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = $request->filled('mes') ? (int) $request->get('mes') : null;
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        if ($mes === null || $anio === null) { $mes = null; $anio = null; }

        $numeroDoc   = max(1, (int) $request->get('documento', 1));
        $movimientos = $this->construirLineas($mes, $anio, $numeroDoc);
        if (empty($movimientos)) {
            return back()->with('error', 'No hay cuentas 14 cruzadas (compensables) para exportar en este corte.');
        }

        $fecha = ($mes && $anio) ? $this->ultimoDiaDelMesSiesa($anio, $mes) : date('Ymd');
        $obs   = 'COMPENSACION CUENTAS 14' . (($mes && $anio) ? ' - HASTA '.sprintf('%02d/%d', $mes, $anio) : '');

        $archivo = $this->generarPlanoSiesa($movimientos, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $obs);

        return response()->download($archivo, 'PLANO_COMPENSACION_14_'.date('Ymd_His').'.xlsx')
            ->deleteFileAfterSend(true);
    }

    public function aplicar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $datos = $request->validate([
            'corte_mes'    => ['nullable', 'integer', 'between:1,12'],
            'corte_anio'   => ['nullable', 'integer', 'min:2000'],
            'documento'    => ['required', 'integer', 'min:1'],
            'destino_mes'  => ['required', 'integer', 'between:1,12'],
            'destino_anio' => ['required', 'integer', 'min:2000'],
        ], ['documento.required' => 'Indica el número de documento del asiento.']);

        $corteMes  = $datos['corte_mes'] ?? null;
        $corteAnio = $datos['corte_anio'] ?? null;
        if ($corteMes === null || $corteAnio === null) { $corteMes = null; $corteAnio = null; }
        $numeroDoc = (int) $datos['documento'];

        $destinoMes = (int) $datos['destino_mes']; $destinoAnio = (int) $datos['destino_anio'];
        if (\App\Models\CierreConciliacion::estaCerrado($destinoMes, $destinoAnio)) {
            return back()->with('error', "El período destino {$destinoMes}/{$destinoAnio} está cerrado. Reábrelo para aplicar planos ahí.");
        }

        $lineas = $this->construirLineas($corteMes, $corteAnio, $numeroDoc);
        if (empty($lineas)) {
            return back()->with('error', 'No hay cuentas 14 cruzadas (compensables) para aplicar en este corte.');
        }
        if (! $this->planoCuadra($lineas)) {
            return back()->with('error', 'El plano de compensación NO cuadra (débitos ≠ créditos). No se aplicó.');
        }

        $contables = $this->lineasContablesDePlano($lineas);

        $plano = $this->aplicarPlanoEnSistema([
            'tipo'             => 'compensacion_14',
            'distribucion_id'  => null,
            'corte_mes'        => $corteMes,
            'corte_anio'       => $corteAnio,
            'mes'              => $destinoMes,
            'anio'             => $destinoAnio,
            'numero_documento' => $numeroDoc,
            'referencia'       => 'Compensación cuentas 14'
                . (($corteMes && $corteAnio) ? ' — corte '.sprintf('%02d/%d', $corteMes, $corteAnio) : ' — histórico'),
            'origen'           => 'compensacion_14',
            'user_id'          => $request->user()->id,
        ], $contables);

        return back()->with('success',
            "Compensación aplicada: {$plano->n_lineas} movimientos (14↔14) en el período {$plano->mes}/{$plano->anio}. "
            . "Las subcuentas quedaron en 0 sin tocar la 61. Puedes deshacerlo desde «Planos aplicados».");
    }

    /**
     * Saldo por (obra NO cerrada, subcuenta 1420) con corte acumulado al mes. Excluye 1455 y demás
     * 14xx (solo 1420 distribuible) y las U.N. de bolsa/apoyo (ADM00099, …).
     */
    private function baseSaldos14(?int $mes, ?int $anio)
    {
        $excluir = array_values(array_unique(array_merge(UnBolsa::codigos(), self::BOLSAS_APOYO_EXCLUIDAS)));

        return RegistroFinanciero::whereNotIn('codigo_proyecto', ProyectoCerrado::pluck('codigo_proyecto')) // NO cerradas
            ->whereNotIn('codigo_proyecto', $excluir)
            ->where('cuenta_mayor', 'Costos por aplicar')
            ->where('cuenta_contable', 'like', DistribucionService::PREFIJO_BOLSA.'%') // solo 1420
            ->when($mes && $anio, function ($q) use ($mes, $anio) {
                $q->where(function ($sub) use ($mes, $anio) {
                    $sub->where('anio', '<', $anio)
                        ->orWhere(fn ($s) => $s->where('anio', $anio)->where('mes', '<=', $mes));
                });
            })
            ->selectRaw('codigo_proyecto, cuenta_contable, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->orderBy('codigo_proyecto')
            ->orderBy('cuenta_contable')
            ->get();
    }

    /**
     * Obras COMPENSABLES: neto 1420 ≈ 0 pero con subcuentas cruzadas (al menos una en débito y una
     * en crédito). Devuelve [codigo_proyecto => true].
     */
    private function obrasCompensables($rows): array
    {
        $porObra = [];
        foreach ($rows as $r) {
            $porObra[$r->codigo_proyecto][] = round((float) $r->saldo, 2);
        }

        $out = [];
        foreach ($porObra as $cod => $saldos) {
            $neto   = round(array_sum($saldos), 2);
            $hasPos = ! empty(array_filter($saldos, fn ($s) => $s >  0.5));
            $hasNeg = ! empty(array_filter($saldos, fn ($s) => $s < -0.5));
            if (abs($neto) <= 1.0 && $hasPos && $hasNeg) {
                $out[$cod] = true;
            }
        }
        return $out;
    }

    private function filasCompensables(?int $mes, ?int $anio): array
    {
        $rows        = $this->baseSaldos14($mes, $anio);
        $compensables = $this->obrasCompensables($rows);
        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')->pluck('descripcion', 'cuenta_contable');

        $filas = [];
        foreach ($rows as $r) {
            if (! isset($compensables[$r->codigo_proyecto])) continue;
            $saldo = round((float) $r->saldo, 2);
            if (abs($saldo) < 0.5) continue;
            $filas[] = [
                'cuenta'   => $r->cuenta_contable,
                'nombre'   => $nombres[$r->cuenta_contable] ?? '',
                'proyecto' => $r->codigo_proyecto,
                'saldo'    => $saldo,
            ];
        }
        usort($filas, fn ($a, $b) => [$a['proyecto'], $a['cuenta']] <=> [$b['proyecto'], $b['cuenta']]);

        return $filas;
    }

    /**
     * Asiento de compensación 14↔14 (formato SIESA): por cada subcuenta 1420 con saldo material de
     * una obra compensable, una línea que la lleva a 0. Como el neto de la obra ≈ 0, el conjunto
     * cuadra (débitos = créditos) sin tocar la 61.
     */
    private function construirLineas(?int $mes, ?int $anio, int $numeroDoc): array
    {
        $rows         = $this->baseSaldos14($mes, $anio);
        $compensables = $this->obrasCompensables($rows);

        $mov = [];
        foreach ($rows as $r) {
            $cod = $r->codigo_proyecto;
            if (! isset($compensables[$cod])) continue;

            $saldo = round((float) $r->saldo, 2);
            if (abs($saldo) < 0.5) continue;

            $c14 = $r->cuenta_contable;
            $m   = abs($saldo);

            // saldo NEGATIVO -> CR 14 (para subirla a 0) ; POSITIVO -> DB 14 (para bajarla a 0).
            if ($saldo < 0) {
                $mov[] = $this->filaPlano($numeroDoc, $c14, self::NIT_SECAR, $cod, null, 0, $m, self::TIPO_DOC);
            } else {
                $mov[] = $this->filaPlano($numeroDoc, $c14, self::NIT_SECAR, $cod, null, $m, 0, self::TIPO_DOC);
            }
        }

        return $mov;
    }
}
