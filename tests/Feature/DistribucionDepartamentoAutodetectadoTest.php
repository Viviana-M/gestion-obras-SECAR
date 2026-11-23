<?php

namespace Tests\Feature;

use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Un admin/director puede guardar la distribución SIN elegir departamento en el filtro: el sistema
 * lo deduce de las obras que vienen con datos (regla de prefijos). Si mezcla departamentos o no es
 * deducible, responde 422 con un mensaje claro (no mudo).
 */
class DistribucionDepartamentoAutodetectadoTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function admin(): User
    {
        return User::factory()->create([
            'rol' => 'admin', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar', 'contabilidad' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cuentaMayor, float $er, int $mes, int $anio, string $cc = '000000'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function admin_sin_filtro_con_un_solo_departamento_guarda_y_autodetecta(): void
    {
        // Obra C-700 → prefijo C → mantenimiento. El admin NO manda 'departamento'.
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');

        $this->actingAs($this->admin())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026,
            'estado_obra' => ['C-700' => 'parcial'],
            'aplicar'     => ['C-700' => ['14200105' => 600]],
        ])->assertRedirect()->assertSessionMissing('error');

        // El plano quedó adjunto al departamento deducido (mantenimiento), sin que el admin filtrara.
        $this->assertDatabaseHas('distribuciones', [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
        ]);
    }

    #[Test]
    public function admin_autoguardado_sin_filtro_un_departamento_responde_ok(): void
    {
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');

        $this->actingAs($this->admin())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'mes' => 7, 'anio' => 2026,
            'aplicar' => ['C-700' => ['14200105' => 500]],
        ])->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function admin_sin_filtro_que_mezcla_departamentos_recibe_422_claro(): void
    {
        // C-700 (mantenimiento) + O-900 (instalaciones) en el mismo envío.
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $this->rf('O-900', 'Costos por aplicar', -1000, 6, 2026, '14200105');

        $resp = $this->actingAs($this->admin())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'mes' => 7, 'anio' => 2026,
            'aplicar' => ['C-700' => ['14200105' => 100], 'O-900' => ['14200105' => 100]],
        ]);

        $resp->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('mantenimiento y de instalaciones', $resp->json('error'));
        $this->assertSame(0, Distribucion::count());
    }

    #[Test]
    public function admin_sin_filtro_sin_departamento_deducible_recibe_422_claro(): void
    {
        // Código sin prefijo de departamento conocido → no deducible.
        $this->rf('XYZ-1', 'Costos por aplicar', -1000, 6, 2026, '14200105');

        $resp = $this->actingAs($this->admin())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'mes' => 7, 'anio' => 2026,
            'aplicar' => ['XYZ-1' => ['14200105' => 100]],
        ]);

        $resp->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('No pude determinar el departamento', $resp->json('error'));
    }
}
