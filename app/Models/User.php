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
        'modulos_permitidos',
        'permisos_modulos',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'menu_colapsado'     => 'boolean',
            'modulos_permitidos' => 'array',
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

    public function modulosLegado(): array
    {
        return [
            'financiero' => ['gestion_financiera'],
            'operativo'  => ['operacion'],
            'comercial'  => ['comercial'],
            'contable'   => ['contabilidad'],
        ][$this->rol] ?? [];
    }

    /**
     * Mapa efectivo de permisos: modulo => 'ver'|'editar'.
     * Prioridad: permisos_modulos (nuevo) -> modulos_permitidos (viejo) -> rol. Los dos
     * ultimos se reconstruyen como 'editar' para no quitarle acceso a usuarios existentes.
     */
    public function mapaPermisos(): array
    {
        $mapa = $this->permisos_modulos ?? [];
        if (!empty($mapa)) {
            return $mapa;
        }

        $viejos = $this->modulos_permitidos ?? [];
        if (empty($viejos)) {
            $viejos = $this->modulosLegado();
        }

        $recon = [];
        foreach ($viejos as $m) {
            $recon[$m] = in_array($m, self::DEPARTAMENTOS, true) ? 'ver' : 'editar';
        }
        return $recon;
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