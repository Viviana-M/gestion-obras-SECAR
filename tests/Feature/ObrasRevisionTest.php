<?php

namespace Tests\Feature;

use App\Models\ObservacionRevision;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObrasRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cod, string $cuentaMayor, float $er, string $cuentaContable = '000000'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod,
            'cuenta_contable' => $cuentaContable, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
    }

    /** GM000045: cuenta 14 neta ≈ 0 (se cancela) pero con costo en cuenta 6. */
    private function seedGM000045(): void
    {
        $this->rf('GM000045', 'Costos por aplicar', 500, '14350150');   // +500
        $this->rf('GM000045', 'Costos por aplicar', -500, '14350151');  // -500  => neto 0
        $this->rf('GM000045', 'Costos aplicados', -800, '61350150');    // costo cuenta 6
    }

    /** C-800: saldo real en cuenta 14 (aparece en Distribución, no en revisión). */
    private function seedConSaldo(): void
    {
        $this->rf('C-800', 'Costos por aplicar', -900, '14350110');
        $this->rf('C-800', 'Ingreso', 1500, '41350110');
    }

    #[Test]
    public function gm000045_no_aparece_en_distribucion(): void
    {
        $this->seedGM000045();
        $this->seedConSaldo();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('id="card-C-800"', false);          // con saldo real: sí aparece
        $resp->assertDontSee('id="card-GM000045"', false);   // neto ≈ 0: no aparece
    }

    #[Test]
    public function gm000045_si_aparece_en_obras_revision(): void
    {
        $this->seedGM000045();
        $this->seedConSaldo();

        $resp = $this->actingAs($this->operador())->get(route('operativo.obras-revision.index'));

        $resp->assertStatus(200);
        $resp->assertSee('GM000045');       // sin saldo en 14 pero con costo: sí
        $resp->assertDontSee('C-800');      // tiene saldo en 14: no va a revisión
    }

    #[Test]
    public function la_revision_marca_margen_negativo(): void
    {
        // costo 800, ingreso 0 => margen -800 (negativo, urgente).
        $this->seedGM000045();

        $resp = $this->actingAs($this->operador())->get(route('operativo.obras-revision.index'));
        $resp->assertStatus(200);
        $resp->assertSee('Enviar a cierre');
        $resp->assertSee('🔴'); // marcador de urgente por margen negativo
    }

    #[Test]
    public function guardar_observacion_persiste_con_autor(): void
    {
        $this->seedGM000045();
        $op = $this->operador();

        $this->actingAs($op)->post(route('operativo.obras-revision.observar'), [
            'codigo_proyecto' => 'GM000045',
            'observacion'     => 'Se deja abierta: garantía en curso.',
        ])->assertRedirect();

        $this->assertDatabaseHas('observaciones_revision', [
            'codigo_proyecto' => 'GM000045',
            'observacion'     => 'Se deja abierta: garantía en curso.',
            'user_id'         => $op->id,
        ]);
    }

    #[Test]
    public function observar_es_idempotente_por_proyecto(): void
    {
        $op = $this->operador();
        $this->actingAs($op)->post(route('operativo.obras-revision.observar'), ['codigo_proyecto' => 'GM000045', 'observacion' => 'v1']);
        $this->actingAs($op)->post(route('operativo.obras-revision.observar'), ['codigo_proyecto' => 'GM000045', 'observacion' => 'v2']);

        $this->assertSame(1, ObservacionRevision::where('codigo_proyecto', 'GM000045')->count());
        $this->assertSame('v2', ObservacionRevision::where('codigo_proyecto', 'GM000045')->value('observacion'));
    }

    #[Test]
    public function un_usuario_sin_permiso_no_puede_observar(): void
    {
        $sinPermiso = User::factory()->create([
            'rol' => 'comercial', 'activo' => true, 'permisos_modulos' => ['comercial' => 'ver'],
        ]);

        $this->actingAs($sinPermiso)->post(route('operativo.obras-revision.observar'), [
            'codigo_proyecto' => 'GM000045', 'observacion' => 'x',
        ])->assertForbidden();
    }

    #[Test]
    public function enviar_a_cierre_precarga_el_codigo(): void
    {
        $contable = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);

        $this->actingAs($contable)->get(route('contable.cierre-obras', ['codigo' => 'GM000045']))
            ->assertStatus(200)
            ->assertSee('value="GM000045"', false);
    }
}
