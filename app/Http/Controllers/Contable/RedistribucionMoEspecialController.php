<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ManoObraEspecial;
use App\Models\MontoDistribuirMoEspecial;
use App\Models\RedistribucionMoEspecial;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\RedistribucionMoEspecialService;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $personas = ManoObraEspecial::orderBy('nombre')->get()->map(function ($p) use ($costo, $nombresUn) {
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

    /** Alta de una persona al maestro. Se puede elegir un tercero de la bolsa o escribirlo a mano. */
    public function guardarPersona(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para editar en Contabilidad.');

        $datos = $request->validate([
            'cedula' => 'nullable|string|max:255',
            'nombre' => 'required|string|max:255',
        ], [], ['cedula' => 'cédula']);

        $nombre = trim($datos['nombre']);
        $cedula = trim((string) ($datos['cedula'] ?? ''));
        // Si el tercero de la bolsa no trae documento, se genera una clave estable a partir del
        // nombre (el cruce se hará por nombre); así no exigimos una cédula que el financiero no tiene.
        if ($cedula === '') {
            $cedula = 'SD-'.substr(md5(mb_strtolower($nombre)), 0, 12);
        }

        if (ManoObraEspecial::where('cedula', $cedula)->exists()) {
            return back()->with('error', 'Esa persona ya está registrada.');
        }

        ManoObraEspecial::create([
            'cedula' => $cedula, 'nombre' => $nombre,
            'activo' => true, 'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Persona agregada a MO Apoyo administrativo y operativo.');
    }

    public function eliminarPersona(Request $request, ManoObraEspecial $persona)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para editar en Contabilidad.');

        RedistribucionMoEspecial::where('cedula', $persona->cedula)->delete();
        if (Schema::hasTable('monto_distribuir_mo_especial')) {
            MontoDistribuirMoEspecial::where('cedula', $persona->cedula)->delete();
        }
        $persona->delete();

        return back()->with('success', 'Persona eliminada de MO Apoyo administrativo y operativo.');
    }

    /** Guarda los % de redistribución del período. Valida que sumen 100% por persona. */
    public function guardarPorcentajes(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para editar en Contabilidad.');

        [$mes, $anio] = $this->periodo($request);
        $entrada = (array) $request->input('pct', []);      // [cedula => [ ['un'=>,'pct'=>], ... ]]
        $montosInput = (array) $request->input('monto', []); // [cedula => monto a distribuir]

        // Consolidar y validar los % por persona.
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

        // Monto a distribuir por persona: se topa al total retirado del período (no se puede
        // distribuir más de lo que tiene). Si viene vacío, se distribuye el total (por defecto).
        $costo  = $this->svc->costoPorPersona($mes, $anio);
        $montos = [];
        foreach ($montosInput as $cedula => $valor) {
            if (! isset($costo[$cedula])) continue;
            $total = (float) $costo[$cedula]['total'];
            $m = $valor === '' || $valor === null ? $total : (float) $valor;
            $montos[$cedula] = max(0.0, min($m, $total));
        }

        DB::transaction(function () use ($porPersona, $montos, $mes, $anio, $request) {
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
            // Reemplaza el monto a distribuir del período para las personas enviadas.
            if (! empty($montos) && Schema::hasTable('monto_distribuir_mo_especial')) {
                MontoDistribuirMoEspecial::where('mes', $mes)->where('anio', $anio)
                    ->whereIn('cedula', array_keys($montos))->delete();
                foreach ($montos as $cedula => $m) {
                    MontoDistribuirMoEspecial::create([
                        'cedula' => $cedula, 'mes' => $mes, 'anio' => $anio,
                        'monto_distribuir' => round($m, 2), 'user_id' => $request->user()->id,
                    ]);
                }
            }
        });

        return back()->with('success', 'Distribución guardada para el período '.sprintf('%02d/%d', $mes, $anio).'.');
    }

    /** Plano SIESA de reclasificación 14→61 (por la UN que trae cada línea del cierre). */
    public function plano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403, 'No tienes permiso para ver Contabilidad.');

        [$mes, $anio] = $this->periodo($request);
        $numeroDoc = max(1, (int) $request->get('documento', 1));

        $mov = [];
        foreach ($this->svc->movimientosRedistribucion($mes, $anio) as $m) {
            // CR la cuenta 14 conservando el tercero del ERP (persona en salario; fondo/EPS en SS)
            // y DB la cuenta 61 a nombre de la persona, en la MISMA UN que trae la línea.
            $mov[] = $this->filaPlano($numeroDoc, $m['cuenta_credito'], $m['tercero_credito'], $m['un'], null, 0, $m['monto'], self::TIPO_DOC);
            $mov[] = $this->filaPlano($numeroDoc, $m['cuenta_debito'], $m['tercero_debito'], $m['un'], null, $m['monto'], 0, self::TIPO_DOC);
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

    /** Período seleccionado; por defecto el último con datos financieros. */
    private function periodo(Request $request): array
    {
        $ult = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first();
        $mes  = (int) $request->get('mes', $ult->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $ult->anio ?? (int) date('Y'));
        return [$mes, $anio];
    }
}
