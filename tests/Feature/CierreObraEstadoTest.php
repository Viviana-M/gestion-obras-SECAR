<?php

namespace Tests\Feature;

use App\Models\ObraEstado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Al enviar una obra a "cerrada" con todo el saldo DISTRIBUIBLE (1420) aplicado, debe quedar
 * guardada como 'cerrada' (y no degradarse a 'parcial'). El chequeo de cierre debe mirar lo mismo
 * que la pantalla: solo 1420, no las otras 14xx (1455 inventario en tránsito), que no se distribuyen.
 */
class CierreObraEstadoTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cod, string $cuenta, float $er, int $mes, string $mayor = 'Costos por aplicar'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra', 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => $mayor, 'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => 2026,
        ]);
    }

    #[Test]
    public function cerrar_con_el_1420_aplicado_guarda_cerrada_aunque_haya_saldo_en_1455(): void
    {
        $user = $this->operador();
        // Obra con ingreso, 1420 pendiente 1.000.000 (distribuible) y un 1455 de 500.000 (NO distribuible).
        $this->rf('C-1', '41350100', 5000000, 7, 'Ingreso');
        $this->rf('C-1', '14200105', -1000000, 6);  // 1420: se distribuye
        $this->rf('C-1', '14552505', -500000, 6);   // 1455: inventario en tránsito, no se distribuye

        $this->actingAs($user)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'estado_obra' => ['C-1' => 'cerrada'],
            'aplicar'     => ['C-1' => ['14200105' => '1.000.000']],  // llega con separador de miles
        ])->assertRedirect();

        $this->assertSame('cerrada', ObraEstado::where('codigo_proyecto', 'C-1')->value('estado'),
            'Con el 1420 totalmente aplicado, la obra debe quedar cerrada (el 1455 no bloquea).');
    }

    #[Test]
    public function cerrar_sin_aplicar_el_1420_pendiente_si_degrada_a_parcial(): void
    {
        $user = $this->operador();
        // 1420 pendiente 1.000.000 pero NO se aplica nada → sigue habiendo saldo distribuible → parcial.
        $this->rf('C-2', '41350100', 5000000, 7, 'Ingreso');
        $this->rf('C-2', '14200105', -1000000, 6);

        $this->actingAs($user)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'estado_obra' => ['C-2' => 'cerrada'],
            'aplicar'     => ['C-2' => []],
        ])->assertRedirect();

        $this->assertSame('parcial', ObraEstado::where('codigo_proyecto', 'C-2')->value('estado'),
            'Si queda saldo 1420 sin distribuir, no puede cerrarse: debe degradar a parcial.');
    }
}
