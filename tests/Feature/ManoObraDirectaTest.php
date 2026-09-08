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
        // Ya no se piden porcentajes: la distribución por UN la trae Nómina en el cierre.
        $this->actingAs($this->admin())->post(route('admin.mano-obra-directa.store'), [
            'cedula' => '111', 'nombre' => 'perez juan',
        ])->assertRedirect();

        $this->assertDatabaseHas('mano_obra_directa', [
            'cedula' => '111', 'nombre' => 'perez juan', 'activo' => 1,
        ]);
    }

    #[Test]
    public function la_cedula_es_unica(): void
    {
        ManoObraDirecta::create(['cedula' => '333', 'nombre' => 'X']);

        $this->actingAs($this->admin())->post(route('admin.mano-obra-directa.store'), [
            'cedula' => '333', 'nombre' => 'Otro',
        ])->assertSessionHasErrors('cedula');

        $this->assertSame(1, ManoObraDirecta::count());
    }

    #[Test]
    public function actualiza_y_alterna_el_estado(): void
    {
        $p = ManoObraDirecta::create(['cedula' => '444', 'nombre' => 'Vieja', 'activo' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.mano-obra-directa.update', $p->id), [
            'nombre' => 'Nueva',
        ])->assertRedirect();
        $this->assertSame('Nueva', $p->refresh()->nombre);

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
