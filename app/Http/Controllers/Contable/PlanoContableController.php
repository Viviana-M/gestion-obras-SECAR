<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\AplicacionCosto;
use App\Models\ObraEstado;
use App\Models\Distribucion;
use App\Models\User;
use App\Exports\PlanoCerradasExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;

class PlanoContableController extends Controller
{
    public function index(Request $request)
    {
        $mes  = (int) $request->get('mes', date('n'));
        $anio = (int) $request->get('anio', date('Y'));

        // Cerradas y parciales cruzan 14 -> 61 (la parcial aplica lo decidido, deja el resto en 14)
        $aplican  = ObraEstado::whereIn('estado', ['cerrada', 'parcial'])->pluck('codigo_proyecto');
        $usuarios = User::pluck('name', 'id');

        $versiones = Distribucion::where('mes', $mes)->where('anio', $anio)
            ->where('estado', 'enviado')
            ->orderBy('departamento')->orderByDesc('version')->get();

        $data = $versiones->map(function ($d) use ($aplican, $usuarios) {
            $lineas = AplicacionCosto::where('distribucion_id', $d->id)
                ->whereIn('codigo_proyecto', $aplican)
                ->where('monto_aplicar', '>', 0)
                ->orderBy('codigo_proyecto')->get();
            return [
                'id'           => $d->id,
                'version'      => $d->version,
                'departamento' => $d->departamento,
                'enviado_at'   => $d->enviado_at,
                'enviado_por'  => $usuarios[$d->enviado_por] ?? '—',
                'habilitada'   => $d->edicion_habilitada,
                'lineas'       => $lineas,
                'total'        => $lineas->sum('monto_aplicar'),
                'obras'        => $lineas->pluck('codigo_proyecto')->unique()->count(),
            ];
        });

        return view('contable.plano-contable', compact('mes', 'anio', 'data'));
    }

    public function exportarPlano(Distribucion $distribucion)
    {
        // Cerradas y parciales cruzan 14 -> 61
        $aplican = ObraEstado::whereIn('estado', ['cerrada', 'parcial'])->pluck('codigo_proyecto');

        $lineas = AplicacionCosto::where('distribucion_id', $distribucion->id)
            ->whereIn('codigo_proyecto', $aplican)
            ->where('monto_aplicar', '>', 0)
            ->orderBy('codigo_proyecto')->get();

        $filename = "plano_v{$distribucion->version}_{$distribucion->anio}_" . str_pad($distribucion->mes, 2, '0', STR_PAD_LEFT) . ".xlsx";
        return Excel::download(new PlanoCerradasExport($lineas, $distribucion->mes, $distribucion->anio), $filename);
    }

    public function habilitar(Distribucion $distribucion)
    {
        $distribucion->edicion_habilitada = !$distribucion->edicion_habilitada;
        $distribucion->save();

        // Registrar el evento en la trazabilidad del plano
        \App\Models\DistribucionVersion::create([
            'distribucion_id' => $distribucion->id,
            'evento'          => $distribucion->edicion_habilitada ? 'reabierto' : 'bloqueado',
            'user_id'         => auth()->id(),
            'user_nombre'     => auth()->user()?->name,
            'snapshot'        => null, // al reabrir/bloquear los números no cambian
        ]);

        $msg = $distribucion->edicion_habilitada
            ? "Versión {$distribucion->version} habilitada: operaciones ya puede editarla."
            : "Versión {$distribucion->version} bloqueada de nuevo.";
        return back()->with('success', $msg);
    }
}