<?php

namespace Tests\Feature;

use App\Models\ManoObraDirecta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManoObraDirectaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'activo' => true]);
    }

    #[Test]
    public function el_admin_crea_una_persona(): void
    {
        $this->actingAs($this->admin())->post(route('admin.mano-obra-directa.store'), [
            'cedula' => '111', 'nombre' => 'perez juan',
            'pct_mantenimiento' => 60, 'pct_instalaciones' => 40,
        ])->assertRedirect();

        $this->assertDatabaseHas('mano_obra_directa', [
            'cedula' => '111', 'nombre' => 'perez juan',
            'pct_mantenimiento' => 60, 'pct_instalaciones' => 40, 'activo' => 1,
        ]);
    }

    #[Test]
    public function rechaza_si_los_porcentajes_suman_mas_de_100(): void
    {
        $this->actingAs($this->admin())->post(route('admin.mano-obra-directa.store'), [
            'cedula' => '222', 'nombre' => 'gomez ana',
            'pct_mantenimiento' => 70, 'pct_instalaciones' => 40, // 110 > 100
        ])->assertSessionHasErrors('pct_mantenimiento');

        $this->assertSame(0, ManoObraDirecta::count());
    }

    #[Test]
    public function la_cedula_es_unica(): void
    {
        ManoObraDirecta::create(['cedula' => '333', 'nombre' => 'X', 'pct_mantenimiento' => 0, 'pct_instalaciones' => 0]);

        $this->actingAs($this->admin())->post(route('admin.mano-obra-directa.store'), [
            'cedula' => '333', 'nombre' => 'Otro', 'pct_mantenimiento' => 10, 'pct_instalaciones' => 10,
        ])->assertSessionHasErrors('cedula');

        $this->assertSame(1, ManoObraDirecta::count());
    }

    #[Test]
    public function actualiza_y_alterna_el_estado(): void
    {
        $p = ManoObraDirecta::create(['cedula' => '444', 'nombre' => 'Vieja', 'pct_mantenimiento' => 50, 'pct_instalaciones' => 50, 'activo' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.mano-obra-directa.update', $p->id), [
            'nombre' => 'Nueva', 'pct_mantenimiento' => 30, 'pct_instalaciones' => 20,
        ])->assertRedirect();
        $p->refresh();
        $this->assertSame('Nueva', $p->nombre);
        $this->assertEquals(30.0, $p->pct_mantenimiento);

        $this->actingAs($admin)->put(route('admin.mano-obra-directa.toggle', $p->id))->assertRedirect();
        $this->assertFalse($p->refresh()->activo);
    }

    #[Test]
    public function un_no_admin_no_puede_entrar(): void
    {
        $noAdmin = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);

        $this->actingAs($noAdmin)->get(route('admin.mano-obra-directa.index'))->assertForbidden();
        $this->actingAs($noAdmin)->post(route('admin.mano-obra-directa.store'), [
            'cedula' => '999', 'nombre' => 'X',
        ])->assertForbidden();
    }

    #[Test]
    public function el_scope_activos_filtra(): void
    {
        ManoObraDirecta::create(['cedula' => 'a', 'nombre' => 'A', 'activo' => true]);
        ManoObraDirecta::create(['cedula' => 'b', 'nombre' => 'B', 'activo' => false]);

        $this->assertSame(1, ManoObraDirecta::activos()->count());
    }
}
