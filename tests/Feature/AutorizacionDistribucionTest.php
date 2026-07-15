<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\AutorizacionDistribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutorizacionDistribucionTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol'              => 'aux_costos',
            'activo'           => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function gerente(): User
    {
        return User::factory()->create([
            'rol'    => 'gerente',
            'activo' => true,
        ]);
    }

    #[Test]
    public function el_helper_es_gerencia_reconoce_admin_gerente_y_directores(): void
    {
        $esGerencia = fn (string $rol) => (new User(['rol' => $rol]))->esGerencia();

        $this->assertTrue($esGerencia('admin'));
        $this->assertTrue($esGerencia('gerente'));
        $this->assertTrue($esGerencia('dir_operaciones'));
        $this->assertTrue($esGerencia('dir_instalaciones'));
        $this->assertTrue($esGerencia('dir_admin_auditoria'));
        $this->assertTrue($esGerencia('director_comercial'));
        $this->assertTrue($esGerencia('director_compras'));

        $this->assertFalse($esGerencia('aux_costos'));
        $this->assertFalse($esGerencia('comercial'));
        $this->assertFalse($esGerencia('supervisor_mtto'));
    }

    #[Test]
    public function el_operador_puede_solicitar_autorizacion_y_queda_pendiente(): void
    {
        $op = $this->operador();

        $resp = $this->actingAs($op)->post(route('operativo.autorizaciones.solicitar'), [
            'codigo_proyecto' => 'C-100',
            'mes'             => 7,
            'anio'            => 2026,
            'motivo'          => 'Costo devengado sin facturación aún.',
        ]);

        $resp->assertRedirect();
        $this->assertDatabaseHas('autorizaciones_distribucion', [
            'codigo_proyecto' => 'C-100',
            'mes'             => 7,
            'anio'            => 2026,
            'estado'          => 'pendiente',
            'solicitado_por'  => $op->id,
        ]);
    }

    #[Test]
    public function un_usuario_sin_permiso_de_operacion_no_puede_solicitar(): void
    {
        $sinPermiso = User::factory()->create([
            'rol'              => 'comercial',
            'activo'           => true,
            'permisos_modulos' => ['comercial' => 'ver'],
        ]);

        $this->actingAs($sinPermiso)->post(route('operativo.autorizaciones.solicitar'), [
            'codigo_proyecto' => 'C-100',
            'mes'             => 7,
            'anio'            => 2026,
            'motivo'          => 'x',
        ])->assertForbidden();
    }

    #[Test]
    public function gerencia_aprueba_y_el_proyecto_queda_autorizado(): void
    {
        $sol = AutorizacionDistribucion::create([
            'codigo_proyecto' => 'C-200',
            'mes'             => 7,
            'anio'            => 2026,
            'estado'          => 'pendiente',
            'motivo'          => 'motivo',
            'solicitado_por'  => $this->operador()->id,
            'solicitado_at'   => now(),
        ]);

        $this->actingAs($this->gerente())
            ->post(route('operativo.autorizaciones.aprobar', $sol->id), ['comentario_gerencia' => 'OK'])
            ->assertRedirect();

        $this->assertTrue(AutorizacionDistribucion::estaAprobado('C-200', 7, 2026));
        $this->assertEquals(['C-200'], AutorizacionDistribucion::aprobadosEn(7, 2026));
    }

    #[Test]
    public function un_no_gerencia_no_puede_aprobar(): void
    {
        $sol = AutorizacionDistribucion::create([
            'codigo_proyecto' => 'C-300', 'mes' => 7, 'anio' => 2026,
            'estado' => 'pendiente', 'solicitado_at' => now(),
        ]);

        $this->actingAs($this->operador())
            ->post(route('operativo.autorizaciones.aprobar', $sol->id))
            ->assertForbidden();

        $this->assertDatabaseHas('autorizaciones_distribucion', [
            'id' => $sol->id, 'estado' => 'pendiente',
        ]);
    }

    #[Test]
    public function guardar_ignora_costos_de_proyecto_sin_ingreso_sin_autorizacion(): void
    {
        $op = $this->operador();

        // Proyecto C-400 no tiene ingreso (no hay RegistroFinanciero) => bloqueado.
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion'       => 'guardar',
            'mes'          => 7,
            'anio'         => 2026,
            'departamento' => 'mantenimiento',
            'aplicar'      => ['C-400' => ['1435' => 500]],
        ])->assertRedirect();

        $this->assertSame(0, AplicacionCosto::where('codigo_proyecto', 'C-400')->count(),
            'No debe guardar costos de un proyecto sin ingreso ni autorización.');
    }

    #[Test]
    public function guardar_permite_costos_cuando_hay_autorizacion_aprobada(): void
    {
        $op = $this->operador();

        AutorizacionDistribucion::create([
            'codigo_proyecto' => 'C-500', 'mes' => 7, 'anio' => 2026,
            'estado' => 'aprobada', 'solicitado_at' => now(), 'resuelto_at' => now(),
        ]);

        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion'       => 'guardar',
            'mes'          => 7,
            'anio'         => 2026,
            'departamento' => 'mantenimiento',
            'aplicar'      => ['C-500' => ['1435' => 500]],
        ])->assertRedirect();

        $this->assertSame(1, AplicacionCosto::where('codigo_proyecto', 'C-500')->count(),
            'Con autorización aprobada, el costo sí debe guardarse.');
    }

    /** Proyecto con saldo en cuenta 14 pero SIN ingreso en el mes. */
    private function seedProyectoSinIngreso(string $cod): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod,
            'nombre_proyecto' => 'Obra sin ingreso',
            'cuenta_contable' => '143501',
            'cuenta_mayor'    => 'Costos por aplicar',
            'estado_er'       => -1000, // saldo pendiente por aplicar
            'valor_debito'    => 0,
            'valor_credito'   => 0,
            'mes'             => 7,
            'anio'            => 2026,
        ]);
    }

    #[Test]
    public function la_pantalla_muestra_el_bloqueo_para_proyecto_sin_ingreso(): void
    {
        $this->seedProyectoSinIngreso('C-900');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Sin ingreso en el mes');
        $resp->assertSee('Solicitar autorización');
    }

    #[Test]
    public function la_pantalla_desbloquea_el_proyecto_una_vez_autorizado(): void
    {
        $this->seedProyectoSinIngreso('C-901');
        AutorizacionDistribucion::create([
            'codigo_proyecto' => 'C-901', 'mes' => 7, 'anio' => 2026,
            'estado' => 'aprobada', 'solicitado_at' => now(), 'resuelto_at' => now(),
        ]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Autorizado por gerencia');
        $resp->assertDontSee('Solicitar autorización');
    }
}
