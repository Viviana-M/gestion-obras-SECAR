<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\DistribucionService;
use App\Support\DescomponeSaldos14;
use App\Support\GeneraPlanoSiesa;
use App\Support\AplicaPlanoCuenta14;
use Illuminate\Http\Request;

/**
 * Plano 2 — COMPENSACIÓN entre cuentas 14 (14↔14).
 *
 * Obras (abiertas O cerradas) cuyo neto de la cuenta 1420 ≈ 0 pero descuadrado POR DENTRO: una subcuenta
 * en débito y otra en crédito que se compensan, sin que haya que mover costo (la 61 ya está correcta).
 * Se compensan las subcuentas 14 ENTRE SÍ (nunca se toca la 61), para que cada una quede en 0 y la
 * OT se pueda cerrar. Ejemplo R0040284: 14200105 y 14203010 opuestas (± 6.350.000), neto 0.
 */
class CompensacionCuentas14Controller extends Controller
{
    use GeneraPlanoSiesa;
    use AplicaPlanoCuenta14;
    use DescomponeSaldos14;

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
        $total = $this->totalCompensable($filas);

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

        // Compensable sin importar si la obra está cerrada o abierta: el criterio es el mismo
        // (1420 neto ≈ 0 con subcuentas opuestas). Solo se excluyen las UN de bolsa/apoyo.
        return RegistroFinanciero::whereNotIn('codigo_proyecto', $excluir)
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
     * Filas de compensación para la vista previa: por cada subcuenta 1420 que participa en el cruce
     * de una obra, el monto DRENADO con su signo (crédito drenado +, débito drenado −). El total a
     * compensar por obra es min(D, C) (una cara del cruce).
     */
    private function filasCompensables(?int $mes, ?int $anio): array
    {
        $rows    = $this->baseSaldos14($mes, $anio);
        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')->pluck('descripcion', 'cuenta_contable');

        $filas = [];
        foreach ($this->saldosPorObra14($rows) as $cod => $saldos) {
            $d = $this->descomponerObra14($saldos);
            if ($d['comp'] < $this->umbral14()) continue; // no hay cruce posible (un solo lado)

            foreach ($d['compensacion'] as $l) {
                $saldo = $l['debito'] > 0 ? $l['debito'] : -$l['credito']; // crédito drenado +, débito −
                $filas[] = [
                    'cuenta'   => $l['cuenta'],
                    'nombre'   => $nombres[$l['cuenta']] ?? '',
                    'proyecto' => $cod,
                    'saldo'    => round($saldo, 2),
                ];
            }
        }
        usort($filas, fn ($a, $b) => [$a['proyecto'], $a['cuenta']] <=> [$b['proyecto'], $b['cuenta']]);

        return $filas;
    }

    /** Total a compensar = Σ min(D, C) por obra (una cara del cruce, no se cuenta doble). */
    private function totalCompensable(array $filas): float
    {
        // Las filas traen el crédito drenado en positivo; su suma es Σ min(D, C).
        return round(array_sum(array_map(fn ($f) => max($f['saldo'], 0), $filas)), 2);
    }

    /**
     * Asiento de compensación 14↔14 (formato SIESA): drena min(D, C) cruzando el lado débito contra
     * el lado crédito de cada obra. Cuadra (débitos = créditos) sin tocar la 61.
     */
    private function construirLineas(?int $mes, ?int $anio, int $numeroDoc): array
    {
        $rows = $this->baseSaldos14($mes, $anio);

        $mov = [];
        foreach ($this->saldosPorObra14($rows) as $cod => $saldos) {
            $d = $this->descomponerObra14($saldos);
            if ($d['comp'] < $this->umbral14()) continue;

            foreach ($d['compensacion'] as $l) {
                $mov[] = $this->filaPlano(
                    $numeroDoc, $l['cuenta'], self::NIT_SECAR, $cod, null,
                    $l['debito'], $l['credito'], self::TIPO_DOC
                );
            }
        }

        return $mov;
    }
}
