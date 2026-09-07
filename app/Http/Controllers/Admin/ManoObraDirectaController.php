<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManoObraDirecta;
use Illuminate\Http\Request;

class ManoObraDirectaController extends Controller
{
    private function soloAdmin(): void
    {
        abort_unless(auth()->user()?->esAdmin(), 403, 'Solo un administrador puede gestionar la mano de obra directa.');
    }

    public function index()
    {
        $this->soloAdmin();
        $personas = ManoObraDirecta::orderBy('nombre')->get();
        return view('admin.mano-obra-directa.index', ['personas' => $personas]);
    }

    public function store(Request $request)
    {
        $this->soloAdmin();

        $datos = $this->validar($request, true);
        $datos['cedula'] = trim($datos['cedula']);
        $datos['nombre'] = trim($datos['nombre']);
        $datos['activo'] = true;

        ManoObraDirecta::create($datos);

        return back()->with('success', 'Persona agregada.');
    }

    public function update(Request $request, ManoObraDirecta $manoObraDirecta)
    {
        $this->soloAdmin();

        $datos = $this->validar($request, false);
        $datos['nombre'] = trim($datos['nombre']);

        $manoObraDirecta->update($datos);

        return back()->with('success', 'Persona actualizada.');
    }

    public function toggle(ManoObraDirecta $manoObraDirecta)
    {
        $this->soloAdmin();

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
