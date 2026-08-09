<?php

namespace App\Models;

use App\Mail\ResetPasswordSecar;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Departamentos: no son modulos con nivel, son filtros de obra (pertenece/no). */
    public const DEPARTAMENTOS = ['dep_mantenimiento', 'dep_instalaciones'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'sede',
        'menu_colapsado',
        'permisos_modulos',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'menu_colapsado'     => 'boolean',
            'permisos_modulos'   => 'array',
            'activo'             => 'boolean',
        ];
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function esAdmin(): bool
    {
        return $this->rol === 'admin';
    }

    /**
     * ¿Pertenece a gerencia? (puede aprobar/rechazar autorizaciones de distribución)
     * Cuenta como gerencia: administrador, cargo gerente, o cualquier director
     * (roles que empiezan por 'dir_' o 'director', p. ej. dir_operaciones,
     * dir_instalaciones, dir_mantenimiento, director_comercial, director_compras,
     * dir_admin_auditoria).
     */
    public function esGerencia(): bool
    {
        if ($this->esAdmin()) {
            return true;
        }

        $rol = (string) $this->rol;

        return $rol === 'gerente'
            || str_starts_with($rol, 'dir_')
            || str_starts_with($rol, 'director');
    }

    /**
     * Mapa efectivo de permisos: modulo => 'ver'|'editar'.
     * Fuente única: permisos_modulos. La columna vieja modulos_permitidos se
     * consolidó a esta y se eliminó (ver migración de consolidación).
     */
    public function mapaPermisos(): array
    {
        return $this->permisos_modulos ?? [];
    }

    // ¿Puede VER este módulo? (ver o editar cuentan como ver)
    public function puedeVerModulo(string $clave): bool
    {
        if ($this->esAdmin()) {
            return true;
        }
        return isset($this->mapaPermisos()[$clave]);
    }

    // ¿Puede EDITAR este módulo? Admin siempre; el resto solo si su nivel es 'editar'.
    public function puedeEditarModulo(string $clave): bool
    {
        if ($this->esAdmin()) {
            return true;
        }
        return ($this->mapaPermisos()[$clave] ?? null) === 'editar';
    }

    /**
     * Página de inicio del usuario: el PRIMER módulo que pueda ver, en el orden del
     * menú lateral. Los roles son cargos, así que el inicio NO depende del rol sino
     * de los permisos: cada quien aterriza donde tiene acceso (el admin ve todo, así
     * que cae en el primero, el financiero). Sin ningún módulo → a un lugar seguro.
     */
    public function paginaInicio(): string
    {
        $modulos = [
            'gestion_financiera' => '/dashboard',
            'operacion'          => '/operativo/distribucion',
            'comercial'          => '/comercial/cotizaciones',
            'contabilidad'       => '/contable/homologaciones',
        ];

        foreach ($modulos as $clave => $ruta) {
            if ($this->puedeVerModulo($clave)) {
                return $ruta;
            }
        }

        // Sin acceso a ningún módulo: al perfil (siempre disponible para autenticados).
        return '/profile';
    }

    // Nivel de un modulo: 'editar' | 'ver' | null (sin acceso). Admin -> 'editar'.
    public function nivelModulo(string $clave): ?string
    {
        if ($this->esAdmin()) {
            return 'editar';
        }
        return $this->mapaPermisos()[$clave] ?? null;
    }

    public function departamentosPermitidos(): array
    {
        $mapa = [
            'dep_mantenimiento' => ['C', 'R', 'MO', 'GM'],
            'dep_instalaciones' => ['GI', 'O'],
        ];

        $prefijos = [];
        foreach ($mapa as $modulo => $pref) {
            if ($this->puedeVerModulo($modulo)) {
                $prefijos = array_merge($prefijos, $pref);
            }
        }
        return $prefijos;
    }

    public function departamentoUnico(): ?string
    {
        $mant = $this->puedeVerModulo('dep_mantenimiento');
        $inst = $this->puedeVerModulo('dep_instalaciones');

        if ($this->esAdmin()) return null;
        if ($mant && $inst)   return null;
        if ($mant)            return 'mantenimiento';
        if ($inst)            return 'instalaciones';
        return null;
    }

    public static function prefijosDeDepartamento(string $dep): array
    {
        return [
            'mantenimiento' => ['C', 'R', 'MO', 'GM'],
            'instalaciones' => ['GI', 'O'],
        ][$dep] ?? [];
    }

    /** Departamento al que pertenece un código de obra según su prefijo. */
    public static function departamentoDeCodigo(string $cod): ?string
    {
        $cod = strtoupper(trim($cod));
        foreach (['mantenimiento', 'instalaciones'] as $dep) {
            foreach (self::prefijosDeDepartamento($dep) as $p) {
                if (str_starts_with($cod, strtoupper($p))) {
                    return $dep;
                }
            }
        }
        return null;
    }

    public function tieneFiltroDepartamento(): bool
    {
        if ($this->esAdmin()) return false;
        return $this->puedeVerModulo('dep_mantenimiento') || $this->puedeVerModulo('dep_instalaciones');
    }

    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', [
            'token' => $token,
            'email' => $this->email,
        ]);

        Mail::to($this->email)->send(new ResetPasswordSecar($url, $this->name));
    }
}