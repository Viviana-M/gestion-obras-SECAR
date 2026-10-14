<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Página de inicio = el primer módulo que el usuario puede ver (orden del menú),
 * no un destino fijo por rol.
 */
class PaginaInicioTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $modulos, string $rol = 'aux'): User
    {
        return User::factory()->create([
            'rol' => $rol, 'activo' => true, 'permisos_modulos' => $modulos,
        ]);
    }

    #[Test]
    public function el_helper_devuelve_el_primer_modulo_visible_en_orden_de_menu(): void
    {
        $this->assertSame('/dashboard', $this->usuario(['gestion_financiera' => 'ver'])->paginaInicio());
        $this->assertSame('/operativo/distribucion', $this->usuario(['operacion' => 'editar'])->paginaInicio());
        $this->assertSame('/contable/homologaciones', $this->usuario(['contabilidad' => 'ver'])->paginaInicio());

        // Con varios módulos, gana el primero del menú (financiero antes que operación).
        $this->assertSame('/dashboard', $this->usuario(['operacion' => 'ver', 'gestion_financiera' => 'ver'])->paginaInicio());

        // Sin ningún módulo: a un lugar seguro (perfil).
        $this->assertSame('/profile', $this->usuario([])->paginaInicio());

        // Admin ve todo: cae en el primero.
        $this->assertSame('/dashboard', User::factory()->create(['rol' => 'admin', 'activo' => true])->paginaInicio());
    }

    #[Test]
    public function el_login_aterriza_en_el_primer_modulo_con_permiso(): void
    {
        $oper = $this->usuario(['operacion' => 'editar']);

        $this->post('/login', ['email' => $oper->email, 'password' => 'password'])
            ->assertRedirect('/operativo/distribucion');
    }

    #[Test]
    public function un_usuario_solo_operativo_no_puede_ver_el_dashboard_financiero(): void
    {
        $oper = $this->usuario(['operacion' => 'editar']);

        // Aunque escriba /dashboard a mano, lo mandan a su inicio.
        $this->actingAs($oper)->get('/dashboard')->assertRedirect('/operativo/distribucion');
        // Y la raíz también lleva a su inicio.
        $this->actingAs($oper)->get('/')->assertRedirect('/operativo/distribucion');
    }

    #[Test]
    public function un_usuario_financiero_si_ve_el_dashboard(): void
    {
        $fin = $this->usuario(['gestion_financiera' => 'ver']);

        $this->actingAs($fin)->get('/dashboard')->assertStatus(200);
        $this->actingAs($fin)->get('/')->assertRedirect('/dashboard');
    }

    #[Test]
    public function el_admin_ve_el_dashboard(): void
    {
        $admin = User::factory()->create(['rol' => 'admin', 'activo' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertStatus(200);
        $this->actingAs($admin)->get('/')->assertRedirect('/dashboard');
    }
}
