<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManoObraDirecta;
use Illuminate\Http\Request;

class ManoObraDirectaController extends Controller
{
    /** Ver el maestro: Contabilidad (o admin). */
    private function puedeVer(): void
    {
        abort_unless(auth()->user()?->puedeVerModulo('contabilidad'), 403, 'No tienes acceso a la mano de obra directa.');
    }

    /** Modificar el maestro: Contabilidad con permiso de edición (o admin). */
    private function puedeEditar(): void
    {
        abort_unless(auth()->user()?->puedeEditarModulo('contabilidad'), 403, 'No tienes permiso para modificar la mano de obra directa.');
    }

    public function index(Request $request)
    {
        $this->puedeVer();

        $q      = trim((string) $request->get('q', ''));
        $estado = in_array($request->get('estado'), ['inactivos', 'todos'], true)
            ? $request->get('estado')
            : 'activos'; // por defecto solo activos

        $personas = ManoObraDirecta::query()
            ->when($estado === 'activos', fn ($x) => $x->where('activo', true))
            ->when($estado === 'inactivos', fn ($x) => $x->where('activo', false))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('cedula', 'like', "%{$q}%")))
            ->orderBy('nombre')
            ->get();

        return view('admin.mano-obra-directa.index', compact('personas', 'q', 'estado'));
    }

    public function store(Request $request)
    {
        $this->puedeEditar();

        $datos = $this->validar($request, true);
        $datos['cedula'] = trim($datos['cedula']);
        $datos['nombre'] = trim($datos['nombre']);
        $datos['activo'] = true;

        ManoObraDirecta::create($datos);

        return back()->with('success', 'Persona agregada.');
    }

    public function update(Request $request, ManoObraDirecta $manoObraDirecta)
    {
        $this->puedeEditar();

        $datos = $this->validar($request, false);
        $datos['nombre'] = trim($datos['nombre']);

        $manoObraDirecta->update($datos);

        return back()->with('success', 'Persona actualizada.');
    }

    public function toggle(ManoObraDirecta $manoObraDirecta)
    {
        $this->puedeEditar();

        $manoObraDirecta->activo = ! $manoObraDirecta->activo;
        $manoObraDirecta->save();

        return back()->with('success', $manoObraDirecta->activo ? 'Persona activada.' : 'Persona desactivada.');
    }

    /** Reglas comunes; en creación exige cédula única. */
    private function validar(Request $request, bool $conCedula): array
    {
        // Los porcentajes ya no se usan: la distribución por unidad de negocio la trae Nómina
        // en el archivo de cierre (cuenta 14). Solo se administra cédula + nombre.
        $reglas = ['nombre' => 'required|string|max:255'];
        if ($conCedula) {
            $reglas['cedula'] = 'required|string|max:20|unique:mano_obra_directa,cedula';
        }

        return $request->validate($reglas, [], ['cedula' => 'cédula', 'nombre' => 'nombre']);
    }
}
