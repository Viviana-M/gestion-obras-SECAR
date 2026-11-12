<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rol "consulta_obras": usuario de SOLO LECTURA encerrado en la pantalla Consulta de Obras.
 * Solo ve las obras de su departamento y no puede entrar a ninguna otra pantalla.
 */
class ConsultaObrasRolTest extends TestCase
{
    use RefreshDatabase;

    private function consultaObras(string $dep = 'dep_mantenimiento'): User
    {
        return User::factory()->create([
            'rol' => 'consulta_obras', 'activo' => true, 'email_verified_at' => now(),
            'permisos_modulos' => ['operacion' => 'ver', $dep => 'ver'],
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'activo' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function entra_a_consulta_de_obras(): void
    {
        $this->actingAs($this->consultaObras())
            ->get(route('operativo.consulta-obras', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk();
    }

    #[Test]
    public function aterriza_en_consulta_de_obras_al_entrar_a_la_raiz(): void
    {
        $this->actingAs($this->consultaObras())
            ->get('/')
            ->assertRedirect(route('operativo.consulta-obras', absolute: false));
    }

    #[Test]
    public function el_dashboard_lo_reencamina_a_consulta_de_obras(): void
    {
        $this->actingAs($this->consultaObras())
            ->get('/dashboard')
            ->assertRedirect(route('operativo.consulta-obras'));
    }

    #[Test]
    public function cualquier_otra_pantalla_le_da_403(): void
    {
        $u = $this->consultaObras();

        $this->actingAs($u)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')->assertForbidden();
        $this->actingAs($u)->get(route('admin.usuarios.index'))->assertForbidden();
        $this->actingAs($u)->get('/contable/homologaciones')->assertForbidden();
        $this->actingAs($u)->get(route('operativo.consulta-provisiones'))->assertForbidden();
    }

    #[Test]
    public function en_el_menu_solo_aparece_consulta_de_obras(): void
    {
        $html = $this->actingAs($this->consultaObras())
            ->get(route('operativo.consulta-obras', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Consulta de Obras', $html);
        // No aparecen las demás entradas del menú de Operaciones ni otros módulos.
        $this->assertStringNotContainsString('Informe de costos', $html);
        $this->assertStringNotContainsString('Facturación por tipo', $html);
        $this->assertStringNotContainsString('Homologación de cuentas', $html);
    }

    #[Test]
    public function el_admin_crea_un_usuario_de_solo_consulta_con_su_departamento(): void
    {
        $this->actingAs($this->admin())->post(route('admin.usuarios.store'), [
            'name' => 'Solo Lectura', 'email' => 'consulta@secar.test',
            'rol' => 'consulta_obras', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'permisos' => ['dep_instalaciones' => 'ver'],
        ])->assertRedirect(route('admin.usuarios.index'));

        $u = User::where('email', 'consulta@secar.test')->first();
        $this->assertNotNull($u);
        $this->assertSame('consulta_obras', $u->rol);
        // Se fuerza operación:ver (para la ruta) + su departamento; nada más.
        $this->assertSame(['operacion' => 'ver', 'dep_instalaciones' => 'ver'], $u->permisos_modulos);
        $this->assertTrue($u->esConsultaObras());
    }

    #[Test]
    public function exige_departamento_al_crear_solo_consulta(): void
    {
        $this->actingAs($this->admin())->post(route('admin.usuarios.store'), [
            'name' => 'Sin Depto', 'email' => 'sindepto@secar.test',
            'rol' => 'consulta_obras', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'permisos' => [],
        ])->assertSessionHasErrors('rol');

        $this->assertNull(User::where('email', 'sindepto@secar.test')->first());
    }

    #[Test]
    public function los_demas_roles_no_cambian_su_acceso(): void
    {
        // Un aux con operación:editar sigue entrando a la distribución (no lo toca el encierro).
        $editor = User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'email_verified_at' => now(),
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);

        $this->actingAs($editor)
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk();
    }
}
