<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de los planos APLICADOS en la cuenta 14 del sistema (reverso y distribución). Lista
 * cada aplicación vigente con sus totales y permite DESHACERLA con un clic: borra los movimientos
 * generados en `registro_financieros` y la fila de auditoría, devolviendo el saldo a como estaba.
 */
class PlanosAplicadosController extends Controller
{
    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $tipo = $request->get('tipo');
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;

        $planos = PlanoAplicado::query()
            ->with('usuario')
            ->when(in_array($tipo, ['reverso', 'reverso_apoyo', 'distribucion', 'mo_distribucion'], true), fn ($q) => $q->where('tipo', $tipo))
            ->when($anio, fn ($q) => $q->where('anio', $anio))
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();

        $anios = PlanoAplicado::selectRaw('DISTINCT anio')->orderByDesc('anio')->pluck('anio');

        return view('contable.planos-aplicados', [
            'planos' => $planos,
            'tipo'   => $tipo,
            'anio'   => $anio,
            'anios'  => $anios,
            'meses'  => self::MESES,
            'totalDebito'  => (float) $planos->sum('total_debito'),
            'totalCredito' => (float) $planos->sum('total_credito'),
        ]);
    }

    /**
     * Deshace una aplicación: borra sus movimientos generados y la fila de auditoría. El saldo de la
     * cuenta 14 vuelve a incluir el pendiente (Operaciones volverá a verlo).
     */
    public function deshacer(Request $request, PlanoAplicado $plano)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        if (\App\Models\CierreConciliacion::estaCerrado((int) $plano->mes, (int) $plano->anio)) {
            return back()->with('error',
                "El período {$plano->mes}/{$plano->anio} está cerrado. Reábrelo en «Conciliación y cierre» para poder deshacer este plano.");
        }

        $n = DB::transaction(function () use ($plano) {
            $borrados = RegistroFinanciero::where('plano_aplicado_id', $plano->id)->delete();
            $plano->delete();

            return $borrados;
        });

        return back()->with('success',
            "Aplicación deshecha: se eliminaron {$n} movimientos generados. El saldo de cuenta 14 volvió a su estado anterior.");
    }
}
