<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La cuenta 14 de las obras se corta al mes filtrado (acumulado):
 * al filtrar un mes histórico se ve su estado real a esa fecha, sin
 * contaminarse con reclasificaciones de meses posteriores.
 */
class DistribucionCorteMesTest extends TestCase
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
    public function la_vista_todo_muestra_la_cuenta14_acumulada_al_mes_filtrado(): void
    {
        // OB008610: costo por aplicar en ABRIL (-1000) que en JUNIO se reclasifica
        // por completo hacia afuera (+1000). Neto de TODOS los períodos = 0.
        $this->rf('OB008610', 'Ingreso', 8000, 4, 2026, '41350100');
        $this->rf('OB008610', 'Costos por aplicar', -1000, 4, 2026, '14350105');
        $this->rf('OB008610', 'Costos por aplicar', 1000, 6, 2026, '14350105'); // reclasificado en junio

        // Filtrando ABRIL (vista por defecto "todo") la obra DEBE aparecer con su
        // saldo de abril: el acumulado a abril es -1000, sin ver el ajuste de junio.
        $abril = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=4&anio=2026&departamento=instalaciones');
        $abril->assertStatus(200);
        $abril->assertSee('OB008610', false);

        // Filtrando JUNIO el acumulado neto es 0 (abril −1000 + junio +1000): ya no
        // queda nada pendiente en cuenta 14, así que la obra NO debe aparecer.
        $junio = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=6&anio=2026&departamento=instalaciones');
        $junio->assertStatus(200);
        $junio->assertDontSee('OB008610', false);
    }

    #[Test]
    public function la_vista_mes_sigue_mostrando_solo_el_mes_seleccionado(): void
    {
        // Costo en abril, sin movimientos posteriores.
        $this->rf('OB008611', 'Ingreso', 8000, 4, 2026, '41350100');
        $this->rf('OB008611', 'Costos por aplicar', -1000, 4, 2026, '14350105');

        // Vista "mes" en MAYO: no hubo movimientos de cuenta 14 en mayo → no aparece.
        $mayo = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=5&anio=2026&departamento=instalaciones&vista=mes');
        $mayo->assertStatus(200);
        $mayo->assertDontSee('OB008611', false);

        // Vista "mes" en ABRIL: sí aparece (movimiento del propio mes).
        $abril = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=4&anio=2026&departamento=instalaciones&vista=mes');
        $abril->assertStatus(200);
        $abril->assertSee('OB008611', false);
    }
}
