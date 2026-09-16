<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ManoObraDirecta;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\RedistribucionMoEspecialService;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;

/**
 * Módulo de Contabilidad: redistribución por % de la mano de obra del personal especial
 * (Grupo B). Solo Contabilidad ve/edita. Ver §spec.
 */
class RedistribucionMoEspecialController extends Controller
{
    use GeneraPlanoSiesa;

    private const TIPO_DOC  = 'CCC';
    private const NIT_SECAR = '890319324';

    public function __construct(private RedistribucionMoEspecialService $svc) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403, 'No tienes permiso para ver Contabilidad.');

        [$mes, $anio] = $this->periodo($request);

        $costo   = $this->svc->costoPorPersona($mes, $anio);
        $resumen = $this->svc->resumenBolsas($mes, $anio);
        $nombresUn = UnBolsa::pluck('nombre', 'codigo');

        // Personas del maestro con su costo y la distribución por UN que ya trae el archivo (solo lectura).
        $personas = ManoObraDirecta::where('activo', true)->orderBy('nombre')->get()->map(function ($p) use ($costo, $nombresUn) {
            $c = $costo[$p->cedula] ?? ['directo' => 0, 'ss' => 0, 'total' => 0, 'buckets' => []];
            // Distribución por UN (suma de sus líneas de MO en cada UN, tal como viene del cierre).
            $porUn = [];
            foreach ($c['buckets'] as $b) {
                $porUn[$b['un']] = ($porUn[$b['un']] ?? 0) + (float) $b['monto'];
            }
            arsort($porUn);
            $distUn = [];
            foreach ($porUn as $un => $monto) {
                $distUn[] = ['un' => (string) $un, 'nombre' => (string) ($nombresUn[$un] ?? ''), 'monto' => $monto];
            }
            return [
                'id' => $p->id, 'cedula' => $p->cedula, 'nombre' => $p->nombre, 'activo' => $p->activo,
                'directo' => (float) $c['directo'], 'ss' => (float) $c['ss'], 'total' => (float) $c['total'],
                'dist_un' => $distUn,
            ];
        });

        $periodos = RegistroFinanciero::selectRaw('anio, mes')->distinct()
            ->orderByDesc('anio')->orderByDesc('mes')->get();
        $sinCruzar  = $this->svc->tercerosSinCruzar($mes, $anio);
        $descuadres = $this->svc->descuadresFondos($mes, $anio);

        return view('contable.redistribucion-mo', compact('personas', 'resumen', 'periodos', 'mes', 'anio', 'sinCruzar', 'descuadres'));
    }

    /** Plano SIESA de reclasificación 14→61 (por la UN que trae cada línea del cierre). */
    public function plano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403, 'No tienes permiso para ver Contabilidad.');

        [$mes, $anio] = $this->periodo($request);
        $numeroDoc = max(1, (int) $request->get('documento', 1));

        $mov = [];
        foreach ($this->svc->movimientosRedistribucion($mes, $anio) as $m) {
            // El auxiliar de centro de costos va SOLO cuando la cuenta inicia en 6 (la 61), según la
            // UN de la bolsa: MTO00099 → 30020105, INS00099 → 30010103.
            $ccCredito = str_starts_with((string) $m['cuenta_credito'], '6') ? $this->centroCosto($m['un']) : null;
            $ccDebito  = str_starts_with((string) $m['cuenta_debito'], '6') ? $this->centroCosto($m['un']) : null;

            // CR la cuenta 14 conservando el tercero del ERP (persona en salario; fondo/EPS en SS)
            // y DB la cuenta 61 a nombre de la persona, en la MISMA UN que trae la línea.
            $mov[] = $this->filaPlano($numeroDoc, $m['cuenta_credito'], $m['tercero_credito'], $m['un'], $ccCredito, 0, $m['monto'], self::TIPO_DOC);
            $mov[] = $this->filaPlano($numeroDoc, $m['cuenta_debito'], $m['tercero_debito'], $m['un'], $ccDebito, $m['monto'], 0, self::TIPO_DOC);
        }

        if (empty($mov)) {
            return back()->with('error', 'No hay mano de obra de estas personas para reclasificar en este período.');
        }

        $fecha = $this->ultimoDiaDelMesSiesa($anio, $mes);
        $obs   = 'RECLASIFICACION MO APOYO ADMINISTRATIVO Y OPERATIVO '.sprintf('%02d/%d', $mes, $anio);
        $archivo = $this->generarPlanoSiesa($mov, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $obs);

        return response()->download($archivo, 'PLANO_REDISTRIBUCION_MO_'.sprintf('%d_%02d', $anio, $mes).'.xlsx')
            ->deleteFileAfterSend(true);
    }

    /** Auxiliar de centro de costos según la UN de la bolsa (para las cuentas 61). */
    private function centroCosto(string $un): ?string
    {
        return match ($un) {
            'MTO00099' => '30020105',
            'INS00099' => '30010103',
            default    => null,
        };
    }

    /** Período seleccionado; por defecto el último con datos financieros. */
    private function periodo(Request $request): array
    {
        $ult = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first();
        $mes  = (int) $request->get('mes', $ult->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $ult->anio ?? (int) date('Y'));
        return [$mes, $anio];
    }
}
