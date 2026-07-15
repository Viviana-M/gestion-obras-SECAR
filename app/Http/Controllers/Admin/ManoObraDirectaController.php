<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManoObraDirecta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        $reglas = [
            'nombre'            => 'required|string|max:255',
            'pct_mantenimiento' => 'nullable|numeric|min:0|max:100',
            'pct_instalaciones' => 'nullable|numeric|min:0|max:100',
        ];
        if ($conCedula) {
            $reglas['cedula'] = 'required|string|max:20|unique:mano_obra_directa,cedula';
        }

        $datos = $request->validate($reglas, [], [
            'cedula'            => 'cédula',
            'nombre'            => 'nombre',
            'pct_mantenimiento' => 'porcentaje de mantenimiento',
            'pct_instalaciones' => 'porcentaje de instalaciones',
        ]);

        $datos['pct_mantenimiento'] = (float) ($datos['pct_mantenimiento'] ?? 0);
        $datos['pct_instalaciones'] = (float) ($datos['pct_instalaciones'] ?? 0);

        if ($datos['pct_mantenimiento'] + $datos['pct_instalaciones'] > 100.0001) {
            throw ValidationException::withMessages([
                'pct_mantenimiento' => 'La suma de los porcentajes (mantenimiento + instalaciones) no puede pasar de 100%.',
            ]);
        }

        return $datos;
    }
}
