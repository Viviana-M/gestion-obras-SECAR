<?php

namespace Tests\Feature;

use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano 2 — Compensación entre cuentas 14 (14↔14). Obras NO cerradas con 1420 neto ≈ 0 pero
 * descuadrado por dentro (subcuentas opuestas). Se cruzan entre sí, sin tocar la 61.
 */
class CompensacionCuentas14Test extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'ver'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    /** Movimiento con el cuenta_mayor derivado del prefijo (14 → Costos por aplicar, 6 → Costos aplicados). */
    private function rf(string $cod, string $cuenta, float $er, int $mes = 6, int $anio = 2026): void
    {
        $mayor = str_starts_with($cuenta, '6') ? 'Costos aplicados' : 'Costos por aplicar';
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => $mayor, 'descripcion' => 'x', 'estado_er' => $er,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /** R0040284: 1420 neto 0 con dos subcuentas opuestas + 61 completa. */
    private function seedR0040284(): void
    {
        $this->rf('R0040284', '14200105', 6350000);   // una subcuenta en un sentido
        $this->rf('R0040284', '14203010', -6350000);  // la otra, opuesta → neto 1420 = 0
        $this->rf('R0040284', '61309530', 6355166);    // la 61 ya está completa (no se toca)
    }

    #[Test]
    public function detecta_r0040284_y_excluye_el_pendiente_c1101401(): void
    {
        $this->seedR0040284();
        $this->rf('C1101401', '14200105', 1489813); // pendiente distribuible: neto 1420 ≠ 0 → NO entra

        $resp = $this->actingAs($this->contable())->get(route('contable.compensacion-14.index'));
        $resp->assertStatus(200);

        $proyectos = collect($resp->viewData('filas'))->pluck('proyecto')->unique()->values()->all();
        $this->assertContains('R0040284', $proyectos);
        $this->assertNotContains('C1101401', $proyectos);
    }

    #[Test]
    public function aplicar_deja_las_subcuentas_14_en_cero_sin_tocar_la_61(): void
    {
        $this->seedR0040284();

        $this->actingAs($this->contable('editar'))->post(route('contable.compensacion-14.aplicar'), [
            'documento' => 9, 'destino_mes' => 6, 'destino_anio' => 2026,
        ])->assertRedirect();

        // Las dos subcuentas 14 quedan en 0…
        $this->assertEqualsWithDelta(0, RegistroFinanciero::where('codigo_proyecto', 'R0040284')->where('cuenta_contable', '14200105')->sum('estado_er'), 0.5);
        $this->assertEqualsWithDelta(0, RegistroFinanciero::where('codigo_proyecto', 'R0040284')->where('cuenta_contable', '14203010')->sum('estado_er'), 0.5);
        // …y la 61 NO cambia (sigue 6.355.166).
        $this->assertEqualsWithDelta(6355166, RegistroFinanciero::where('codigo_proyecto', 'R0040284')->where('cuenta_contable', '61309530')->sum('estado_er'), 0.5);

        // Queda registrado como plano aplicado deshacible (tipo compensacion_14).
        $plano = PlanoAplicado::where('tipo', 'compensacion_14')->first();
        $this->assertNotNull($plano);
        $this->assertSame(2, RegistroFinanciero::where('plano_aplicado_id', $plano->id)->count());
        $this->assertTrue(RegistroFinanciero::where('origen', 'compensacion_14')->where('codigo_proyecto', 'R0040284')->exists());
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_entrar(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true, 'permisos_modulos' => ['comercial' => 'ver']]);
        $this->actingAs($sin)->get(route('contable.compensacion-14.index'))->assertForbidden();
    }
}
