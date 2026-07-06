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

    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'sede',
        'menu_colapsado',
        'modulos_permitidos',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'menu_colapsado'     => 'boolean',
            'modulos_permitidos' => 'array',
            'activo'             => 'boolean',
        ];
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // ¿Es administrador?
    public function esAdmin(): bool
    {
        return $this->rol === 'admin';
    }

    // Acceso heredado del rol antiguo (compatibilidad con usuarios existentes).
    public function modulosLegado(): array
    {
        return [
            'financiero' => ['gestion_financiera'],
            'operativo'  => ['operacion'],
            'comercial'  => ['comercial'],
            'contable'   => ['contabilidad'],
        ][$this->rol] ?? [];
    }

    // ¿Puede ver este módulo?
    // Admin ve TODO. Si tiene checks guardados, mandan los checks.
    // Si no tiene checks (usuario antiguo), conserva su acceso por rol.
    public function puedeVerModulo(string $clave): bool
    {
        if ($this->esAdmin()) {
            return true;
        }

        $permitidos = $this->modulos_permitidos ?? [];

        if (empty($permitidos)) {
            $permitidos = $this->modulosLegado();
        }

        return in_array($clave, $permitidos);
    }

    // Departamentos que el usuario puede ver (para Distribución de costos).
    // Admin ve ambos. Los demás, según sus módulos marcados.
    // Devuelve las claves de prefijos que le corresponden.
    public function departamentosPermitidos(): array
    {
        // Mapa: cada departamento y los prefijos de obra que le pertenecen.
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

    // Devuelve el código de departamento del usuario si tiene UNO solo.
    // Supervisor de mantenimiento -> 'mantenimiento'; de instalaciones -> 'instalaciones'.
    // Si tiene ambos (director) o es admin -> null (debe elegir en pantalla).
    public function departamentoUnico(): ?string
    {
        $mant = $this->puedeVerModulo('dep_mantenimiento');
        $inst = $this->puedeVerModulo('dep_instalaciones');

        if ($this->esAdmin()) return null;      // admin elige
        if ($mant && $inst)   return null;       // director elige
        if ($mant)            return 'mantenimiento';
        if ($inst)            return 'instalaciones';
        return null;
    }

    // Prefijos de obra de un departamento dado.
    public static function prefijosDeDepartamento(string $dep): array
    {
        return [
            'mantenimiento' => ['C', 'R', 'MO', 'GM'],
            'instalaciones' => ['GI', 'O'],
        ][$dep] ?? [];
    }
    // ¿Puede ver ALGÚN departamento? (para saber si filtrar o no)
    public function tieneFiltroDepartamento(): bool
    {
        if ($this->esAdmin()) return false; // admin ve todo, sin filtro
        // Si no tiene marcado ningún departamento, no filtramos por ahora
        // (para no bloquear usuarios existentes). Filtra solo si marcó al menos uno.
        return $this->puedeVerModulo('dep_mantenimiento') || $this->puedeVerModulo('dep_instalaciones');
    }

    // Envía el correo de restablecimiento con el diseño de Secar
    // (en lugar del correo genérico de Laravel).
    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', [
            'token' => $token,
            'email' => $this->email,
        ]);

        Mail::to($this->email)->send(new ResetPasswordSecar($url, $this->name));
    }
}