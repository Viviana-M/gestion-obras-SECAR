<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TerceroManoObra;
use Illuminate\Http\Request;

class TerceroManoObraController extends Controller
{
    /** Ver el maestro: Contabilidad (o admin). */
    private function puedeVer(): void
    {
        abort_unless(auth()->user()?->puedeVerModulo('contabilidad'), 403, 'No tienes acceso a los terceros de mano de obra.');
    }

    /** Modificar el maestro: Contabilidad con permiso de edición (o admin). */
    private function puedeEditar(): void
    {
        abort_unless(auth()->user()?->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para modificar los terceros de mano de obra.');
    }

    public function index(Request $request)
    {
        $this->puedeVer();

        $q      = trim((string) $request->get('q', ''));
        $estado = in_array($request->get('estado'), ['inactivos', 'todos'], true)
            ? $request->get('estado')
            : 'activos'; // por defecto solo activos

        $terceros = TerceroManoObra::query()
            ->when($estado === 'activos', fn ($x) => $x->where('activo', true))
            ->when($estado === 'inactivos', fn ($x) => $x->where('activo', false))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('cedula', 'like', "%{$q}%")))
            ->orderBy('nombre')
            ->get();

        return view('admin.terceros-mano-obra.index', compact('terceros', 'q', 'estado'));
    }

    public function store(Request $request)
    {
        $this->puedeEditar();
        $datos = $request->validate([
            'cedula'       => 'required|string|max:20|unique:terceros_mano_obra,cedula',
            'nombre'       => 'required|string|max:255',
            'departamento' => 'required|in:mantenimiento,instalaciones',
        ], [], ['cedula' => 'cédula', 'nombre' => 'nombre', 'departamento' => 'departamento']);
        $datos['cedula'] = trim($datos['cedula']);
        $datos['nombre'] = trim($datos['nombre']);
        $datos['activo'] = true;
        TerceroManoObra::create($datos);
        return back()->with('success', 'Persona agregada.');
    }

    public function update(Request $request, TerceroManoObra $terceroManoObra)
    {
        $this->puedeEditar();
        $datos = $request->validate([
            'nombre'       => 'required|string|max:255',
            'departamento' => 'required|in:mantenimiento,instalaciones',
        ]);
        $terceroManoObra->update($datos);
        return back()->with('success', 'Persona actualizada.');
    }

    public function toggle(TerceroManoObra $terceroManoObra)
    {
        $this->puedeEditar();
        $terceroManoObra->activo = !$terceroManoObra->activo;
        $terceroManoObra->save();
        return back()->with('success', $terceroManoObra->activo ? 'Persona activada.' : 'Persona desactivada.');
    }
}