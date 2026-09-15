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
        $this->actingAs($this->admin())->post(route('contable.mano-obra-directa.store'), [
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

        $this->actingAs($this->admin())->post(route('contable.mano-obra-directa.store'), [
            'cedula' => '333', 'nombre' => 'Otro',
        ])->assertSessionHasErrors('cedula');

        $this->assertSame(1, ManoObraDirecta::count());
    }

    #[Test]
    public function actualiza_y_alterna_el_estado(): void
    {
        $p = ManoObraDirecta::create(['cedula' => '444', 'nombre' => 'Vieja', 'activo' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('contable.mano-obra-directa.update', $p->id), [
            'nombre' => 'Nueva',
        ])->assertRedirect();
        $this->assertSame('Nueva', $p->refresh()->nombre);

        $this->actingAs($admin)->put(route('contable.mano-obra-directa.toggle', $p->id))->assertRedirect();
        $this->assertFalse($p->refresh()->activo);
    }

    #[Test]
    public function contabilidad_puede_gestionar_el_maestro(): void
    {
        // El maestro vive ahora bajo Contabilidad: una persona con ese módulo (editar) entra y crea.
        $contable = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);

        $this->actingAs($contable)->get(route('contable.mano-obra-directa.index'))->assertOk();
        $this->actingAs($contable)->post(route('contable.mano-obra-directa.store'), [
            'cedula' => '777', 'nombre' => 'CONTABLE CREA',
        ])->assertRedirect();
        $this->assertDatabaseHas('mano_obra_directa', ['cedula' => '777']);
    }

    #[Test]
    public function contabilidad_solo_lectura_no_puede_modificar(): void
    {
        // Con permiso de solo ver: puede abrir la lista pero no crear ni cambiar.
        $soloVer = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'ver'],
        ]);

        $this->actingAs($soloVer)->get(route('contable.mano-obra-directa.index'))->assertOk();
        $this->actingAs($soloVer)->post(route('contable.mano-obra-directa.store'), [
            'cedula' => '999', 'nombre' => 'X',
        ])->assertForbidden();
    }

    #[Test]
    public function sin_el_modulo_de_contabilidad_no_puede_entrar(): void
    {
        $ajeno = User::factory()->create([
            'rol' => 'operario', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);

        $this->actingAs($ajeno)->get(route('contable.mano-obra-directa.index'))->assertForbidden();
        $this->actingAs($ajeno)->post(route('contable.mano-obra-directa.store'), [
            'cedula' => '888', 'nombre' => 'X',
        ])->assertForbidden();
    }

    #[Test]
    public function el_index_ordena_por_nombre_filtra_por_estado_y_busca(): void
    {
        ManoObraDirecta::create(['cedula' => '1', 'nombre' => 'ZULETA ANA', 'activo' => true]);
        ManoObraDirecta::create(['cedula' => '2', 'nombre' => 'AGUIRRE LUIS', 'activo' => true]);
        ManoObraDirecta::create(['cedula' => '3', 'nombre' => 'BORRADO PEDRO', 'activo' => false]);
        $admin = $this->admin();

        // Por defecto: solo activos y ordenados por nombre.
        $r = $this->actingAs($admin)->get(route('contable.mano-obra-directa.index'));
        $r->assertOk();
        $this->assertSame(['AGUIRRE LUIS', 'ZULETA ANA'], $r->viewData('personas')->pluck('nombre')->all());

        // estado=todos incluye inactivos.
        $todos = $this->actingAs($admin)->get(route('contable.mano-obra-directa.index', ['estado' => 'todos']));
        $this->assertContains('BORRADO PEDRO', $todos->viewData('personas')->pluck('nombre')->all());

        // estado=inactivos solo inactivos.
        $inact = $this->actingAs($admin)->get(route('contable.mano-obra-directa.index', ['estado' => 'inactivos']));
        $this->assertSame(['BORRADO PEDRO'], $inact->viewData('personas')->pluck('nombre')->all());

        // Buscador por nombre parcial.
        $porNom = $this->actingAs($admin)->get(route('contable.mano-obra-directa.index', ['q' => 'aguirre']));
        $this->assertSame(['AGUIRRE LUIS'], $porNom->viewData('personas')->pluck('nombre')->all());

        // Buscador por cédula.
        $porCed = $this->actingAs($admin)->get(route('contable.mano-obra-directa.index', ['q' => '1']));
        $this->assertSame(['ZULETA ANA'], $porCed->viewData('personas')->pluck('nombre')->all());
    }

    #[Test]
    public function el_texto_de_ayuda_es_en_lenguaje_sencillo(): void
    {
        $r = $this->actingAs($this->admin())->get(route('contable.mano-obra-directa.index'));
        $r->assertOk();
        $r->assertSee('se reparte entre las áreas', false);
        // El texto técnico anterior ya no está.
        $r->assertDontSee('se reclasifica de la cuenta 14', false);
        $r->assertDontSee('planilla PILA', false);
    }

    #[Test]
    public function el_scope_activos_filtra(): void
    {
        ManoObraDirecta::create(['cedula' => 'a', 'nombre' => 'A', 'activo' => true]);
        ManoObraDirecta::create(['cedula' => 'b', 'nombre' => 'B', 'activo' => false]);

        $this->assertSame(1, ManoObraDirecta::activos()->count());
    }
}
