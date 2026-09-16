<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObrasInactivasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cuentaMayor, float $er, int $mes, int $anio, string $cc): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function una_obra_inactiva_con_saldo_no_se_cierra_y_se_lista(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'MOB09001', 'nombre_obra' => 'Obra con saldo', 'activa' => false]);
        $this->rf('MOB09001', 'Costos por aplicar', -100000, 8, 2024, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=8&anio=2024&departamento=mantenimiento');
        $resp->assertOk();

        // Aparece en el listado de inactivas con saldo…
        $inact = collect($resp->viewData('inactivasConSaldo'))->pluck('codigo')->all();
        $this->assertContains('MOB09001', $inact);

        // …y NO se cierra: sigue abierta y marcada para revisión.
        $obras = $resp->viewData('obras');
        $this->assertArrayHasKey('MOB09001', $obras);
        $this->assertNotSame('cerrada', $obras['MOB09001']['estado']);
        $this->assertTrue($obras['MOB09001']['inactiva_con_saldo']);
    }

    #[Test]
    public function una_obra_inactiva_sin_saldo_no_aparece_en_el_listado(): void
    {
        // Inactiva pero su cuenta 14 neta a cero (−100 y +100): sin saldo pendiente.
        FichaProyecto::create(['codigo_proyecto' => 'MOB09002', 'nombre_obra' => 'Obra sin saldo', 'activa' => false]);
        $this->rf('MOB09002', 'Costos por aplicar', -100000, 8, 2024, '14350105');
        $this->rf('MOB09002', 'Costos por aplicar', 100000, 8, 2024, '14350105');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=8&anio=2024&departamento=mantenimiento');
        $resp->assertOk();

        $inact = collect($resp->viewData('inactivasConSaldo'))->pluck('codigo')->all();
        $this->assertNotContains('MOB09002', $inact);
        // Sin saldo neto: la obra no queda en la lista de trabajo (se puede cerrar).
        $this->assertArrayNotHasKey('MOB09002', $resp->viewData('obras'));
    }

    #[Test]
    public function el_reporte_de_inactivas_lista_solo_las_inactivas_con_saldo(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'MOB09010', 'nombre_obra' => 'Inactiva saldo', 'activa' => false]);
        FichaProyecto::create(['codigo_proyecto' => 'MOB09011', 'nombre_obra' => 'Activa saldo', 'activa' => true]);
        $this->rf('MOB09010', 'Costos por aplicar', -50000, 8, 2024, '14350105');
        $this->rf('MOB09011', 'Costos por aplicar', -80000, 8, 2024, '14350105');

        $resp = $this->actingAs($this->operador())->get(route('operativo.obras-inactivas.index', ['mes' => 8, 'anio' => 2024]));
        $resp->assertOk();
        $resp->assertSee('MOB09010');       // inactiva con saldo → sí
        $resp->assertDontSee('MOB09011');   // activa → no
    }

    #[Test]
    public function el_reporte_es_visible_para_contabilidad(): void
    {
        $contable = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'ver'],
        ]);

        $this->actingAs($contable)->get(route('operativo.obras-inactivas.index'))->assertOk();
    }

    /** El importador lee la columna ACTIVA y la conserva si no viene. */
    #[Test]
    public function el_importador_lee_la_columna_activa_y_la_conserva_si_no_viene(): void
    {
        $con = $this->hoja([
            ['ORDEN DE TRABAJO', 'OBRA', 'ACTIVA'],
            ['MOB09020', 'Obra X', 'No'],
            ['MOB09021', 'Obra Y', 'Si'],
        ]);

        $this->actingAs($this->operador())
            ->post(route('operativo.maestro.importar'), ['archivo' => $con])
            ->assertRedirect();

        $this->assertFalse((bool) FichaProyecto::where('codigo_proyecto', 'MOB09020')->first()->activa);
        $this->assertTrue((bool) FichaProyecto::where('codigo_proyecto', 'MOB09021')->first()->activa);

        // Reimportar SIN la columna ACTIVA: conserva el valor actual (MOB09020 sigue inactiva).
        $sin = $this->hoja([
            ['ORDEN DE TRABAJO', 'OBRA'],
            ['MOB09020', 'Obra X actualizada'],
        ]);
        $this->actingAs($this->operador())
            ->post(route('operativo.maestro.importar'), ['archivo' => $sin])
            ->assertRedirect();

        $f = FichaProyecto::where('codigo_proyecto', 'MOB09020')->first();
        $this->assertFalse((bool) $f->activa);                       // se conservó
        $this->assertSame('Obra X actualizada', $f->nombre_obra);    // sí se actualizó lo demás
    }

    #[Test]
    public function el_importador_cierra_las_inactivas_sin_saldo_y_no_las_que_tienen_saldo(): void
    {
        // MOB09030: inactiva CON saldo en cuenta 14 → no se cierra, va a la lista de revisión.
        // MOB09031: inactiva SIN saldo → se cierra (ProyectoCerrado origen excel).
        $this->rf('MOB09030', 'Costos por aplicar', -120000, 8, 2024, '14350105');

        $resp = $this->actingAs($this->operador())->post(route('operativo.maestro.importar'), [
            'archivo' => $this->hoja([
                ['ORDEN DE TRABAJO', 'OBRA', 'ACTIVA'],
                ['MOB09030', 'Con saldo', 'No'],
                ['MOB09031', 'Sin saldo', 'No'],
            ]),
        ]);
        $resp->assertRedirect();

        // La sin saldo queda cerrada; la con saldo NO.
        $this->assertDatabaseHas('proyectos_cerrados', ['codigo_proyecto' => 'MOB09031', 'origen' => 'excel']);
        $this->assertDatabaseMissing('proyectos_cerrados', ['codigo_proyecto' => 'MOB09030']);

        // La con saldo sale en la lista de revisión (flash) y el mensaje trae los conteos.
        $inact = collect(session('inactivasImport'))->pluck('codigo')->all();
        $this->assertContains('MOB09030', $inact);
        $this->assertStringContainsString('1 obras cerradas', session('success'));
        $this->assertStringContainsString('1 inactivas con saldo', session('success'));
    }

    #[Test]
    public function el_importador_reabre_las_activas_cerradas_por_excel_pero_no_las_manuales(): void
    {
        $uid = $this->operador()->id;
        ProyectoCerrado::create(['codigo_proyecto' => 'MOB09040', 'tipo_cierre' => 'total', 'origen' => 'excel', 'user_id' => $uid]);
        ProyectoCerrado::create(['codigo_proyecto' => 'MOB09041', 'tipo_cierre' => 'total', 'origen' => 'manual', 'user_id' => $uid]);

        $this->actingAs($this->operador())->post(route('operativo.maestro.importar'), [
            'archivo' => $this->hoja([
                ['ORDEN DE TRABAJO', 'OBRA', 'ACTIVA'],
                ['MOB09040', 'Reabrir', 'Si'],
                ['MOB09041', 'Cerrada a mano', 'Si'],
            ]),
        ])->assertRedirect();

        // La cerrada por Excel se reabre; la cerrada a mano se conserva.
        $this->assertDatabaseMissing('proyectos_cerrados', ['codigo_proyecto' => 'MOB09040']);
        $this->assertDatabaseHas('proyectos_cerrados', ['codigo_proyecto' => 'MOB09041']);
    }

    #[Test]
    public function el_maestro_busca_y_pagina(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            FichaProyecto::create(['codigo_proyecto' => sprintf('MOB%05d', $i), 'nombre_obra' => 'Obra '.$i, 'area' => 'Mantenimiento']);
        }

        $resp = $this->actingAs($this->operador())->get(route('operativo.maestro.index'));
        $resp->assertOk();
        $fichas = $resp->viewData('fichas');
        $this->assertSame(60, $fichas->total());
        $this->assertSame(50, $fichas->count());      // una página = 50
        $this->assertTrue($fichas->hasPages());

        // Buscador por código.
        $r2 = $this->actingAs($this->operador())->get(route('operativo.maestro.index', ['q' => 'MOB00007']));
        $this->assertSame(1, $r2->viewData('fichas')->total());
    }

    /** Genera un xlsx (hoja MANTENIMIENTO) con las filas dadas. */
    private function hoja(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('MANTENIMIENTO');
        $r = 1;
        foreach ($filas as $f) {
            $sheet->fromArray($f, null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'maestro').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'maestro.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
