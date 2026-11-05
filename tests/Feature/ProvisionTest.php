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
 * Provisiones persistentes (costo sin factura): se crean una vez (Débito cuenta 61 /
 * Crédito 26), se arrastran activas cada mes y con "Reversar" hacen el asiento inverso
 * (Débito 26 / Crédito 61). La cuenta 61 se homologa de la 14 provisionada.
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
            'cuenta_contable' => '14200105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -500000,
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
    public function una_provision_no_enviada_se_elimina_directamente_sin_reverso(): void
    {
        // Provisión del mes abierto (7/2026) sin plano enviado → se borra directamente, no genera reverso.
        $prov = Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'mes' => 7, 'anio' => 2026, 'estado' => 'activa']);

        $this->assertFalse($prov->fueEnviada());

        $this->actingAs($this->operador())
            ->delete(route('operativo.provisiones.eliminar', $prov->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('provisiones', ['id' => $prov->id]); // borrada, no reversada
    }

    #[Test]
    public function una_provision_enviada_a_contabilidad_no_se_puede_eliminar(): void
    {
        // Hay un plano ENVIADO del período/departamento → la provisión ya fue a contabilidad: no se borra.
        \App\Models\Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'tipo' => 'otros_costos', 'estado' => 'enviado']);
        $prov = Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'mes' => 7, 'anio' => 2026, 'estado' => 'activa']);

        $this->assertTrue($prov->fueEnviada());

        $this->actingAs($this->operador())
            ->deleteJson(route('operativo.provisiones.eliminar', $prov->id))
            ->assertStatus(422);

        $this->assertDatabaseHas('provisiones', ['id' => $prov->id, 'estado' => 'activa']); // sigue viva
    }

    #[Test]
    public function la_provision_es_costo_sin_aplicar_y_baja_el_margen(): void
    {
        $svc = new \App\Services\DistribucionService();
        $o = [
            'ingreso_mes' => 1000000, 'ingreso_acum' => 1000000,
            'costo_apl_mes' => 0, 'costo_apl_acum' => 0,
            'sum_aplicar' => 200000, 'sum_prov' => 300000, 'sum_bolsa' => 0,
            'inventario_obra' => 0, 'inventario_almacen' => 0,
            'valor_oferta' => 0, 'costo_presup' => 0, 'ofertado' => null,
        ];
        $svc->calcularMargenes($o);

        $this->assertEqualsWithDelta(300000, $o['costo_sin_aplicar'], 0.5); // provisión (14→26)
        $this->assertEqualsWithDelta(200000, $o['aplicado_mes'], 0.5);      // 14→6 real (sin provisión)
        // MC del mes = 1.000.000 − (0 + 200.000 aplicado + 300.000 provisión) = 500.000
        $this->assertEqualsWithDelta(500000, $o['mc_mes_pesos'], 0.5);
    }

    #[Test]
    public function el_acumulado_de_cierre_incluye_la_distribucion_del_mes(): void
    {
        $svc = new \App\Services\DistribucionService();
        $o = [
            'ingreso_mes' => 1000000, 'ingreso_acum' => 5000000,
            'costo_apl_mes' => 100000, 'costo_apl_acum' => 3000000,
            'sum_aplicar' => 400000, 'sum_prov' => 200000, 'sum_bolsa' => 50000,
            'inventario_obra' => 0, 'inventario_almacen' => 0,
            'valor_oferta' => 0, 'costo_presup' => 0, 'ofertado' => null,
        ];
        $svc->calcularMargenes($o);

        // Costo acumulado de cierre = costo_apl_acum + (aplicar+bolsa) + provisión
        //                           = 3.000.000 + 450.000 + 200.000 = 3.650.000
        $this->assertEqualsWithDelta(3650000, $o['costo_acum_cierre'], 0.5);
        // Margen acumulado de cierre = 5.000.000 − 3.650.000 = 1.350.000
        $this->assertEqualsWithDelta(1350000, $o['margen_acum_cierre_pesos'], 0.5);
        // MC % acumulado de cierre = 1.350.000 / 5.000.000 = 27%
        $this->assertEqualsWithDelta(27.0, $o['mc_acum_cierre_pct'], 0.05);
    }

    #[Test]
    public function la_proyeccion_de_costos_suma_provisiones_y_otros_costos_distribuidos(): void
    {
        $svc = new \App\Services\DistribucionService();
        $base = [
            'ingreso_mes' => 0, 'ingreso_acum' => 0,
            'costo_apl_mes' => 0, 'costo_apl_acum' => 1000000,   // reconocido en cuenta 6
            'sum_aplicar' => 500000,                             // 14→6 del propio proyecto (ya en inventario_obra)
            'sum_prov' => 0, 'sum_bolsa' => 0,
            'inventario_obra' => 200000, 'inventario_almacen' => 0,
            'valor_oferta' => 5000000, 'costo_presup' => 2000000, 'ofertado' => null,
        ];

        // Sin provisión ni otros costos distribuidos: costo total = reconocido + inventario propio.
        // sum_aplicar NO se suma (ya está representado en inventario_obra).
        $o = $base;
        $svc->calcularMargenes($o);
        $this->assertEqualsWithDelta(1200000, $o['pr_costo_total'], 0.5);

        // Con provisión (150.000) y otros costos distribuidos desde bolsa de área (300.000):
        // el total de la proyección sube por AMBOS conceptos.
        $o = array_merge($base, ['sum_prov' => 150000, 'sum_bolsa' => 300000]);
        $svc->calcularMargenes($o);
        $this->assertEqualsWithDelta(1650000, $o['pr_costo_total'], 0.5); // 1.200.000 + 300.000 + 150.000

        // MC % proyectado baja al subir el costo: (5.000.000 − 1.650.000) / 5.000.000 = 67%.
        $this->assertEqualsWithDelta(67.0, $o['pr_mc_proy'], 0.05);
        // Avance de ejecución también sube: 1.650.000 / 2.000.000 = 82,5%.
        $this->assertEqualsWithDelta(82.5, $o['pr_avance_ejec'], 0.05);
    }

    #[Test]
    public function el_plano_registra_61_a_26_al_crear_y_26_a_61_al_reversar(): void
    {
        // La provisión es un COSTO sin factura: el costo se reconoce en la cuenta 61
        // (homologada de la 14) y la contrapartida queda en la cuenta 26.
        \App\Models\Homologacion::create(['cuenta_14' => '14200530', 'cuenta_61' => '61350105',
            'nombre' => 'Servicio', 'estructura' => 'MAT', 'vigente_desde' => 200001]);

        $prov = Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);

        $ctrl = new PlanoContableController();

        // Mes en que se registró (junio): Débito 61 (costo, con centro de costos) / Crédito 26.
        $jun = $ctrl->movimientosProvisiones(6, 2026, 'mantenimiento', 99);
        $deb61 = collect($jun)->first(fn ($m) => $m['cuenta'] === '61350105' && $m['debito'] > 0);
        $cre26 = collect($jun)->first(fn ($m) => $m['cuenta'] === '26050604' && $m['credito'] > 0);
        $this->assertNotNull($deb61, 'Debe debitar el costo en la cuenta 61 homologada.');
        $this->assertNotNull($cre26, 'Debe acreditar la provisión en la cuenta 26.');
        $this->assertEqualsWithDelta(800000, $deb61['debito'], 0.5);
        $this->assertEqualsWithDelta(800000, $cre26['credito'], 0.5);
        $this->assertSame('30020105', $deb61['centro_costos'], 'El costo (61) lleva el centro de costos del área.');
        // No debe quedar ninguna línea en la cuenta 14: la provisión ya no pasa por inventario.
        $this->assertNull(collect($jun)->first(fn ($m) => $m['cuenta'] === '14200530'));

        // Mes siguiente (julio) sin reversar: NO genera movimiento (ya se contabilizó).
        $this->assertCount(0, $ctrl->movimientosProvisiones(7, 2026, 'mantenimiento', 99));

        // Reversada en agosto: Débito 26 / Crédito 61 (asiento inverso: se revierte el 26).
        $prov->update(['estado' => 'reversada', 'reversada_mes' => 8, 'reversada_anio' => 2026]);
        $ago = $ctrl->movimientosProvisiones(8, 2026, 'mantenimiento', 99);
        $deb26 = collect($ago)->first(fn ($m) => $m['cuenta'] === '26050604' && $m['debito'] > 0);
        $cre61 = collect($ago)->first(fn ($m) => $m['cuenta'] === '61350105' && $m['credito'] > 0);
        $this->assertNotNull($deb26, 'Al reversar debe debitar la cuenta 26.');
        $this->assertNotNull($cre61, 'Al reversar debe acreditar la cuenta 61 (revierte el costo).');
        $this->assertEqualsWithDelta(800000, $deb26['debito'], 0.5);
        $this->assertEqualsWithDelta(800000, $cre61['credito'], 0.5);
    }

    #[Test]
    public function la_provision_sin_homologacion_no_se_lleva_a_la_cuenta_6(): void
    {
        // Sin homologación 14→61 no se puede llevar a la cuenta 6: la provisión se omite del plano
        // (igual que una línea SIN HOMOLOGAR), sin dejar un 26 descuadrado.
        Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14209999', 'cuenta_26' => '26050604', 'monto' => 500000,
            'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);

        $ctrl = new PlanoContableController();
        $this->assertCount(0, $ctrl->movimientosProvisiones(6, 2026, 'mantenimiento', 99));
    }
}
