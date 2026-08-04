<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

class DistribucionInformesTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cm, float $er, int $mes, int $anio, string $cc): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cm,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    // ───────────────────────── Bug 1: resumen == Excel + incluye bolsa ─────────────────────────

    #[Test]
    public function el_resumen_incluye_lo_aplicado_y_las_asignaciones_de_bolsa(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14350105');
        $this->rf('MTO00099', 'Costos por aplicar', -1000, 6, 2026, '14200530'); // bolsa (negativo)

        $payload = [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 100]],
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 400]]],
        ];

        $resp = $this->actingAs($this->operador())
            ->post(route('operativo.distribucion.resumen'), $payload);

        $resp->assertStatus(200);
        // El resumen refleja el aplicado y la bolsa (ambos orígenes visibles).
        $resp->assertSee('Aplicado ahora', false);
        $resp->assertSee('Desde bolsa', false);
        // El botón de descarga reenvía EXACTAMENTE el mismo payload (mismos totales).
        $resp->assertSee('name="aplicar[C-700][14350105]" value="100"', false);
        $resp->assertSee('name="asignacion_bolsa[C-700]', false);
    }

    #[Test]
    public function la_descarga_del_resumen_usa_el_mismo_payload_que_la_pantalla(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14350105');

        $resp = $this->actingAs($this->operador())->post(route('operativo.distribucion.resumen'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'descargar' => 1,
            'aplicar' => ['C-700' => ['14350105' => 250]],
        ]);

        $resp->assertStatus(200);
        $this->assertStringContainsString('attachment', $resp->headers->get('content-disposition'));
    }

    // ───────────────────────── Bug 2: reenvío tras habilitar ─────────────────────────

    #[Test]
    public function reenviar_una_distribucion_habilitada_reemplaza_todas_las_lineas(): void
    {
        $this->rf('MO4501', 'Ingreso', 9000000, 7, 2026, '41350100');
        $this->rf('MO4501', 'Costos por aplicar', -5000, 6, 2026, '14350105');
        $op = $this->operador();

        // Enviar con 500.
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14350105' => 500]],
            'estado_obra' => ['MO4501' => 'cerrada'],
        ])->assertRedirect();

        $dist = Distribucion::first();
        $this->assertEqualsWithDelta(500, (float) AplicacionCosto::where('distribucion_id', $dist->id)->sum('monto_aplicar'), 0.5);

        // Contabilidad habilita la edición.
        $dist->update(['edicion_habilitada' => true]);

        // Operaciones edita el monto a 800 y REENVÍA la misma versión.
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'dist' => $dist->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14350105' => 800]],
            'estado_obra' => ['MO4501' => 'cerrada'],
        ])->assertRedirect();

        $dist->refresh();
        // La misma versión queda con el monto nuevo, sin líneas viejas mezcladas.
        $this->assertEqualsWithDelta(800, (float) AplicacionCosto::where('distribucion_id', $dist->id)->sum('monto_aplicar'), 0.5);
        $this->assertSame('enviado', $dist->estado);
        $this->assertFalse($dist->reemplazada);
    }

    #[Test]
    public function un_reenvio_en_nueva_version_marca_la_anterior_como_reemplazada(): void
    {
        $this->rf('MO4501', 'Ingreso', 9000000, 7, 2026, '41350100');
        $this->rf('MO4501', 'Costos por aplicar', -5000, 6, 2026, '14350105');
        $op = $this->operador();

        $enviar = fn ($monto) => $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14350105' => $monto]],
            'estado_obra' => ['MO4501' => 'cerrada'],
        ])->assertRedirect();

        $enviar(500);
        $v1 = Distribucion::first();
        $v1->update(['edicion_habilitada' => true]);

        // Segundo envío SIN dist → nueva versión (v2).
        $enviar(800);

        $v2 = Distribucion::where('id', '!=', $v1->id)->first();
        $this->assertNotNull($v2);
        $this->assertTrue($v1->refresh()->reemplazada);   // la anterior queda reemplazada
        $this->assertFalse($v2->reemplazada);

        // El plano solo considera la vigente.
        $vigentes = Distribucion::where('mes', 7)->where('anio', 2026)
            ->where('estado', 'enviado')->where('reemplazada', false)->get();
        $this->assertCount(1, $vigentes);
        $this->assertSame($v2->id, $vigentes->first()->id);
    }

    // ───────────────────────── Bug 3: facturado por tipo ─────────────────────────

    #[Test]
    public function el_informe_de_facturado_agrupa_por_tipo_de_obra(): void
    {
        $this->rf('MO4501', 'Ingreso', 1500000000, 7, 2026, '41350100'); // Obras
        $this->rf('C-100',  'Ingreso', 20000000, 7, 2026, '41350100');   // Contratos
        $this->rf('R-9',    'Ingreso', 5000000, 7, 2026, '41350100');    // Reparaciones

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/facturado?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Obras', false);
        $resp->assertSee('1.500.000.000', false);
        $resp->assertSee('Contratos', false);
        $resp->assertSee('20.000.000', false);
        $resp->assertSee('1.525.000.000', false); // total general
    }

    #[Test]
    public function el_informe_de_facturado_se_descarga_en_excel(): void
    {
        $this->rf('MO4501', 'Ingreso', 1500000000, 7, 2026, '41350100');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/facturado?mes=7&anio=2026&departamento=mantenimiento&descargar=1');

        $resp->assertStatus(200);
        $this->assertStringContainsString('attachment', $resp->headers->get('content-disposition'));
    }
}
