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
            'monto'           => 500,
            'motivo'          => 'Costo devengado sin facturación aún.',
        ]);

        $resp->assertRedirect();

        $aut = AutorizacionDistribucion::delProyecto('C-100', 7, 2026)->first();
        $this->assertNotNull($aut);
        $this->assertSame('pendiente', $aut->estado);
        $this->assertSame($op->id, $aut->solicitado_por);
        $this->assertEquals(500.0, $aut->monto_a_distribuir);
        // Sin ingreso ni costo: margen = 0 − (0 + 500) = −500; el % queda null (sin ingreso).
        $this->assertEquals(-500.0, $aut->margen_mes_pesos);
        $this->assertNull($aut->margen_mes_pct);
        $this->assertEquals(-500.0, $aut->margen_total_pesos);
    }

    #[Test]
    public function la_solicitud_calcula_el_impacto_en_margen_con_ingreso(): void
    {
        $op = $this->operador();

        // Ingreso del mes = 2000, costo aplicado del mes = 300 (estado_er negativo).
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-110', 'cuenta_contable' => '413501', 'cuenta_mayor' => 'Ingreso',
            'estado_er' => 2000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-110', 'cuenta_contable' => '613501', 'cuenta_mayor' => 'Costos aplicados',
            'estado_er' => -300, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);

        $this->actingAs($op)->post(route('operativo.autorizaciones.solicitar'), [
            'codigo_proyecto' => 'C-110', 'mes' => 7, 'anio' => 2026,
            'monto' => 700, 'motivo' => 'x',
        ])->assertRedirect();

        $aut = AutorizacionDistribucion::delProyecto('C-110', 7, 2026)->first();
        // margen_mes = 2000 − (300 + 700) = 1000; % = 1000/2000 = 50%.
        $this->assertEquals(1000.0, $aut->margen_mes_pesos);
        $this->assertEquals(50.0, $aut->margen_mes_pct);
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

    #[Test]
    public function la_pantalla_de_gerencia_muestra_monto_e_impacto(): void
    {
        AutorizacionDistribucion::create([
            'codigo_proyecto' => 'C-700', 'mes' => 7, 'anio' => 2026, 'estado' => 'pendiente',
            'motivo' => 'prueba', 'monto_a_distribuir' => 1500,
            'margen_mes_pesos' => -1500, 'margen_mes_pct' => null,
            'margen_total_pesos' => 500, 'margen_total_pct' => 10,
            'solicitado_por' => $this->operador()->id, 'solicitado_at' => now(),
        ]);

        $resp = $this->actingAs($this->gerente())->get(route('operativo.autorizaciones.index'));

        $resp->assertStatus(200);
        $resp->assertSee('Monto a distribuir');
        $resp->assertSee('C-700');
        $resp->assertSee('sin ingreso'); // margen_mes_pct null se muestra como "sin ingreso"
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
    public function la_pantalla_agrupa_con_y_sin_ingreso(): void
    {
        // Proyecto CON ingreso (saldo en cuenta 14 + ingreso en el mes).
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-800', 'nombre_proyecto' => 'Obra con ingreso',
            'cuenta_contable' => '143502', 'cuenta_mayor' => 'Costos por aplicar',
            'estado_er' => -800, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-800', 'cuenta_contable' => '413502', 'cuenta_mayor' => 'Ingreso',
            'estado_er' => 1000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
        // Proyecto SIN ingreso.
        $this->seedProyectoSinIngreso('C-801');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Con ingreso');
        $resp->assertSee('Sin ingreso');
        $resp->assertSee('id="card-C-800"', false);
        $resp->assertSee('id="card-C-801"', false);
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
