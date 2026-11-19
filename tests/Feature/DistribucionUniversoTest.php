<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\FichaProyecto;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Universo de órdenes de la Distribución: traer TODAS las órdenes no cerradas con actividad (saldo
 * 1420, ingreso del mes o ficha activa), y EXCLUIR las bolsas de apoyo (ADM00099, …).
 */
class DistribucionUniversoTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'activo' => true]);
    }

    private function rf(string $cod, string $cuentaMayor, float $er, string $cc, int $mes = 6, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => $cc,
            'cuenta_mayor' => $cuentaMayor, 'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function adm00099_no_aparece_ni_en_la_vista_admin(): void
    {
        // ADM00099 con saldo real en 1420: NO filtra por prefijo en la vista admin, pero debe quedar
        // fuera por ser bolsa de apoyo.
        $this->rf('ADM00099', 'Costos por aplicar', -500000, '14200105');
        $this->rf('C0001', 'Costos por aplicar', -100, '14200105'); // obra normal, para que haya lista

        $html = $this->actingAs($this->admin())
            ->get('/operativo/distribucion?mes=7&anio=2026')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="card-ADM00099"', $html);
        $this->assertStringContainsString('id="card-C0001"', $html);
    }

    #[Test]
    public function una_orden_con_ficha_activa_sin_saldo_ni_ingreso_aparece_en_cero(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'C0050', 'nombre_obra' => 'Sin actividad', 'activa' => true]);
        $this->rf('C0001', 'Costos por aplicar', -100, '14200105'); // ancla para la lista

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringContainsString('id="card-C0050"', $html); // ficha activa → aparece (en 0)
    }

    #[Test]
    public function una_orden_con_ingreso_pero_sin_saldo_1420_aparece(): void
    {
        $this->rf('C0060', 'Ingreso', 900000, '41350100', 7, 2026); // ingreso del mes, sin 1420
        $this->rf('C0001', 'Costos por aplicar', -100, '14200105');

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringContainsString('id="card-C0060"', $html);
    }

    #[Test]
    public function una_obra_cerrada_ya_trabajada_en_el_borrador_sigue_apareciendo(): void
    {
        $op = $this->operador();
        // C0080 cerrada, pero con una aplicación guardada DENTRO de un borrador.
        ProyectoCerrado::create(['codigo_proyecto' => 'C0080', 'tipo_cierre' => 'total', 'user_id' => $op->id]);
        $this->rf('C0001', 'Costos por aplicar', -100, '14200105'); // ancla para la lista

        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false,
        ]);
        AplicacionCosto::create([
            'distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => 'C0080',
            'cuenta_14' => '14200105', 'cuenta_61' => '61350105', 'categoria' => 'OTROS COSTO',
            'nombre' => 'Trabajo', 'monto_aplicar' => 5000, 'es_provision' => false,
        ]);

        // Base/nueva (sin retomar el borrador): la cerrada NO aparece.
        $base = $this->actingAs($op)
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento&nuevo=1')
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('id="card-C0080"', $base);

        // Dentro de ESE borrador: la cerrada SÍ aparece (trazabilidad de lo distribuido).
        $enBorr = $this->actingAs($op)
            ->get('/operativo/distribucion?dist='.$dist->id)
            ->assertOk()->getContent();
        $this->assertStringContainsString('id="card-C0080"', $enBorr);
    }

    #[Test]
    public function una_ficha_inactiva_sin_actividad_no_aparece(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'C0070', 'nombre_obra' => 'Inactiva', 'activa' => false]);
        $this->rf('C0001', 'Costos por aplicar', -100, '14200105');

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="card-C0070"', $html);
    }
}
