<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TerceroManoObra;
use Illuminate\Http\Request;

class TerceroManoObraController extends Controller
{
    public function index()
    {
        $terceros = TerceroManoObra::orderBy('nombre')->get();
        return view('admin.terceros-mano-obra.index', ['terceros' => $terceros]);
    }

    public function store(Request $request)
    {
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
        $datos = $request->validate([
            'nombre'       => 'required|string|max:255',
            'departamento' => 'required|in:mantenimiento,instalaciones',
        ]);
        $terceroManoObra->update($datos);
        return back()->with('success', 'Persona actualizada.');
    }

    public function toggle(TerceroManoObra $terceroManoObra)
    {
        $terceroManoObra->activo = !$terceroManoObra->activo;
        $terceroManoObra->save();
        return back()->with('success', $terceroManoObra->activo ? 'Persona activada.' : 'Persona desactivada.');
    }
}