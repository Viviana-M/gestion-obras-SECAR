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
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $this->rf('MTO00099', 'Costos por aplicar', -1000, 6, 2026, '14200530'); // bolsa (negativo)

        $payload = [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 100]],
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ];

        $resp = $this->actingAs($this->operador())
            ->post(route('operativo.distribucion.resumen'), $payload);

        $resp->assertStatus(200);
        // Tabla vertical simplificada: Tipo · Ingreso · Costos · Rentabilidad %.
        $resp->assertSee('RENTABILIDAD %', false);
        $resp->assertSee('Contratos', false); // C-700 es tipo Contrato
        // Los "Costos" del tipo suman lo aplicado (100) + la bolsa (400) = 500 (ambos orígenes incluidos).
        $resp->assertSee('$500', false);
        $resp->assertSee('$5.000.000', false); // ingreso del mes
        // El botón de descarga reenvía EXACTAMENTE el mismo payload (mismos totales).
        $resp->assertSee('name="aplicar[C-700][14200105]" value="100"', false);
        $resp->assertSee('name="asignacion_bolsa[C-700]', false);
    }

    #[Test]
    public function la_descarga_del_resumen_usa_el_mismo_payload_que_la_pantalla(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');

        $resp = $this->actingAs($this->operador())->post(route('operativo.distribucion.resumen'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'descargar' => 1,
            'aplicar' => ['C-700' => ['14200105' => 250]],
        ]);

        $resp->assertStatus(200);
        $this->assertStringContainsString('attachment', $resp->headers->get('content-disposition'));
    }

    // ───────────────────────── Bug 2: reenvío tras habilitar ─────────────────────────

    #[Test]
    public function reenviar_una_distribucion_habilitada_reemplaza_todas_las_lineas(): void
    {
        $this->rf('MO4501', 'Ingreso', 9000000, 7, 2026, '41350100');
        $this->rf('MO4501', 'Costos por aplicar', -5000, 6, 2026, '14200105');
        $op = $this->operador();

        // Enviar con 500.
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14200105' => 500]],
            'estado_obra' => ['MO4501' => 'cerrada'],
        ])->assertRedirect();

        $dist = Distribucion::first();
        $this->assertEqualsWithDelta(500, (float) AplicacionCosto::where('distribucion_id', $dist->id)->sum('monto_aplicar'), 0.5);

        // Contabilidad habilita la edición.
        $dist->update(['edicion_habilitada' => true]);

        // Operaciones edita el monto a 800 y REENVÍA la misma versión.
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'dist' => $dist->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14200105' => 800]],
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
        $this->rf('MO4501', 'Costos por aplicar', -5000, 6, 2026, '14200105');
        $op = $this->operador();

        $enviar = fn ($monto) => $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14200105' => $monto]],
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

    // ───────────────────────── Bug: el borrador no se retomaba al volver a entrar ─────────────────────────

    /** Guarda un borrador (sin enviar) aplicando $monto a C-700. */
    private function guardarBorrador(User $op, float $monto)
    {
        return $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => $monto]],
            'estado_obra' => ['C-700' => 'parcial'],
        ])->assertRedirect();
    }

    #[Test]
    public function al_volver_a_entrar_sin_dist_se_retoma_el_borrador_guardado(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $op = $this->operador();

        $this->guardarBorrador($op, 100);
        $draft = Distribucion::where('estado', 'borrador')->firstOrFail();

        // Entrar por el menú (SIN ?dist) retoma ese mismo borrador, no abre una base nueva.
        $resp = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertOk();
        $this->assertSame($draft->id, $resp->viewData('distId'));
    }

    #[Test]
    public function guardar_sin_dist_no_duplica_el_borrador(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $op = $this->operador();

        // Varios guardados SIN ?dist deben reutilizar el mismo borrador (no acumular duplicados).
        $this->guardarBorrador($op, 100);
        $this->guardarBorrador($op, 200);
        $this->guardarBorrador($op, 300);

        $this->assertSame(1, Distribucion::where('mes', 7)->where('anio', 2026)
            ->where('departamento', 'mantenimiento')->where('tipo', 'obras')->count());
    }

    #[Test]
    public function empezar_un_borrador_nuevo_no_retoma_el_existente(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $op = $this->operador();

        $this->guardarBorrador($op, 100);

        // Con ?nuevo=1 NO se retoma: se muestra la base limpia (sin dist).
        $resp = $this->actingAs($op)->get('/operativo/distribucion?nuevo=1&mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertOk();
        $this->assertNull($resp->viewData('distId'));
    }

    #[Test]
    public function al_retomar_se_muestra_el_banner_con_fecha_y_version(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $op = $this->operador();
        $this->guardarBorrador($op, 100);

        $html = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Retomando tu borrador guardado del', $html);
        // Lleva el contador del candado optimista del borrador en el formulario.
        $draft = Distribucion::where('estado', 'borrador')->firstOrFail();
        $this->assertStringContainsString('name="lock_version" value="'.$draft->lock_version.'"', $html);
    }

    #[Test]
    public function no_pisa_el_trabajo_de_otra_persona_si_la_version_esta_desfasada(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $op = $this->operador();

        // A crea el borrador y toma el contador (como lo tendría su formulario al cargar).
        $this->guardarBorrador($op, 100);
        $draft = Distribucion::where('estado', 'borrador')->firstOrFail();
        $lockA = $draft->lock_version;

        // B guarda un cambio sobre el mismo borrador (sube lock_version).
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'dist' => $draft->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 200]], 'estado_obra' => ['C-700' => 'parcial'],
            'lock_version' => $draft->fresh()->lock_version,
        ])->assertRedirect();
        $this->assertGreaterThan($lockA, $draft->fresh()->lock_version); // el contador subió

        // A intenta guardar con su marca VIEJA → debe rechazarse (no pisar a B).
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'dist' => $draft->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 999]], 'estado_obra' => ['C-700' => 'parcial'],
            'lock_version' => $lockA, 'auto' => '1',
        ])->assertStatus(409)->assertJson(['ok' => false, 'stale' => true]);

        // El monto de B (200) se conservó; el de A (999) no pisó.
        $this->assertEqualsWithDelta(200, (float) AplicacionCosto::where('distribucion_id', $draft->id)->sum('monto_aplicar'), 0.5);
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
