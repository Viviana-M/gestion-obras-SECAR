<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Homologacion;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;

class PlanoReversionController extends Controller
{
    use GeneraPlanoSiesa;

    /** Mismo tipo de documento y tercero (SECAR) que el Plano de Cierre Contable. */
    private const TIPO_DOC  = 'CCC';
    private const NIT_SECAR = '890319324';

    /**
     * Página de Contabilidad: vista previa de las cuentas 14 con saldo CONTRARIO
     * ("reversión excesiva" = saldo de la 14 del lado equivocado) y descarga del plano.
     * Filtro opcional por mes/año (acumulado al mes, mismo criterio que el resto).
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = $request->filled('mes') ? (int) $request->get('mes') : null;
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        if ($mes === null || $anio === null) { $mes = null; $anio = null; } // corte solo si vienen ambos

        $filas = $this->cuentas14Contrarias($mes, $anio);
        $total = array_sum(array_map(fn ($f) => abs($f['saldo']), $filas));

        $periodos = RegistroFinanciero::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.plano-reversion', compact('filas', 'total', 'mes', 'anio', 'periodos'));
    }
    public function exportarPlano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = $request->filled('mes') ? (int) $request->get('mes') : null;
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        if ($mes === null || $anio === null) { $mes = null; $anio = null; }

        // N° de documento del asiento (SIESA). Lo puede fijar contabilidad; por defecto 1.
        $numeroDoc = max(1, (int) $request->get('documento', 1));

        $movimientos = $this->construirLineas($mes, $anio, $numeroDoc);
        if (empty($movimientos)) {
            return back()->with('error', 'No hay cuentas 14 con saldo contrario para exportar en este corte.');
        }

        $fecha = ($mes && $anio) ? $this->ultimoDiaDelMesSiesa($anio, $mes) : date('Ymd');
        $obs   = 'REVERSION SALDOS CUENTA 14' . (($mes && $anio) ? ' - HASTA '.sprintf('%02d/%d', $mes, $anio) : '');

        // MISMO formato de columnas que el Plano de Cierre Contable (4 hojas SIESA).
        $archivo = $this->generarPlanoSiesa($movimientos, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $obs);

        return response()->download($archivo, 'PLANO_REVERSION_SALDOS_14_'.date('Ymd_His').'.xlsx')
            ->deleteFileAfterSend(true);
    }

    /**
     * Saldo por (obra cerrada, subcuenta 14) — misma fórmula que la tarjeta. Con corte
     * acumulado al mes si se indica (todos los períodos anteriores + el mes).
     */
    private function baseSaldos14(?int $mes, ?int $anio)
    {
        return RegistroFinanciero::whereIn('codigo_proyecto', ProyectoCerrado::pluck('codigo_proyecto'))
            ->where('cuenta_mayor', 'Costos por aplicar')
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
     * Cuentas 14 con saldo contrario (para la vista previa): [cuenta, nombre, proyecto, saldo],
     * de las obras cerradas cuyo neto de la 14 no cuadró. Ordenadas por mayor saldo (absoluto).
     */
    private function cuentas14Contrarias(?int $mes, ?int $anio): array
    {
        $rows    = $this->baseSaldos14($mes, $anio);
        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')->pluck('descripcion', 'cuenta_contable');

        $netoObra = [];
        foreach ($rows as $r) {
            $netoObra[$r->codigo_proyecto] = ($netoObra[$r->codigo_proyecto] ?? 0) + (float) $r->saldo;
        }
        $obrasMal = array_filter($netoObra, fn ($v) => abs(round($v, 2)) >= 0.5);

        $filas = [];
        foreach ($rows as $r) {
            if (! isset($obrasMal[$r->codigo_proyecto])) continue;
            $saldo = round((float) $r->saldo, 2);
            if (abs($saldo) < 0.5) continue;
            $filas[] = [
                'cuenta'    => $r->cuenta_contable,
                'nombre'    => $nombres[$r->cuenta_contable] ?? '',
                'proyecto'  => $r->codigo_proyecto,
                'saldo'     => $saldo,
            ];
        }
        usort($filas, fn ($a, $b) => abs($b['saldo']) <=> abs($a['saldo']));

        return $filas;
    }

    /**
     * Asiento de reversión en el formato SIESA (mismas 12 columnas del Plano de Cierre
     * Contable): por cada cuenta 14 con saldo contrario, el par 14 ↔ 61 con su débito/crédito.
     */
    private function construirLineas(?int $mes, ?int $anio, int $numeroDoc): array
    {
        $homol = Homologacion::pluck('cuenta_61', 'cuenta_14');
        $rows  = $this->baseSaldos14($mes, $anio);

        // Neto de la 14 por obra (para quedarnos solo con las que de verdad quedaron mal)
        $netoObra = [];
        foreach ($rows as $r) {
            $netoObra[$r->codigo_proyecto] = ($netoObra[$r->codigo_proyecto] ?? 0) + (float) $r->saldo;
        }
        $obrasMal = array_filter($netoObra, fn($v) => abs(round($v, 2)) >= 0.5);

        // Si SIESA lo pide al revés, cambia a true (único punto a tocar)
        $invertir = false;

        $mov = [];
        foreach ($rows as $r) {
            $cod = $r->codigo_proyecto;
            if (!isset($obrasMal[$cod])) continue;          // obra ya cuadrada: se ignora completa

            $saldo = round((float) $r->saldo, 2);
            if (abs($saldo) < 0.5) continue;                // subcuenta sin saldo: se ignora

            $c14 = $r->cuenta_contable;
            $c61 = $homol[$c14] ?? 'SIN HOMOLOGAR';
            $m   = abs($saldo);

            // saldo NEGATIVO (pendiente) -> CR 14 / DB 61 ; POSITIVO (reversado) -> DB 14 / CR 61
            $acreditar14 = ($saldo < 0);
            if ($invertir) $acreditar14 = !$acreditar14;

            if ($acreditar14) {
                $mov[] = $this->filaPlano($numeroDoc, $c14, self::NIT_SECAR, $cod, null, 0, $m, self::TIPO_DOC);
                $mov[] = $this->filaPlano($numeroDoc, $c61, self::NIT_SECAR, $cod, null, $m, 0, self::TIPO_DOC);
            } else {
                $mov[] = $this->filaPlano($numeroDoc, $c14, self::NIT_SECAR, $cod, null, $m, 0, self::TIPO_DOC);
                $mov[] = $this->filaPlano($numeroDoc, $c61, self::NIT_SECAR, $cod, null, 0, $m, self::TIPO_DOC);
            }
        }

        return $mov;
    }
}