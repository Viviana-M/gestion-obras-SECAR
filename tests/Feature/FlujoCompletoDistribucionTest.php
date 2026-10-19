<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Recorrido COMPLETO del proceso, como lo hace el usuario, para validar que todo el flujo funciona
 * de punta a punta sobre las rutas/controladores reales (los 5 pasos del prototipo):
 *   1) Cargar cierre → 2) Distribución (bolsas + obras) → 3) Distribuir la obra →
 *   4) Guardar · generar plano (resumen por tipo) → 5) Mis distribuciones.
 */
class FlujoCompletoDistribucionTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function distribuidor(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar', 'contabilidad' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cuentaMayor, float $er, int $mes, int $anio, string $cc = '000000'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /** Escenario "cierre cargado": obra C-700 (mantenimiento) con ingreso + pendiente propio y la bolsa con 1000. */
    private function seedCierreCargado(): void
    {
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105'); // inventario en tránsito propio
        $this->rf('MTO00099', 'Costos por aplicar', -1000, 6, 2026, '14209999'); // bolsa del área
    }

    #[Test]
    public function el_usuario_recorre_los_cinco_pasos_del_proceso(): void
    {
        $user = $this->distribuidor();
        $this->seedCierreCargado();

        // ── Paso 1 · Cargar cierre: la pantalla de carga del mes está disponible. ──
        $this->actingAs($user)->get(route('contable.carga'))
            ->assertOk()
            ->assertSee('BIABLE', false);

        // ── Paso 2 · Distribución: se ven las bolsas (Otros costos + Mano de obra) y la tarjeta de la obra. ──
        $dist = $this->actingAs($user)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $dist->assertOk()
            ->assertSee('Otros costos', false)
            ->assertSee('Mano de obra', false)
            ->assertSee('Total bolsa', false)
            ->assertSee('id="bolsa-box-mantenimiento"', false)
            ->assertSee('id="card-C-700"', false);          // la obra aparece como tarjeta

        // La obra trae su pendiente propio (inventario en tránsito) listo para distribuir.
        $obra = $dist->viewData('obras')['C-700'];
        $this->assertGreaterThan(0, $obra['total_pendiente']);

        // ── Paso 3 · Distribuir la obra: aplica inventario propio (600) + bolsa (400). ──
        // (En pantalla esto se escribe en los inputs y el resumen se mueve en vivo por JS;
        //  aquí ejercemos el guardado real que respalda ese recálculo.)
        $this->actingAs($user)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'estado_obra'      => ['C-700' => 'parcial'],
            'aplicar'          => ['C-700' => ['14200105' => 600]],
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ])->assertRedirect();

        // Lo distribuido quedó registrado: 600 propio (14→61) + 400 de bolsa.
        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->whereNull('origen_bolsa')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(400, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->whereNotNull('origen_bolsa')->sum('monto_aplicar'), 0.5);
        $this->assertDatabaseHas('bolsa_asignaciones', [
            'bolsa_codigo' => 'mantenimiento', 'codigo_proyecto' => 'C-700', 'monto' => 400.00,
        ]);

        // Al recargar la pantalla, el estado y la asignación de bolsa persistidos se reflejan
        // (misma fuente de datos que alimenta el recálculo en vivo del navegador).
        $dist2 = $this->actingAs($user)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $obra2 = $dist2->viewData('obras')['C-700'];
        $this->assertSame('parcial', $obra2['estado']);

        // ── Paso 4 · Guardar · generar plano: la distribución quedó persistida y el resumen por tipo responde. ──
        $this->assertDatabaseHas('distribuciones', [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
        ]);
        $resumen = $this->actingAs($user)->post(route('operativo.distribucion.resumen'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar'          => ['C-700' => ['14200105' => 600]],
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ]);
        $resumen->assertOk()
            ->assertSee('Resumen de distribución', false)
            ->assertSee('Rentabilidad', false)   // columna del resumen por tipo
            ->assertSee('Total', false);          // fila de totales

        // ── Paso 5 · Mis distribuciones: la distribución guardada aparece en la lista. ──
        $this->actingAs($user)->get(route('operativo.distribucion.consultas'))
            ->assertOk()
            ->assertSee('Julio 2026', false)
            ->assertSee('Mantenimiento', false);
    }
}
