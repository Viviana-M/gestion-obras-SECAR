<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vista "Seguridad social por persona": maestro-detalle con total de Aporte empresa
 * por persona, desglose por concepto PILA, KPIs y descarga a Excel.
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

    private function ap(string $ced, string $nombre, string $un, string $concepto, float $empresa): void
    {
        AutoliquidacionAporte::create([
            'cedula' => $ced, 'razon_social' => $nombre, 'un_codigo' => $un,
            'concepto_pila' => $concepto, 'aporte_empresa' => $empresa,
            'aporte_empleado' => 999, 'real_descontado' => 999, // deben ignorarse en el total
            'mes' => 4, 'anio' => 2026,
        ]);
    }

    private function sembrar(): void
    {
        // GOMEZ: EPS 30.000 + ARL 12.000 = 42.000 (ADM00099)
        $this->ap('111', 'GOMEZ ANA', 'ADM00099', 'EPS', 30000);
        $this->ap('111', 'GOMEZ ANA', 'ADM00099', 'ARL', 12000);
        // RUIZ: AFP 25.000 (OB4501)
        $this->ap('222', 'RUIZ LUIS', 'OB4501', 'Pensión', 25000);
        // PEÑA: Caja 9.000 (OB4501)
        $this->ap('333', 'PEÑA JOSE', 'OB4501', 'Caja', 9000);
    }

    #[Test]
    public function agrupa_por_persona_ordena_desc_y_calcula_kpis(): void
    {
        $this->sembrar();

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.autoliquidacion.personas', ['mes' => 4, 'anio' => 2026]));
        $resp->assertStatus(200);
        $resp->assertSee('Seguridad social por persona', false);

        $personas = $resp->viewData('personas');
        // Orden de mayor a menor por total.
        $this->assertSame(['111', '222', '333'], $personas->pluck('cedula')->all());

        $p111 = $personas->firstWhere('cedula', '111');
        // Total = solo Aporte empresa (ignora aporte_empleado y real_descontado).
        $this->assertEqualsWithDelta(42000, $p111['total'], 0.5);
        $this->assertSame('ADM00099', $p111['un']);
        $desglose = collect($p111['conceptos'])->pluck('aporte', 'concepto');
        $this->assertEqualsWithDelta(30000, $desglose['EPS'], 0.5);
        $this->assertEqualsWithDelta(12000, $desglose['ARL'], 0.5);

        // KPIs: total cuadra con la suma de la columna Aporte empresa, y promedio.
        $sumaColumna = (float) AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->sum('aporte_empresa');
        $this->assertEqualsWithDelta(76000, $sumaColumna, 0.5);
        $this->assertEqualsWithDelta($sumaColumna, $resp->viewData('total'), 0.5);
        $this->assertSame(3, $resp->viewData('numPersonas'));
        $this->assertEqualsWithDelta(76000 / 3, $resp->viewData('promedio'), 0.5);
    }

    #[Test]
    public function se_filtra_por_unidad_de_negocio(): void
    {
        $this->sembrar();

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.autoliquidacion.personas', ['mes' => 4, 'anio' => 2026, 'un' => 'OB4501']));
        $resp->assertStatus(200);

        $personas = $resp->viewData('personas');
        $this->assertSame(['222', '333'], $personas->pluck('cedula')->all());
        $this->assertEqualsWithDelta(34000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function descarga_el_excel_con_persona_un_total_y_conceptos(): void
    {
        Excel::fake();
        $this->sembrar();

        $this->actingAs($this->contable())
            ->get(route('contable.autoliquidacion.personas.excel', ['mes' => 4, 'anio' => 2026]))
            ->assertOk();

        Excel::assertDownloaded('Seguridad_social_por_persona_Abril_2026.xlsx', function ($export) {
            $plano = json_encode($export->array());
            return str_contains($plano, 'GOMEZ ANA')
                && str_contains($plano, 'Total Aporte empresa')
                && str_contains($plano, 'EPS')
                && str_contains($plano, 'TOTAL');
        });
    }

    #[Test]
    public function un_usuario_sin_permiso_no_puede_ver(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true,
            'permisos_modulos' => ['comercial' => 'ver']]);

        $this->actingAs($sin)
            ->get(route('contable.autoliquidacion.personas', ['mes' => 4, 'anio' => 2026]))
            ->assertForbidden();
    }

    #[Test]
    public function el_admin_puede_ver(): void
    {
        $this->sembrar();
        $admin = User::factory()->create(['rol' => 'admin', 'activo' => true]);

        $this->actingAs($admin)
            ->get(route('contable.autoliquidacion.personas', ['mes' => 4, 'anio' => 2026]))
            ->assertStatus(200);
    }
}
