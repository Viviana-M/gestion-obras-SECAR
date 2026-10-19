<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consulta de gerencia (solo lectura): lista de obras con responsable (de la ficha) y margen real
 * (MC%), con esquema de costos por categoría (PART.%), filtros y Excel.
 */
class ConsultaProyectosGerenciaTest extends TestCase
{
    use RefreshDatabase;

    private function gerente(): User
    {
        return User::factory()->create([
            'rol' => 'gerente', 'activo' => true, 'permisos_modulos' => ['gestion_financiera' => 'ver'],
        ]);
    }

    private function homolog(string $c14, string $estructura): void
    {
        Homologacion::create(['cuenta_14' => $c14, 'cuenta_61' => '61'.substr($c14, 2), 'nombre' => 'H',
            'estructura' => $estructura, 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1]);
    }

    private function ingreso(string $cod, float $monto, int $mes = 7, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod, 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => $monto, 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /** Costo reconocido (cuenta 61): la descripción termina en la cuenta 14 para triangular su estructura. */
    private function costo(string $cod, string $c14, float $monto, int $mes = 7, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod, 'cuenta_contable' => '61'.substr($c14, 2),
            'descripcion' => 'Costo '.$c14, 'cuenta_mayor' => 'Costos aplicados', 'estado_er' => -abs($monto),
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function seedEsc(): void
    {
        $this->homolog('14200105', 'EQU-MAT-SUM');   // Equipos y materiales
        $this->homolog('14200530', 'MOI');           // M.O. interna

        // C-700 (mantenimiento, contrato): ingreso 1.000.000, costo 300k materiales + 200k MOI → MC 500k (50%).
        $this->ingreso('C-700', 1000000);
        $this->costo('C-700', '14200105', 300000);
        $this->costo('C-700', '14200530', 200000);
        FichaProyecto::create(['codigo_proyecto' => 'C-700', 'nombre_obra' => 'Obra Contrato', 'cliente' => 'Clínica X',
            'responsable_obra' => 'Ana Ruiz', 'responsable_comercial' => 'Luis Paz', 'margen_ofertado' => 45]);

        // O0001 (instalaciones, obras): ingreso 500k, costo 400k materiales → MC 100k (20%).
        $this->ingreso('O0001', 500000);
        $this->costo('O0001', '14200105', 400000);
        FichaProyecto::create(['codigo_proyecto' => 'O0001', 'nombre_obra' => 'Obra Inst', 'cliente' => 'Hotel Y',
            'responsable_obra' => 'Beto Gómez', 'margen_ofertado' => 25]);
    }

    #[Test]
    public function lista_las_obras_con_responsable_margen_y_esquema(): void
    {
        $this->seedEsc();

        $resp = $this->actingAs($this->gerente())
            ->get(route('financiero.proyectos', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $obras = collect($resp->viewData('obras'))->keyBy('codigo');

        // C-700: esquema correcto y MC% = 50.
        $c = $obras['C-700'];
        $this->assertSame('Ana Ruiz', $c['responsable']);
        $this->assertEqualsWithDelta(1000000, $c['ingreso'], 0.5);
        $this->assertEqualsWithDelta(300000, $c['cat']['EQU-MAT-SUM'], 0.5);
        $this->assertEqualsWithDelta(200000, $c['cat']['MOI'], 0.5);
        $this->assertEqualsWithDelta(500000, $c['total_costo'], 0.5);
        $this->assertEqualsWithDelta(500000, $c['mc'], 0.5);
        $this->assertEqualsWithDelta(50, $c['mc_pct'], 0.1);
        $this->assertEqualsWithDelta(45, $c['ofertado'], 0.1);   // ofertado desde la ficha

        // Se ve en pantalla el responsable y las etiquetas del esquema.
        $resp->assertSee('Ana Ruiz', false);
        $resp->assertSee('M.O. interna', false);
        $resp->assertSee('Equipos y materiales', false);
    }

    #[Test]
    public function ordena_peor_margen_primero(): void
    {
        $this->seedEsc();

        $obras = collect($this->actingAs($this->gerente())
            ->get(route('financiero.proyectos', ['mes' => 7, 'anio' => 2026]))
            ->viewData('obras'))->pluck('codigo')->all();

        // O0001 (20%) antes que C-700 (50%).
        $this->assertSame(['O0001', 'C-700'], $obras);
    }

    #[Test]
    public function filtra_por_departamento_tipo_y_responsable(): void
    {
        $this->seedEsc();
        $g = $this->gerente();

        // Departamento = instalaciones → solo O0001.
        $dep = collect($this->actingAs($g)->get(route('financiero.proyectos', ['mes'=>7,'anio'=>2026,'departamento'=>'instalaciones']))->viewData('obras'))->pluck('codigo')->all();
        $this->assertSame(['O0001'], $dep);

        // Tipo = contrato → solo C-700.
        $tipo = collect($this->actingAs($g)->get(route('financiero.proyectos', ['mes'=>7,'anio'=>2026,'tipo'=>'contrato']))->viewData('obras'))->pluck('codigo')->all();
        $this->assertSame(['C-700'], $tipo);

        // Responsable = Beto Gómez → solo O0001.
        $resp = collect($this->actingAs($g)->get(route('financiero.proyectos', ['mes'=>7,'anio'=>2026,'responsable'=>'Beto Gómez']))->viewData('obras'))->pluck('codigo')->all();
        $this->assertSame(['O0001'], $resp);
    }

    #[Test]
    public function el_buscador_filtra_por_codigo_obra_cliente_o_responsable(): void
    {
        $this->seedEsc();

        $obras = collect($this->actingAs($this->gerente())
            ->get(route('financiero.proyectos', ['mes'=>7,'anio'=>2026,'q'=>'Clínica X']))
            ->viewData('obras'))->pluck('codigo')->all();
        $this->assertSame(['C-700'], $obras);
    }

    #[Test]
    public function totales_cuadran(): void
    {
        $this->seedEsc();

        $t = $this->actingAs($this->gerente())
            ->get(route('financiero.proyectos', ['mes' => 7, 'anio' => 2026]))->viewData('totales');

        $this->assertEqualsWithDelta(1500000, $t['ingreso'], 0.5);      // 1.000.000 + 500.000
        $this->assertEqualsWithDelta(900000, $t['total_costo'], 0.5);   // 500.000 + 400.000
        $this->assertEqualsWithDelta(600000, $t['mc'], 0.5);
        $this->assertEqualsWithDelta(40, $t['mc_pct'], 0.1);            // 600k / 1.5M
    }

    #[Test]
    public function descarga_el_excel_con_el_esquema(): void
    {
        Excel::fake();
        $this->seedEsc();

        $this->actingAs($this->gerente())
            ->get(route('financiero.proyectos.excel', ['mes' => 7, 'anio' => 2026]))->assertOk();

        Excel::assertDownloaded('Proyectos_gerencia_todos_202607.xlsx', function ($export) {
            $plano = json_encode($export->array(), JSON_UNESCAPED_UNICODE);
            return str_contains($plano, 'Responsable')
                && str_contains($plano, 'M.O. interna')
                && str_contains($plano, 'Ana Ruiz')
                && str_contains($plano, 'TOTAL');
        });
    }

    #[Test]
    public function requiere_el_modulo_de_gestion_financiera(): void
    {
        $sin = User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver'],
        ]);
        $this->actingAs($sin)->get(route('financiero.proyectos'))->assertForbidden();
    }
}
