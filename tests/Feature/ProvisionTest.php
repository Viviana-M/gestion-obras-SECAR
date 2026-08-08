<?php

namespace Tests\Feature;

use App\Http\Controllers\Contable\PlanoContableController;
use App\Models\CierrePeriodo;
use App\Models\Provision;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

/**
 * Provisiones persistentes (costo en tránsito): se crean una vez (Débito cuenta 14 /
 * Crédito 26), se arrastran activas cada mes y con "Reversar" hacen el asiento inverso.
 */
class ProvisionTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre; // abre el cierre de 7/2026

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    #[Test]
    public function crear_una_provision_la_deja_activa_con_14_y_26(): void
    {
        $this->actingAs($this->operador())->post(route('operativo.provisiones.crear'), [
            'codigo_proyecto' => 'MO4501', 'cuenta_14' => '14200530', 'monto' => '1.500.000',
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'descripcion' => 'Factura pendiente',
        ])->assertRedirect();

        $this->assertDatabaseHas('provisiones', [
            'codigo_proyecto' => 'MO4501', 'cuenta_14' => '14200530', 'cuenta_26' => '26050604',
            'monto' => 1500000.00, 'mes' => 7, 'anio' => 2026, 'estado' => 'activa',
        ]);
    }

    #[Test]
    public function no_se_puede_crear_provision_con_el_cierre_cerrado(): void
    {
        CierrePeriodo::where('mes', 7)->where('anio', 2026)->update(['abierto' => false]);

        $this->actingAs($this->operador())->post(route('operativo.provisiones.crear'), [
            'codigo_proyecto' => 'MO4501', 'cuenta_14' => '14200530', 'monto' => 1000,
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Provision::count());
    }

    #[Test]
    public function la_provision_se_arrastra_activa_al_mes_siguiente(): void
    {
        // Provisión registrada en junio; la obra tiene ingreso + saldo para verse en la lista.
        Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'descripcion' => 'Servicio X', 'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Obra',
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 9000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Obra',
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -500000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);

        // En JULIO la provisión de junio sigue apareciendo como activa.
        $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertStatus(200)
            ->assertSee('14200530', false)
            ->assertSee('activa desde 06/2026', false);
    }

    #[Test]
    public function reversar_marca_la_provision_como_reversada(): void
    {
        $prov = Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);

        $this->actingAs($this->operador())
            ->post(route('operativo.provisiones.reversar', $prov->id), ['mes' => 7, 'anio' => 2026])
            ->assertRedirect();

        $this->assertDatabaseHas('provisiones', [
            'id' => $prov->id, 'estado' => 'reversada', 'reversada_mes' => 7, 'reversada_anio' => 2026,
        ]);
    }

    #[Test]
    public function el_plano_registra_14_a_26_al_crear_y_26_a_14_al_reversar(): void
    {
        $prov = Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);

        $ctrl = new PlanoContableController();

        // Mes en que se registró (junio): Débito 14 / Crédito 26.
        $jun = $ctrl->movimientosProvisiones(6, 2026, 'mantenimiento', 99);
        $deb14 = collect($jun)->first(fn ($m) => $m['cuenta'] === '14200530' && $m['debito'] > 0);
        $cre26 = collect($jun)->first(fn ($m) => $m['cuenta'] === '26050604' && $m['credito'] > 0);
        $this->assertNotNull($deb14);
        $this->assertNotNull($cre26);
        $this->assertEqualsWithDelta(800000, $deb14['debito'], 0.5);
        $this->assertEqualsWithDelta(800000, $cre26['credito'], 0.5);

        // Mes siguiente (julio) sin reversar: NO genera movimiento (ya se contabilizó).
        $this->assertCount(0, $ctrl->movimientosProvisiones(7, 2026, 'mantenimiento', 99));

        // Reversada en agosto: Débito 26 / Crédito 14 (asiento inverso).
        $prov->update(['estado' => 'reversada', 'reversada_mes' => 8, 'reversada_anio' => 2026]);
        $ago = $ctrl->movimientosProvisiones(8, 2026, 'mantenimiento', 99);
        $deb26 = collect($ago)->first(fn ($m) => $m['cuenta'] === '26050604' && $m['debito'] > 0);
        $cre14 = collect($ago)->first(fn ($m) => $m['cuenta'] === '14200530' && $m['credito'] > 0);
        $this->assertNotNull($deb26);
        $this->assertNotNull($cre14);
        $this->assertEqualsWithDelta(800000, $deb26['debito'], 0.5);
        $this->assertEqualsWithDelta(800000, $cre14['credito'], 0.5);
    }
}
