<?php

namespace Tests\Feature;

use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * En la Distribución, las obras con ingreso en el mes se muestran como "parcial" y
 * las que no tuvieron ingreso como "abierta". Las cerradas conservan su estado.
 */
class EstadoObraPorIngresoTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cuentaMayor, float $er, int $mes, int $anio, string $cc): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function con_ingreso_en_el_mes_se_muestra_parcial(): void
    {
        // Obra con ingreso en julio: debe salir "parcial" (Parcial seleccionado).
        $this->rf('MOB07001', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('MOB07001', 'Costos por aplicar', -100, 6, 2026, '14200105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        // Estado = control segmentado; el valor viaja en el input oculto estado_obra[...].
        $resp->assertSee('name="estado_obra[MOB07001]" id="estado-inp-MOB07001" value="parcial"', false);
        $resp->assertSee('data-estado="parcial" class="on"', false);
    }

    #[Test]
    public function sin_ingreso_en_el_mes_se_muestra_abierta(): void
    {
        // Obra sin ingreso en el mes filtrado (solo saldo de cuenta 14): debe salir "abierta".
        $this->rf('MOB07002', 'Costos por aplicar', -100, 6, 2026, '14200105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('name="estado_obra[MOB07002]" id="estado-inp-MOB07002" value="abierta"', false);
        $resp->assertSee('data-estado="abierta" class="on"', false);
    }

    #[Test]
    public function un_proyecto_con_saldo_solo_en_1455_no_aparece_en_distribucion(): void
    {
        // Inventario (1455) negativo y enorme, SIN saldo en 1420: no hay nada que distribuir,
        // así que el proyecto queda fuera del listado (antes se colaba como "abierto").
        $this->rf('MOB07010', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('MOB07010', 'Costos por aplicar', -50000000, 6, 2026, '14552599'); // 1455 inventario
        // Otro proyecto con saldo REAL en 1420: sí aparece.
        $this->rf('MOB07011', 'Costos por aplicar', -100, 6, 2026, '14200105');

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="card-MOB07010"', $html); // solo 1455 → fuera
        $this->assertStringContainsString('id="card-MOB07011"', $html);     // 1420 → aparece
    }

    #[Test]
    public function una_obra_cerrada_conserva_su_estado_aunque_tenga_ingreso(): void
    {
        // Obra con ingreso pero cerrada totalmente: sigue "cerrada", no se reabre a parcial.
        ProyectoCerrado::create([
            'codigo_proyecto' => 'MOB07003', 'tipo_cierre' => 'total',
            'user_id' => User::factory()->create()->id,
        ]);
        $this->rf('MOB07003', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('MOB07003', 'Costos por aplicar', -100, 6, 2026, '14200105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('name="estado_obra[MOB07003]" id="estado-inp-MOB07003" value="cerrada"', false);
        $resp->assertSee('data-estado="cerrada" class="on"', false);
    }
}
