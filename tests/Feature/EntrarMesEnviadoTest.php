<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\Provision;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Al entrar a un mes YA ENVIADO se muestra la versión enviada congelada (solo lectura), NO se
 * retoma un borrador anterior. Y al enviar, los borradores previos quedan reemplazados.
 */
class EntrarMesEnviadoTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cod, string $cm, float $er, string $cc, int $mes, int $anio): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod, 'cuenta_contable' => $cc,
            'cuenta_mayor' => $cm, 'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function al_entrar_a_un_mes_enviado_se_muestra_la_version_enviada_congelada(): void
    {
        // Instalaciones Oct-2026 ya enviado (v11, reemplazada=0) + un borrador viejo v10 reemplazada=1.
        $this->rf('O-8001', 'Ingreso', 1_356_771_607, '41350100', 10, 2026);
        $this->rf('O-8001', 'Costos por aplicar', -500_000, '14200105', 10, 2026);   // para que se liste

        // Borrador viejo v10 (reemplazada): NO debe retomarse. Trae un inventario inflado distinto.
        $v10 = Distribucion::create(['mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones',
            'tipo' => 'obras', 'version' => 10, 'estado' => 'borrador', 'edicion_habilitada' => false,
            'reemplazada' => true]);
        AplicacionCosto::create(['distribucion_id' => $v10->id, 'mes' => 10, 'anio' => 2026,
            'codigo_proyecto' => 'O-8001', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'inv', 'monto_aplicar' => 1_276_228_755, 'es_provision' => false, 'estado' => 'ok']);

        // Enviada vigente v11 (congelada): inventario 976.604.711 + otros costos 19.107.822 = 995.712.533.
        $v11 = Distribucion::create(['mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones',
            'tipo' => 'obras', 'version' => 11, 'estado' => 'enviado', 'edicion_habilitada' => false,
            'reemplazada' => false, 'enviado_at' => now()]);
        AplicacionCosto::create(['distribucion_id' => $v11->id, 'mes' => 10, 'anio' => 2026,
            'codigo_proyecto' => 'O-8001', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'inv', 'monto_aplicar' => 976_604_711, 'es_provision' => false, 'estado' => 'enviado']);
        AplicacionCosto::create(['distribucion_id' => $v11->id, 'mes' => 10, 'anio' => 2026,
            'codigo_proyecto' => 'O-8001', 'cuenta_14' => '14200530', 'cuenta_61' => '61350105',
            'categoria' => 'MAT', 'nombre' => 'otros', 'monto_aplicar' => 19_107_822, 'es_provision' => false,
            'origen_bolsa' => 'IN-UN1', 'estado' => 'enviado']);
        Provision::create(['codigo_proyecto' => 'O-8001', 'departamento' => 'instalaciones',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 52_000_000, 'descripcion' => 'x',
            'mes' => 10, 'anio' => 2026, 'estado' => 'activa']);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=10&anio=2026&departamento=instalaciones');
        $resp->assertOk();

        // 1) Se carga la v11 enviada (no el borrador v10), en solo lectura.
        $this->assertSame($v11->id, $resp->viewData('distId'));
        $this->assertTrue((bool) $resp->viewData('bloqueado'));

        // 2) El panel toma las cifras CONGELADAS de la v11: 995.712.533 + 52.000.000 = 1.047.712.533.
        $det = $resp->viewData('costoDetPorDepto')['instalaciones'];
        $this->assertEqualsWithDelta(976_604_711, $det['inventario'], 1);
        $this->assertEqualsWithDelta(19_107_822, $det['otros'], 1);
        $this->assertEqualsWithDelta(0, $det['mano_obra'], 1);
        $this->assertEqualsWithDelta(52_000_000, $det['provision'], 1);
        $this->assertEqualsWithDelta(1_047_712_533, $resp->viewData('costoGuardadoPorDepto')['instalaciones'], 1);
        $this->assertEqualsWithDelta(1_356_771_607, $resp->viewData('ingresoPorDepto')['instalaciones'], 1);

        // 3) El encabezado del panel dice "Congelado · Enviado" y el front NO recalcula en vivo.
        $html = $resp->getContent();
        $this->assertStringContainsString('Congelado · Enviado', $html);
        $this->assertStringContainsString('const EC_BLOQUEADO = true', $html);
        $this->assertStringNotContainsString('1276228755', $html); // el inventario del v10 no aparece
    }

    #[Test]
    public function un_borrador_viejo_no_reemplazado_tampoco_se_reabre_ante_una_enviada_posterior(): void
    {
        // Caso defensivo: el borrador v10 quedó reemplazada=0 (dato viejo). Aun así, la enviada v11
        // (versión mayor) manda: se muestra lo congelado, no el borrador.
        $this->rf('O-8100', 'Ingreso', 1_000_000, '41350100', 10, 2026);
        $this->rf('O-8100', 'Costos por aplicar', -500_000, '14200105', 10, 2026);

        Distribucion::create(['mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones', 'tipo' => 'obras',
            'version' => 10, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false]);
        $v11 = Distribucion::create(['mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones', 'tipo' => 'obras',
            'version' => 11, 'estado' => 'enviado', 'edicion_habilitada' => false, 'reemplazada' => false, 'enviado_at' => now()]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=10&anio=2026&departamento=instalaciones');

        $this->assertSame($v11->id, $resp->viewData('distId'));
        $this->assertTrue((bool) $resp->viewData('bloqueado'));
    }

    #[Test]
    public function un_borrador_nuevo_tras_reabrir_si_se_edita(): void
    {
        // Tras reabrir, se creó un borrador v12 (versión mayor que la enviada v11): ESE se edita.
        $this->rf('O-8200', 'Ingreso', 1_000_000, '41350100', 10, 2026);
        $this->rf('O-8200', 'Costos por aplicar', -500_000, '14200105', 10, 2026);

        Distribucion::create(['mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones', 'tipo' => 'obras',
            'version' => 11, 'estado' => 'enviado', 'edicion_habilitada' => false, 'reemplazada' => false, 'enviado_at' => now()]);
        $v12 = Distribucion::create(['mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones', 'tipo' => 'obras',
            'version' => 12, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=10&anio=2026&departamento=instalaciones');

        $this->assertSame($v12->id, $resp->viewData('distId'));
        $this->assertFalse((bool) $resp->viewData('bloqueado'));   // editable, no congelado
    }

    #[Test]
    public function al_enviar_los_borradores_previos_quedan_reemplazados(): void
    {
        $this->rf('C-1', 'Ingreso', 5_000_000, '41350100', 7, 2026);
        $this->rf('C-1', 'Costos por aplicar', -1_000_000, '14200105', 7, 2026);
        $op = $this->operador();

        // Borrador VIEJO (reemplazada=0) que quedaba reabrible pese a existir el envío.
        $viejo = Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'tipo' => 'obras', 'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false,
            'reemplazada' => false, 'guardado_por' => $op->id]);

        // Borrador vigente que se va a ENVIAR.
        $vigente = Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'tipo' => 'obras', 'version' => 2, 'estado' => 'borrador', 'edicion_habilitada' => false,
            'reemplazada' => false, 'guardado_por' => $op->id]);

        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'enviar', 'dist' => $vigente->id, 'mes' => 7, 'anio' => 2026,
            'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 1_000_000]], 'estado_obra' => ['C-1' => 'parcial'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // El enviado queda vigente; el borrador viejo queda reemplazado (ya no se puede retomar).
        $this->assertSame('enviado', $vigente->refresh()->estado);
        $this->assertFalse((bool) $vigente->reemplazada);
        $this->assertTrue((bool) $viejo->refresh()->reemplazada);

        // Al volver a entrar se muestra el ENVIADO (solo lectura), no un borrador.
        $resp = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $this->assertSame($vigente->id, $resp->viewData('distId'));
        $this->assertTrue((bool) $resp->viewData('bloqueado'));
    }
}
