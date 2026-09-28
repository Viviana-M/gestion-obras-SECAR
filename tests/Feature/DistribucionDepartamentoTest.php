<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * En Distribución cada persona ve SOLO el departamento que tiene en su perfil de
 * usuario (permisos dep_mantenimiento / dep_instalaciones). Con un único departamento
 * no aparece el selector y las obras de los otros departamentos quedan fuera, incluso
 * si manipulan el parámetro de la URL. Admin/directores con ambos sí eligen.
 */
class DistribucionDepartamentoTest extends TestCase
{
    use RefreshDatabase;

    /** Usuario de operación con UN solo departamento en su perfil. */
    private function operadorDe(string $dep): User
    {
        return User::factory()->create([
            'rol' => 'supervisor_mtto', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar', 'dep_'.$dep => 'ver'],
        ]);
    }

    private function obra(string $codigo, int $mes, int $anio): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso',
            'estado_er' => 5000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio]);
        RegistroFinanciero::create(['codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar',
            'estado_er' => -1000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio]);
    }

    #[Test]
    public function un_operador_ve_solo_las_obras_de_su_departamento(): void
    {
        $this->obra('MO4501', 6, 2026);   // Mantenimiento (prefijo MO)
        $this->obra('GI0100', 6, 2026);   // Instalaciones (prefijo GI)

        $resp = $this->actingAs($this->operadorDe('mantenimiento'))
            ->get('/operativo/distribucion?mes=7&anio=2026');

        $resp->assertStatus(200);
        $resp->assertSee('MO4501', false);        // la suya sí
        $resp->assertDontSee('GI0100', false);    // la de instalaciones NO
    }

    #[Test]
    public function con_un_solo_departamento_no_aparece_el_selector_y_el_banner_lo_muestra(): void
    {
        $this->obra('MO4501', 6, 2026);

        $resp = $this->actingAs($this->operadorDe('mantenimiento'))
            ->get('/operativo/distribucion?mes=7&anio=2026');

        $resp->assertStatus(200);
        // No hay selector de departamento (sí existe un hidden con name="departamento",
        // por eso comprobamos el <select> concreto por su id).
        $resp->assertDontSee('id="sel-departamento"', false);
        // El banner muestra el departamento del perfil.
        $resp->assertSee('Mantenimiento (MT)', false);
    }

    #[Test]
    public function no_puede_colarse_a_otro_departamento_por_la_url(): void
    {
        $this->obra('MO4501', 6, 2026);   // suya (mantenimiento)
        $this->obra('GI0100', 6, 2026);   // ajena (instalaciones)

        // Fuerza ?departamento=instalaciones: el servidor manda el del perfil.
        $resp = $this->actingAs($this->operadorDe('mantenimiento'))
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=instalaciones');

        $resp->assertStatus(200);
        $resp->assertSee('MO4501', false);        // sigue viendo solo la suya
        $resp->assertDontSee('GI0100', false);
    }

    #[Test]
    public function un_usuario_con_ambos_departamentos_si_elige_con_el_selector(): void
    {
        $this->obra('MO4501', 6, 2026);

        $usuario = User::factory()->create([
            'rol' => 'dir_operaciones', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar', 'dep_mantenimiento' => 'ver', 'dep_instalaciones' => 'ver'],
        ]);

        $resp = $this->actingAs($usuario)->get('/operativo/distribucion?mes=7&anio=2026');

        $resp->assertStatus(200);
        // Con ambos departamentos sí aparece el selector para elegir.
        $resp->assertSee('id="sel-departamento"', false);
    }
}
