<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El encabezado de cada obra muestra: código - nombre del proyecto · cliente.
 */
class DistribucionEncabezadoTest extends TestCase
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
    public function el_encabezado_muestra_codigo_nombre_del_proyecto_y_cliente(): void
    {
        FichaProyecto::create([
            'codigo_proyecto' => 'MOB08656',
            'nombre_obra'     => 'REMODELACION TORRE NORTE',
            'cliente'         => 'JARAMILLO MORA CONSTRUCTORA SA',
        ]);
        $this->rf('MOB08656', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('MOB08656', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('MOB08656', false);
        $resp->assertSee('REMODELACION TORRE NORTE', false);            // nombre del proyecto (ficha)
        $resp->assertSee('JARAMILLO MORA CONSTRUCTORA SA', false);      // cliente
    }

    #[Test]
    public function la_tarjeta_usa_terminos_de_ingenieria_y_el_acumulado_de_cierre(): void
    {
        $this->rf('MOB08660', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('MOB08660', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        // Términos de ingeniería (sin jerga contable "cuenta 6" ni "14→6").
        $resp->assertSee('Inventario en tránsito aplicado', false);
        $resp->assertSee('Provisión (costo sin aplicar)', false);
        // La proyección muestra la fórmula completa del margen.
        $resp->assertSee('MC % = (Ingreso proyectado − Costo real) ÷ Ingreso proyectado', false);
        // El acumulado ahora es de cierre e incluye la distribución del mes.
        $resp->assertSee('INCLUYE ESTA DISTRIBUCIÓN', false);
        $resp->assertSee('Costo acumulado (con distribución)', false);
        // Ya no se muestra la jerga contable en las tarjetas.
        $resp->assertDontSee('Costo del mes (cuenta 6)', false);
        $resp->assertDontSee('Aplicado ahora (14→6)', false);
        $resp->assertDontSee('Inventario en obra (cta 14)', false);
        // El "inventario almacén" no existe: está dentro del costo por aplicar.
        $resp->assertDontSee('Inventario almacén', false);
    }

    #[Test]
    public function el_buscador_indexa_codigo_nombre_y_cliente(): void
    {
        FichaProyecto::create([
            'codigo_proyecto' => 'O-2201',
            'nombre_obra'     => 'FUNDACION VALLE DEL LILI',
            'cliente'         => 'CLINICA VALLE DEL LILI',
        ]);
        $this->rf('O-2201', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('O-2201', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=instalaciones');

        $resp->assertStatus(200);
        // Placeholder ampliado.
        $resp->assertSee('Código, proyecto o cliente', false);
        // El índice de búsqueda de la tarjeta incluye código, nombre y cliente (en minúsculas).
        $resp->assertSee('data-buscar="o-2201 fundacion valle del lili clinica valle del lili"', false);
    }

    #[Test]
    public function usa_el_nombre_de_los_movimientos_si_la_ficha_no_tiene_nombre(): void
    {
        // Sin ficha: cae al nombre_proyecto de los movimientos ("Proy MOB08657").
        $this->rf('MOB08657', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('MOB08657', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('MOB08657', false);
        $resp->assertSee('Proy MOB08657', false); // fallback
    }

    #[Test]
    public function por_defecto_filtra_el_ultimo_periodo_con_informacion(): void
    {
        // Hay cargues en abril y junio 2026; sin filtro explícito debe arrancar en junio,
        // el último período con información, no en el mes en curso.
        $this->rf('MOB08658', 'Ingreso', 5000, 4, 2026, '41350100');
        $this->rf('MOB08658', 'Ingreso', 7000, 6, 2026, '41350100');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('value="6" selected', false);      // junio
        $resp->assertSee('value="2026" selected', false);   // 2026
    }

    #[Test]
    public function respeta_el_mes_elegido_por_el_usuario(): void
    {
        $this->rf('MOB08659', 'Ingreso', 5000, 4, 2026, '41350100');
        $this->rf('MOB08659', 'Ingreso', 7000, 6, 2026, '41350100');

        // Si el usuario elige abril explícitamente, se respeta su elección.
        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=4&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('value="4" selected', false);      // abril
    }
}
