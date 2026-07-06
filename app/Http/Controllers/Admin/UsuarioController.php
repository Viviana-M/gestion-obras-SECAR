<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    // Solo un admin puede entrar a cualquiera de estas pantallas.
    private function soloAdmin(): void
    {
        abort_unless(Auth::user()->esAdmin(), 403, 'No tienes permiso para administrar usuarios.');
    }

    public function index()
    {
        $this->soloAdmin();

        $usuarios = User::orderBy('name')->get();
        $modulos  = config('modulos');

        return view('admin.usuarios.index', compact('usuarios', 'modulos'));
    }

    public function create()
    {
        $this->soloAdmin();

        $modulos = config('modulos');

        return view('admin.usuarios.create', compact('modulos'));
    }

    public function store(Request $request)
    {
        $this->soloAdmin();

        $claves = array_keys(config('modulos'));

        $datos = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255|unique:users,email',
            'sede'      => 'nullable|string|max:255',
            'rol'       => 'required|in:admin,usuario,director,gerente,supervisor,coordinador',
            'password'  => 'required|string|min:8|confirmed',
            'modulos'   => 'nullable|array',
            'modulos.*' => 'in:' . implode(',', $claves),
        ]);

        $usuario = User::create([
            'name'               => $datos['name'],
            'email'              => $datos['email'],
            'sede'               => $datos['sede'] ?? null,
            'rol'                => $datos['rol'],
            'password'           => $datos['password'], // se encripta solo (cast hashed)
            'modulos_permitidos' => $datos['rol'] === 'admin' ? null : ($datos['modulos'] ?? []),
            'activo'             => true,
        ]);

        // Usuario creado por el admin: se considera verificado (no pide correo de verificación).
        $usuario->email_verified_at = now();
        $usuario->save();

        return redirect()->route('admin.usuarios.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $usuario)
    {
        $this->soloAdmin();

        $modulos  = config('modulos');
        // Pre-marcar: lo que tenga guardado, o su acceso heredado del rol antiguo.
        $marcados = $usuario->modulos_permitidos ?? $usuario->modulosLegado();

        return view('admin.usuarios.edit', compact('usuario', 'modulos', 'marcados'));
    }

    public function update(Request $request, User $usuario)
    {
        $this->soloAdmin();

        $claves = array_keys(config('modulos'));

        $datos = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->id)],
            'sede'      => 'nullable|string|max:255',
            'rol'       => 'required|in:admin,usuario,director,gerente,supervisor,coordinador',
            'password'  => 'nullable|string|min:8|confirmed',
            'modulos'   => 'nullable|array',
            'modulos.*' => 'in:' . implode(',', $claves),
        ]);

        // Evitar que la admin se quite a sí misma el rol y quede bloqueada.
        if ($usuario->id === Auth::id() && $datos['rol'] !== 'admin') {
            return back()->withInput()
                ->withErrors(['rol' => 'No puedes quitarte a ti misma el rol de administrador.']);
        }

        $usuario->name  = $datos['name'];
        $usuario->email = $datos['email'];
        $usuario->sede  = $datos['sede'] ?? null;
        $usuario->rol   = $datos['rol'];
        $usuario->modulos_permitidos = $datos['rol'] === 'admin' ? null : ($datos['modulos'] ?? []);

        if (! empty($datos['password'])) {
            $usuario->password = $datos['password']; // el cast hashed lo encripta
        }

        $usuario->save();

        return redirect()->route('admin.usuarios.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function toggleActivo(User $usuario)
    {
        $this->soloAdmin();

        // Evitar que la admin se desactive a sí misma.
        if ($usuario->id === Auth::id()) {
            return back()->withErrors(['estado' => 'No puedes desactivar tu propia cuenta.']);
        }

        $usuario->activo = ! $usuario->activo;
        $usuario->save();

        $estado = $usuario->activo ? 'activado' : 'desactivado';

        return redirect()->route('admin.usuarios.index')
            ->with('success', "Usuario {$estado} correctamente.");
    }
}