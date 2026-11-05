<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consulta de obras (solo lectura) para ingenieros de operación: réplica EN VIVO de las tarjetas de
 * proyecto de la distribución, reutilizando el mismo componente de tarjeta en modo readonly.
 */
class ConsultaObrasTest extends TestCase
{
    use RefreshDatabase;

    /** Ingeniero: ve la consulta pero NO distribuye (operación: solo ver). */
    private function ingeniero(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'ver'],
        ]);
    }

    /** Distribuidor: puede editar la distribución (operación: editar). */
    private function distribuidor(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function seedObra(): void
    {
        // Obra de mantenimiento con ingreso y un residuo de saldo en 1420 → aparece como tarjeta.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-777', 'nombre_proyecto' => 'Obra demo', 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => 1000000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-777', 'nombre_proyecto' => 'Obra demo', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -100000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
    }

    #[Test]
    public function el_ingeniero_ve_la_consulta_pero_no_la_distribucion(): void
    {
        $ing = $this->ingeniero();

        // La consulta (solo lectura) sí: requiere solo operación:ver.
        $this->actingAs($ing)->get(route('operativo.consulta-obras', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk();

        // La distribución editable NO: exige operación:editar.
        $this->actingAs($ing)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertForbidden();
    }

    #[Test]
    public function el_distribuidor_sigue_entrando_a_la_distribucion(): void
    {
        $this->actingAs($this->distribuidor())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk();
    }

    #[Test]
    public function la_consulta_muestra_la_tarjeta_completa_resumen_y_proyeccion(): void
    {
        $this->seedObra();

        $html = $this->actingAs($this->ingeniero())
            ->get(route('operativo.consulta-obras', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk()->getContent();

        // La tarjeta del proyecto está presente…
        $this->assertStringContainsString('id="card-C-777"', $html);
        // …con el resumen del mes, el acumulado y la proyección de rentabilidad.
        $this->assertStringContainsString('Costos por llevar a este proyecto', $html);
        $this->assertStringContainsString('RENTABILIDAD DEL MES', $html);
        $this->assertStringContainsString('ACUMULADO A', $html);
        $this->assertStringContainsString('PROYECCIÓN DE RENTABILIDAD', $html);
        $this->assertStringContainsString('id="mcpct-C-777"', $html);     // badge % del mes
        $this->assertStringContainsString('id="prmcp-C-777"', $html);     // proyectado (real)
    }

    #[Test]
    public function la_consulta_es_solo_lectura_sin_acciones_de_distribucion(): void
    {
        $this->seedObra();

        $html = $this->actingAs($this->ingeniero())
            ->get(route('operativo.consulta-obras', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk()->getContent();

        // Sin botón "Distribuir costos", sin control editable de estado, sin guardado, sin textarea.
        $this->assertStringNotContainsString('Distribuir costos', $html);
        $this->assertStringNotContainsString('estadoClick(', $html);
        $this->assertStringNotContainsString('name="estado_obra[', $html);
        $this->assertStringNotContainsString('Guardar distribución', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('name="observacion[', $html);
        // En la zona de tarjetas no hay NINGÚN input (el único input de la página es el buscador).
        $this->assertStringNotContainsString('<input', $this->soloTarjetas($html));

        // El estado se ve como etiqueta de solo lectura.
        $this->assertStringContainsString('Estado del cierre', $html);
    }

    /** Recorta el buscador/filtros para que la aserción de "sin inputs" mire solo las tarjetas. */
    private function soloTarjetas(string $html): string
    {
        $pos = strpos($html, 'id="card-');

        return $pos === false ? $html : substr($html, $pos);
    }

    #[Test]
    public function la_consulta_usa_la_misma_fuente_de_datos_que_la_distribucion(): void
    {
        $this->seedObra();
        $ing = $this->ingeniero();

        $consulta = $this->actingAs($ing)
            ->get(route('operativo.consulta-obras', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk();

        $dist = $this->actingAs($this->distribuidor())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk();

        // Misma colección de obras (mismos códigos y mismos valores calculados) → réplica en vivo.
        $this->assertSame(
            array_keys($dist->viewData('obras')),
            array_keys($consulta->viewData('obras'))
        );
        $this->assertSame(
            $dist->viewData('obras')['C-777']['mc_mes_pct'],
            $consulta->viewData('obras')['C-777']['mc_mes_pct']
        );
        $this->assertTrue($consulta->viewData('readonly'));
    }
}
