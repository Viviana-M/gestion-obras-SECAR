<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\ManoObraDirecta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pestaña "Seguridad social por persona" dentro del módulo de Autoliquidación:
 * lista por PERSONA (empleado), no por el tercero/fondo, con desglose por concepto PILA.
 */
class SeguridadSocialPersonaTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'ver'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true,
            'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    /** Fila PILA: el TERCERO es el fondo; el EMPLEADO es la persona. */
    private function ap(string $tercero, string $fondo, string $empleado, string $nombreEmpl, string $un, string $concepto, float $empresa): void
    {
        AutoliquidacionAporte::create([
            'cedula' => $tercero, 'razon_social' => $fondo,          // tercero = fondo (EPS/AFP/…)
            'empleado' => $empleado, 'empleado_nombre' => $nombreEmpl, // persona
            'un_codigo' => $un, 'concepto_pila' => $concepto,
            'aporte_empresa' => $empresa, 'aporte_empleado' => 999, 'real_descontado' => 999,
            'mes' => 4, 'anio' => 2026,
        ]);
    }

    private function sembrar(): void
    {
        // La vista por persona solo muestra a las de Mano de Obra Directa.
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'JUAN PEREZ', 'activo' => true]);
        ManoObraDirecta::create(['cedula' => '222', 'nombre' => 'ANA GOMEZ', 'activo' => true]);
        ManoObraDirecta::create(['cedula' => '333', 'nombre' => 'LUIS RUIZ', 'activo' => true]);

        // JUAN PEREZ (111): EPS (Nueva EPS) 30.000 + ARL (Colmena) 12.000 = 42.000, UN ADM00099
        $this->ap('800100', 'NUEVA EPS', '111', 'JUAN PEREZ', 'ADM00099', 'EPS', 30000);
        $this->ap('800226175', 'COLMENA ARP', '111', 'JUAN PEREZ', 'ADM00099', 'ARL', 12000);
        // ANA GOMEZ (222): Pensión (Porvenir) 25.000, UN OB4501
        $this->ap('800224808', 'PORVENIR', '222', 'ANA GOMEZ', 'OB4501', 'Pensión', 25000);
        // LUIS RUIZ (333): Caja (Comfandi) 9.000, UN OB4501
        $this->ap('890303208', 'COMFANDI', '333', 'LUIS RUIZ', 'OB4501', 'Caja', 9000);
    }

    private function ver(User $u, array $params = [])
    {
        return $this->actingAs($u)->get(route('contable.autoliquidacion.index',
            array_merge(['mes' => 4, 'anio' => 2026, 'tab' => 'personas'], $params)));
    }

    #[Test]
    public function lista_por_persona_no_por_el_fondo(): void
    {
        $this->sembrar();
        $resp = $this->ver($this->contable());
        $resp->assertStatus(200);

        $personas = $resp->viewData('personas');
        // Agrupa por EMPLEADO (cédula 111/222/333), NO por el tercero/fondo.
        $this->assertSame(['111', '222', '333'], $personas->pluck('cedula')->all());
        $nombres = $personas->pluck('nombre')->all();
        $this->assertSame(['JUAN PEREZ', 'ANA GOMEZ', 'LUIS RUIZ'], $nombres);
        // Los nombres de fondos NO aparecen como "persona".
        $this->assertNotContains('COLMENA ARP', $nombres);
        $this->assertNotContains('NUEVA EPS', $nombres);

        // Total por persona y desglose por concepto.
        $p111 = $personas->firstWhere('cedula', '111');
        $this->assertEqualsWithDelta(42000, $p111['total'], 0.5);
        $desglose = collect($p111['conceptos'])->pluck('aporte', 'concepto');
        $this->assertEqualsWithDelta(30000, $desglose['EPS'], 0.5);
        $this->assertEqualsWithDelta(12000, $desglose['ARL'], 0.5);
    }

    #[Test]
    public function agrupa_por_nombre_cuando_falta_la_cedula_del_empleado(): void
    {
        // "Empleado" (cédula) vacío pero "Nombre del empl" presente → agrupa por el nombre,
        // NO por el tercero/fondo. Debe estar en el maestro (cruce por nombre).
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'JUAN PEREZ', 'activo' => true]);
        AutoliquidacionAporte::create(['cedula' => '800100', 'razon_social' => 'NUEVA EPS',
            'empleado' => '', 'empleado_nombre' => 'JUAN PEREZ', 'un_codigo' => 'ADM00099',
            'concepto_pila' => 'EPS', 'aporte_empresa' => 30000, 'mes' => 4, 'anio' => 2026]);
        AutoliquidacionAporte::create(['cedula' => '800226175', 'razon_social' => 'COLMENA ARP',
            'empleado' => '', 'empleado_nombre' => 'JUAN PEREZ', 'un_codigo' => 'ADM00099',
            'concepto_pila' => 'ARL', 'aporte_empresa' => 12000, 'mes' => 4, 'anio' => 2026]);

        $resp = $this->ver($this->contable());
        $personas = $resp->viewData('personas');

        $this->assertCount(1, $personas);
        $this->assertSame('JUAN PEREZ', $personas->first()['nombre']);
        $this->assertEqualsWithDelta(42000, $personas->first()['total'], 0.5);
        $this->assertNotContains('NUEVA EPS', $personas->pluck('nombre')->all());
    }

    #[Test]
    public function calcula_los_kpis_y_cuadra_con_la_columna(): void
    {
        $this->sembrar();
        $resp = $this->ver($this->contable());

        $suma = (float) AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->sum('aporte_empresa');
        $this->assertEqualsWithDelta(76000, $suma, 0.5);
        $this->assertEqualsWithDelta($suma, $resp->viewData('total'), 0.5);
        $this->assertSame(3, $resp->viewData('numPersonas'));            // 3 personas (no fondos)
        $this->assertEqualsWithDelta(76000 / 3, $resp->viewData('promedio'), 0.5);
    }

    #[Test]
    public function se_filtra_por_unidad_de_negocio(): void
    {
        $this->sembrar();
        $resp = $this->ver($this->contable(), ['un' => 'OB4501']);

        $personas = $resp->viewData('personas');
        $this->assertSame(['222', '333'], $personas->pluck('cedula')->all());
        $this->assertEqualsWithDelta(34000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function no_muestra_personas_ni_conceptos_en_cero(): void
    {
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'JUAN PEREZ', 'activo' => true]);
        $this->ap('800100', 'NUEVA EPS', '111', 'JUAN PEREZ', 'ADM00099', 'EPS', 30000);
        $this->ap('800100', 'FSP', '111', 'JUAN PEREZ', 'ADM00099', 'FSP', 0);       // concepto en 0
        $this->ap('800100', 'NUEVA EPS', '444', 'SIN COSTO', 'ADM00099', 'EPS', 0);  // persona total 0

        $resp = $this->ver($this->contable());
        $personas = $resp->viewData('personas');
        $this->assertSame(['111'], $personas->pluck('cedula')->all());
        $conceptos = collect($personas->firstWhere('cedula', '111')['conceptos'])->pluck('concepto')->all();
        $this->assertContains('EPS', $conceptos);
        $this->assertNotContains('FSP', $conceptos);
    }

    #[Test]
    public function descarga_el_excel_por_persona(): void
    {
        Excel::fake();
        $this->sembrar();

        $this->actingAs($this->contable())
            ->get(route('contable.autoliquidacion.personas.excel', ['mes' => 4, 'anio' => 2026]))
            ->assertOk();

        Excel::assertDownloaded('Seguridad_social_por_persona_Abril_2026.xlsx', function ($export) {
            $plano = json_encode($export->array());
            return str_contains($plano, 'JUAN PEREZ')
                && str_contains($plano, 'Total Aporte empresa')
                && str_contains($plano, 'EPS')
                && str_contains($plano, 'TOTAL');
        });
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_ver(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true,
            'permisos_modulos' => ['comercial' => 'ver']]);

        $this->actingAs($sin)
            ->get(route('contable.autoliquidacion.index', ['mes' => 4, 'anio' => 2026, 'tab' => 'personas']))
            ->assertForbidden();
    }
}
