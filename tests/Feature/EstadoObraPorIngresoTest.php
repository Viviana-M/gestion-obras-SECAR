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
        $this->rf('MOB07001', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('<option value="parcial" selected>Parcial</option>', false);
    }

    #[Test]
    public function sin_ingreso_en_el_mes_se_muestra_abierta(): void
    {
        // Obra sin ingreso en el mes filtrado (solo saldo de cuenta 14): debe salir "abierta".
        $this->rf('MOB07002', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('<option value="abierta" selected>Abierta</option>', false);
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
        $this->rf('MOB07003', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('<option value="cerrada" selected>Cerrada</option>', false);
    }
}
