<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnBolsa;
use Illuminate\Http\Request;

class UnBolsaController extends Controller
{
    public function index()
    {
        $bolsas = UnBolsa::orderBy('departamento')->orderBy('codigo')->get();
        return view('admin.un-bolsas.index', ['bolsas' => $bolsas]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'codigo'       => 'required|string|max:30|unique:un_bolsas,codigo',
            'nombre'       => 'nullable|string|max:255',
            'departamento' => 'required|in:mantenimiento,instalaciones',
        ], [], [
            'codigo' => 'código',
            'departamento' => 'departamento',
        ]);
        $datos['codigo'] = strtoupper(trim($datos['codigo']));
        $datos['activo'] = true;
        UnBolsa::create($datos);
        return back()->with('success', 'Unidad de negocio agregada.');
    }

    public function update(Request $request, UnBolsa $unBolsa)
    {
        $datos = $request->validate([
            'nombre'       => 'nullable|string|max:255',
            'departamento' => 'required|in:mantenimiento,instalaciones',
        ]);
        $unBolsa->update($datos);
        return back()->with('success', 'Unidad de negocio actualizada.');
    }

    public function toggle(UnBolsa $unBolsa)
    {
        $unBolsa->activo = !$unBolsa->activo;
        $unBolsa->save();
        return back()->with('success', $unBolsa->activo ? 'Bolsa activada.' : 'Bolsa desactivada.');
    }
}