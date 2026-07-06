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