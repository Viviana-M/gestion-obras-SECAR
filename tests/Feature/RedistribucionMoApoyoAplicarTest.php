<?php

namespace Tests\Feature;

use App\Models\Homologacion;
use App\Models\ManoObraDirecta;
use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aplicar en la cuenta 14 del sistema el plano de reverso de MO de apoyo (cierre × autoliquidación):
 * CR 14 con el tercero del empleado + DB 61 homologada, origen='reverso_apoyo', idempotente, y que
 * el borrado por mes de BIABLE no lo toque.
 */
class RedistribucionMoApoyoAplicarTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    private function base(): void
    {
        Homologacion::create(['cuenta_14' => '14200530', 'cuenta_61' => '61350105', 'nombre' => 'MO apoyo',
            'estructura' => 'MOI', 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1]);
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'Juan Perez', 'activo' => true]);
        // Salario del empleado 111 en la bolsa INS00099 (origen BIABLE), tercero = el empleado.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'INS00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '111', 'razon_social' => 'Juan Perez',
            'estado_er' => -500000, 'valor_debito' => 500000, 'valor_credito' => 0, 'mes' => 8, 'anio' => 2026, 'origen' => 'biable',
        ]);
    }

    #[Test]
    public function aplicar_escribe_la_14_con_el_tercero_del_empleado_y_cuadra_la_bolsa(): void
    {
        $this->base();

        $this->actingAs($this->contable())->post(route('contable.redistribucion-mo.aplicar'), ['mes' => 8, 'anio' => 2026])
            ->assertRedirect()->assertSessionHas('success');

        // Plano propio aplicado (reverso_apoyo), por corte del período.
        $plano = PlanoAplicado::where('tipo', 'reverso_apoyo')->sole();
        $this->assertSame(8, (int) $plano->corte_mes);
        $this->assertSame(2026, (int) $plano->corte_anio);

        // CR a la cuenta 14 con el tercero del EMPLEADO (no SECAR) y origen propio.
        $cr = RegistroFinanciero::where('origen', 'reverso_apoyo')->where('cuenta_contable', '14200530')->sole();
        $this->assertSame('INS00099', $cr->codigo_proyecto);
        $this->assertSame('111', $cr->tercero_dcto);
        $this->assertEqualsWithDelta(500000, $cr->valor_credito, 1);
        $this->assertEqualsWithDelta(500000, $cr->estado_er, 1); // crédito a la 14 → positivo, reduce el pendiente

        // DB a la cuenta 61 homologada en la misma UN.
        $db = RegistroFinanciero::where('origen', 'reverso_apoyo')->where('cuenta_contable', '61350105')->sole();
        $this->assertSame('INS00099', $db->codigo_proyecto);
        $this->assertEqualsWithDelta(500000, $db->valor_debito, 1);

        // La bolsa queda en 0 (−500k del costo + 500k del reverso).
        $neto = RegistroFinanciero::where('codigo_proyecto', 'INS00099')->where('cuenta_contable', '14200530')->sum('estado_er');
        $this->assertEqualsWithDelta(0, $neto, 1);
    }

    #[Test]
    public function reaplicar_reemplaza_la_corrida_no_duplica(): void
    {
        $this->base();
        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.redistribucion-mo.aplicar'), ['mes' => 8, 'anio' => 2026])->assertRedirect();
        $this->actingAs($c)->post(route('contable.redistribucion-mo.aplicar'), ['mes' => 8, 'anio' => 2026])->assertRedirect();

        $this->assertSame(1, PlanoAplicado::where('tipo', 'reverso_apoyo')->count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'reverso_apoyo')->count()); // CR 14 + DB 61
    }

    #[Test]
    public function el_borrado_por_mes_de_biable_no_toca_el_reverso_apoyo(): void
    {
        $this->base();
        $this->actingAs($this->contable())->post(route('contable.redistribucion-mo.aplicar'), ['mes' => 8, 'anio' => 2026])->assertRedirect();

        // Blindaje: el reproceso de BIABLE borra SOLO origen='biable'.
        RegistroFinanciero::where('mes', 8)->where('anio', 2026)->where('origen', 'biable')->delete();

        $this->assertSame(0, RegistroFinanciero::where('origen', 'biable')->count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'reverso_apoyo')->count()); // sobrevive
    }
}
