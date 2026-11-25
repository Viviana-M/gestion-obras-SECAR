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
 * "Guardar cambios" manual por AJAX: responde JSON (sin redirect) para que el usuario quede en la
 * misma OT. "Enviar · generar plano" y el guardado no-AJAX siguen con redirect.
 */
class GuardarManualAjaxTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function seedObra(): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Obra',
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 5000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Obra',
            'cuenta_contable' => '14200105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -1000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);
    }

    #[Test]
    public function guardar_manual_ajax_responde_json_sin_recargar(): void
    {
        $this->seedObra();

        $resp = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 600]],
            'estado_obra' => ['C-700' => 'parcial'],
        ]);

        $resp->assertOk()
            ->assertJson(['ok' => true, 'auto' => false])
            ->assertJsonStructure(['ok', 'dist', 'hora', 'lock_version', 'msg']);

        $this->assertDatabaseHas('distribuciones', [
            'id' => $resp->json('dist'), 'departamento' => 'mantenimiento', 'estado' => 'borrador',
        ]);
    }

    #[Test]
    public function el_guardado_no_ajax_sigue_redirigiendo(): void
    {
        $this->seedObra();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 600]],
            'estado_obra' => ['C-700' => 'parcial'],
        ])->assertRedirect();
    }

    #[Test]
    public function enviar_por_ajax_no_responde_json_de_guardado(): void
    {
        $this->seedObra();

        // accion=enviar NO toma la rama JSON de guardado (sigue el flujo de envío/redirect).
        $resp = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 600]],
            'estado_obra' => ['C-700' => 'cerrada'],
        ]);

        // No es el JSON {ok:true} del guardado manual (es un redirect, que en JSON llega como 3xx).
        $this->assertNotSame(200, $resp->getStatusCode());
    }
}
