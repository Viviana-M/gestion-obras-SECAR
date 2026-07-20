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
}
