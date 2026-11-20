<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\DistribucionService;
use App\Support\DescomponeSaldos14;
use App\Support\GeneraPlanoSiesa;
use App\Support\AplicaPlanoCuenta14;
use Illuminate\Http\Request;

class PlanoReversionController extends Controller
{
    use GeneraPlanoSiesa;
    use AplicaPlanoCuenta14;
    use DescomponeSaldos14;

    /** Mismo tipo de documento y tercero (SECAR) que el Plano de Cierre Contable. */
    private const TIPO_DOC  = 'CCC';
    private const NIT_SECAR = '890319324';

    /** Bolsas de apoyo (ADM/COM/CNT) que NO son obras: nunca entran al plano. */
    private const BOLSAS_APOYO_EXCLUIDAS = ['ADM00099', 'COM00099', 'CNT00099'];

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
     * APLICA el reverso en la cuenta 14 del sistema: crea los movimientos equivalentes (crédito/
     * débito a la 14) en `registro_financieros` con origen 'reverso_plano', para que el saldo baje
     * de inmediato y coincida con el ERP sin recargar BIABLE. Idempotente: re-aplicar el mismo corte
     * reemplaza sus movimientos. El período destino (donde el ERP lo contabiliza) por defecto es el
     * del corte, pero contabilidad puede cambiarlo.
     */
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
        ], [
            'documento.required' => 'Indica el número de documento del asiento.',
        ]);

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
            return back()->with('error', 'No hay cuentas 14 con saldo contrario para aplicar en este corte.');
        }

        // Verificación de cuadre de la partida doble antes de aplicar.
        if (! $this->planoCuadra($lineas)) {
            return back()->with('error', 'El plano de reversión NO cuadra (débitos ≠ créditos). No se aplicó.');
        }

        $contables = $this->lineasContablesDePlano($lineas);

        $plano = $this->aplicarPlanoEnSistema([
            'tipo'             => 'reverso',
            'distribucion_id'  => null,
            'corte_mes'        => $corteMes,
            'corte_anio'       => $corteAnio,
            'mes'              => (int) $datos['destino_mes'],
            'anio'             => (int) $datos['destino_anio'],
            'numero_documento' => $numeroDoc,
            'referencia'       => 'Reversión saldos cuenta 14'
                . (($corteMes && $corteAnio) ? ' — corte '.sprintf('%02d/%d', $corteMes, $corteAnio) : ' — histórico'),
            'origen'           => 'reverso_plano',
            'user_id'          => $request->user()->id,
        ], $contables);

        return back()->with('success',
            "Reverso aplicado en el sistema: {$plano->n_lineas} movimientos (partida doble 14/6) en el período {$plano->mes}/{$plano->anio}. "
            . "El saldo ya se reflejó (Operaciones no volverá a verlo).");
    }

    /**
     * Saldo por (obra, subcuenta 1420) con corte acumulado al mes si se indica. Aplica a obras
     * ABIERTAS y CERRADAS por igual; solo se excluyen la 1455 / demás 14xx y las UN de bolsa/apoyo.
     */
    private function baseSaldos14(?int $mes, ?int $anio)
    {
        $excluir = array_values(array_unique(array_merge(UnBolsa::codigos(), self::BOLSAS_APOYO_EXCLUIDAS)));

        return RegistroFinanciero::whereNotIn('codigo_proyecto', $excluir)
            ->where('cuenta_mayor', 'Costos por aplicar')
            // Solo 1420 (lo distribuible): se excluye la 1455 (inventario en tránsito) y demás 14xx,
            // que no son reversado de más y sobrevaloraban el ajuste.
            ->where('cuenta_contable', 'like', DistribucionService::PREFIJO_BOLSA.'%')
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
     * Cuentas 14 a reversar (vista previa): por cada obra cuyo neto 1420 quedó en CRÉDITO (C > D),
     * las subcuentas crédito con el EXCESO a reversar (C − D), ya descontado lo que se compensa.
     * Las de neto ≈ 0 (subcuentas que se cruzan, p. ej. O0008566) NO aparecen: van al Plano 2.
     */
    private function cuentas14Contrarias(?int $mes, ?int $anio): array
    {
        $rows    = $this->baseSaldos14($mes, $anio);
        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')->pluck('descripcion', 'cuenta_contable');

        $filas = [];
        foreach ($this->saldosPorObra14($rows) as $cod => $saldos) {
            foreach ($this->descomponerObra14($saldos)['reverso'] as $l) {
                $filas[] = [
                    'cuenta'    => $l['cuenta'],
                    'nombre'    => $nombres[$l['cuenta']] ?? '',
                    'proyecto'  => $cod,
                    'saldo'     => $l['saldo'],   // exceso de crédito a reversar (neto)
                ];
            }
        }
        usort($filas, fn ($a, $b) => abs($b['saldo']) <=> abs($a['saldo']));

        return $filas;
    }

    /**
     * Asiento de reversión en el formato SIESA (mismas 12 columnas del Plano de Cierre Contable):
     * por cada subcuenta 1420 con crédito en exceso (C − D), la partida doble DB 14 / CR 61.
     */
    private function construirLineas(?int $mes, ?int $anio, int $numeroDoc): array
    {
        $homol = Homologacion::pluck('cuenta_61', 'cuenta_14');
        $rows  = $this->baseSaldos14($mes, $anio);

        $mov = [];
        foreach ($this->saldosPorObra14($rows) as $cod => $saldos) {
            foreach ($this->descomponerObra14($saldos)['reverso'] as $l) {
                $c14 = $l['cuenta'];
                $c61 = $homol[$c14] ?? 'SIN HOMOLOGAR';
                $m   = round($l['saldo'], 2);

                // Crédito de más → DB 14 / CR 61 (baja la 14 y revierte el costo aplicado de más).
                $mov[] = $this->filaPlano($numeroDoc, $c14, self::NIT_SECAR, $cod, null, $m, 0, self::TIPO_DOC);
                $mov[] = $this->filaPlano($numeroDoc, $c61, self::NIT_SECAR, $cod, null, 0, $m, self::TIPO_DOC);
            }
        }

        return $mov;
    }
}
