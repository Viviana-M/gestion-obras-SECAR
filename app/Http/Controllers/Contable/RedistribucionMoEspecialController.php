<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ManoObraEspecial;
use App\Models\RedistribucionMoEspecial;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\RedistribucionMoEspecialService;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $pcts    = $this->svc->porcentajes($mes, $anio);
        $resumen = $this->svc->resumenBolsas($mes, $anio);

        // Personas del maestro con su costo y % del período.
        $personas = ManoObraEspecial::orderBy('nombre')->get()->map(function ($p) use ($costo, $pcts) {
            $c = $costo[$p->cedula] ?? ['directo' => 0, 'ss' => 0, 'total' => 0, 'buckets' => []];
            $pp = $pcts[$p->cedula] ?? [];
            return [
                'id' => $p->id, 'cedula' => $p->cedula, 'nombre' => $p->nombre, 'activo' => $p->activo,
                'directo' => (float) $c['directo'], 'ss' => (float) $c['ss'], 'total' => (float) $c['total'],
                'porcentajes' => $pp, 'suma_pct' => array_sum($pp),
            ];
        });

        $bolsas   = UnBolsa::where('activo', true)->orderBy('codigo')->get(['codigo', 'nombre']);
        $periodos = RegistroFinanciero::selectRaw('anio, mes')->distinct()
            ->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.redistribucion-mo', compact('personas', 'resumen', 'bolsas', 'periodos', 'mes', 'anio'));
    }

    /** Alta de una persona al maestro Grupo B. */
    public function guardarPersona(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para editar en Contabilidad.');

        $datos = $request->validate([
            'cedula' => 'required|string|max:255|unique:mano_obra_especial,cedula',
            'nombre' => 'required|string|max:255',
        ], [], ['cedula' => 'cédula']);

        ManoObraEspecial::create([
            'cedula' => trim($datos['cedula']), 'nombre' => trim($datos['nombre']),
            'activo' => true, 'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Persona agregada a MO Apoyo administrativo y operativo.');
    }

    public function eliminarPersona(Request $request, ManoObraEspecial $persona)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para editar en Contabilidad.');

        RedistribucionMoEspecial::where('cedula', $persona->cedula)->delete();
        $persona->delete();

        return back()->with('success', 'Persona eliminada de MO Apoyo administrativo y operativo.');
    }

    /** Guarda los % de redistribución del período. Valida que sumen 100% por persona. */
    public function guardarPorcentajes(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para editar en Contabilidad.');

        [$mes, $anio] = $this->periodo($request);
        $entrada = (array) $request->input('pct', []); // [cedula => [ ['un'=>,'pct'=>], ... ]]

        // Consolidar y validar por persona.
        $porPersona = [];
        foreach ($entrada as $cedula => $filas) {
            foreach ((array) $filas as $f) {
                $un  = trim((string) ($f['un'] ?? ''));
                $pct = (float) ($f['pct'] ?? 0);
                if ($un === '' || $pct <= 0) continue;
                $porPersona[$cedula][$un] = ($porPersona[$cedula][$un] ?? 0) + $pct;
            }
        }

        foreach ($porPersona as $cedula => $unes) {
            $suma = array_sum($unes);
            if (abs($suma - 100) > 0.05) {
                $nombre = ManoObraEspecial::where('cedula', $cedula)->value('nombre') ?? $cedula;
                return back()->with('error', "Los porcentajes de {$nombre} suman ".rtrim(rtrim(number_format($suma, 1), '0'), '.')."%, deben sumar 100%.");
            }
        }

        DB::transaction(function () use ($porPersona, $mes, $anio, $request) {
            // Reemplaza los % del período para las personas enviadas.
            RedistribucionMoEspecial::where('mes', $mes)->where('anio', $anio)
                ->whereIn('cedula', array_keys($porPersona))->delete();
            foreach ($porPersona as $cedula => $unes) {
                foreach ($unes as $un => $pct) {
                    RedistribucionMoEspecial::create([
                        'cedula' => $cedula, 'mes' => $mes, 'anio' => $anio,
                        'un_codigo' => $un, 'porcentaje' => round($pct, 2), 'user_id' => $request->user()->id,
                    ]);
                }
            }
        });

        return back()->with('success', 'Porcentajes guardados para el período '.sprintf('%02d/%d', $mes, $anio).'.');
    }

    /** Plano SIESA (14→14 entre UN) de la redistribución del período. */
    public function plano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403, 'No tienes permiso para ver Contabilidad.');

        [$mes, $anio] = $this->periodo($request);
        $numeroDoc = max(1, (int) $request->get('documento', 1));

        $mov = [];
        foreach ($this->svc->movimientosRedistribucion($mes, $anio) as $m) {
            // CR en la UN de origen (retiro) y DB en la UN destino (redistribución), misma cuenta 14.
            $mov[] = $this->filaPlano($numeroDoc, $m['cuenta'], self::NIT_SECAR, $m['un_origen'], null, 0, $m['monto'], self::TIPO_DOC);
            $mov[] = $this->filaPlano($numeroDoc, $m['cuenta'], self::NIT_SECAR, $m['un_destino'], null, $m['monto'], 0, self::TIPO_DOC);
        }

        if (empty($mov)) {
            return back()->with('error', 'No hay redistribución para exportar en este período (¿definiste los % y hay costo de MO Apoyo administrativo y operativo?).');
        }

        $fecha = $this->ultimoDiaDelMesSiesa($anio, $mes);
        $obs   = 'REDISTRIBUCION MO APOYO ADMINISTRATIVO Y OPERATIVO '.sprintf('%02d/%d', $mes, $anio);
        $archivo = $this->generarPlanoSiesa($mov, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $obs);

        return response()->download($archivo, 'PLANO_REDISTRIBUCION_MO_'.sprintf('%d_%02d', $anio, $mes).'.xlsx')
            ->deleteFileAfterSend(true);
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
