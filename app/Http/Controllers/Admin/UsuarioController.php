<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    /** Roles validos. El bug anterior dejaba 'contador' FUERA de la lista al crear. */
    /** Cargos validos. Son etiquetas; el acceso lo definen los permisos por modulo. */
    private const ROLES = [
        'admin',
        'dir_operaciones',
        'aux_comercial',
        'aux_costos',
        'aux_admin_instalaciones',
        'comercial_mtto',
        'comercial',
        'contadora',
        'coordinadora_mtto',
        'dir_instalaciones',
        'dir_mantenimiento',
        'director_comercial',
        'director_compras',
        'dir_admin_auditoria',
        'gerente',
        'ing_instalaciones',
        'supervisor_mtto',
    ];

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

        $datos = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'sede'       => 'nullable|string|max:255',
            'rol'        => ['required', Rule::in(self::ROLES)],
            'password'   => 'required|string|min:8|confirmed',
            'permisos'   => 'nullable|array',
            'permisos.*' => 'in:no,ver,editar',
        ]);

        $usuario = User::create([
            'name'             => $datos['name'],
            'email'            => $datos['email'],
            'sede'             => $datos['sede'] ?? null,
            'rol'              => $datos['rol'],
            'password'         => $datos['password'],
            'permisos_modulos' => $datos['rol'] === 'admin' ? null : $this->armarPermisos($datos['permisos'] ?? []),
            'activo'           => true,
        ]);

        $usuario->email_verified_at = now();
        $usuario->save();

        return redirect()->route('admin.usuarios.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $usuario)
    {
        $this->soloAdmin();
        $modulos = config('modulos');
        $niveles = $this->nivelesActuales($usuario);
        return view('admin.usuarios.edit', compact('usuario', 'modulos', 'niveles'));
    }

    public function update(Request $request, User $usuario)
    {
        $this->soloAdmin();

        $datos = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->id)],
            'sede'       => 'nullable|string|max:255',
            'rol'        => ['required', Rule::in(self::ROLES)],
            'password'   => 'nullable|string|min:8|confirmed',
            'permisos'   => 'nullable|array',
            'permisos.*' => 'in:no,ver,editar',
        ]);

        if ($usuario->id === Auth::id() && $datos['rol'] !== 'admin') {
            return back()->withInput()
                ->withErrors(['rol' => 'No puedes quitarte a ti misma el rol de administrador.']);
        }

        $usuario->name  = $datos['name'];
        $usuario->email = $datos['email'];
        $usuario->sede  = $datos['sede'] ?? null;
        $usuario->rol   = $datos['rol'];
        $usuario->permisos_modulos = $datos['rol'] === 'admin'
            ? null
            : $this->armarPermisos($datos['permisos'] ?? []);

        if (! empty($datos['password'])) {
            $usuario->password = $datos['password'];
        }

        $usuario->save();

        return redirect()->route('admin.usuarios.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function toggleActivo(User $usuario)
    {
        $this->soloAdmin();

        if ($usuario->id === Auth::id()) {
            return back()->withErrors(['estado' => 'No puedes desactivar tu propia cuenta.']);
        }

        $usuario->activo = ! $usuario->activo;
        $usuario->save();

        $estado = $usuario->activo ? 'activado' : 'desactivado';

        return redirect()->route('admin.usuarios.index')
            ->with('success', "Usuario {$estado} correctamente.");
    }

    // ═══════════════════ Helpers de permisos ═══════════════════

    private function armarPermisos(array $permisos): array
    {
        $validos = array_keys(config('modulos'));
        $mapa = [];

        foreach ($permisos as $modulo => $nivel) {
            if (!in_array($modulo, $validos, true)) continue;
            if ($nivel === 'no' || $nivel === null) continue;

            if (in_array($modulo, User::DEPARTAMENTOS, true)) {
                $mapa[$modulo] = 'ver';
                continue;
            }

            $mapa[$modulo] = ($nivel === 'editar') ? 'editar' : 'ver';
        }

        return $mapa;
    }

    private function nivelesActuales(User $usuario): array
    {
        $mapa = $usuario->mapaPermisos();
        $niveles = [];

        foreach (array_keys(config('modulos')) as $modulo) {
            $niveles[$modulo] = $mapa[$modulo] ?? 'no';
        }

        return $niveles;
    }
}