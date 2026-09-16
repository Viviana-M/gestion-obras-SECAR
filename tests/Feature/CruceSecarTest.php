<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CruceSecarTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'ver'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function rf(string $cod, float $er, string $tercero, string $razon, string $cuentaMayor = 'Costos por aplicar'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod,
            'cuenta_contable' => '14350105', 'cuenta_mayor' => $cuentaMayor,
            'tercero_dcto' => $tercero, 'razon_social' => $razon,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 8, 'anio' => 2024,
        ]);
    }

    #[Test]
    public function separa_el_saldo_secar_del_saldo_real_por_obra(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'MO008570', 'nombre_obra' => 'Obra grande', 'activa' => false]);

        // SECAR: +853.057.866 ; terceros reales: −953.574.268 ; total = −100.516.402
        $this->rf('MO008570', 853057866, '890319324', 'SECAR INGENIEROS SA');
        $this->rf('MO008570', -953574268, '800111222', 'PROVEEDOR REAL SAS');

        $resp = $this->actingAs($this->contable())->get(route('contable.cruce-secar.index'));
        $resp->assertOk();

        $fila = collect($resp->viewData('filas'))->firstWhere('codigo', 'MO008570');
        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(853057866, $fila['saldo_secar'], 1);
        $this->assertEqualsWithDelta(-100516402, $fila['saldo_total'], 1);
        $this->assertEqualsWithDelta(-953574268, $fila['saldo_real'], 1);
        $this->assertSame('Inactiva', $fila['estado']);   // según ficha_proyectos.activa
    }

    #[Test]
    public function solo_muestra_obras_con_saldo_secar_relevante_y_ordena_desc(): void
    {
        // Obra A: saldo SECAR grande. Obra B: saldo SECAR pequeño. Obra C: sin SECAR (excluida).
        $this->rf('OBA', 900000, '890319324', 'SECAR INGENIEROS SA');
        $this->rf('OBB', 100000, '90319324', 'SECAR INGENIEROS SA');   // acepta el NIT sin el 8
        $this->rf('OBC', 500000, '800999', 'OTRO PROVEEDOR');           // sin SECAR → no aparece

        $resp = $this->actingAs($this->contable())->get(route('contable.cruce-secar.index'));
        $codigos = collect($resp->viewData('filas'))->pluck('codigo')->all();

        $this->assertSame(['OBA', 'OBB'], $codigos);   // ordenadas por |saldo_secar| desc, sin OBC
    }

    #[Test]
    public function descarga_el_excel(): void
    {
        Excel::fake();
        $this->rf('MO008570', 853057866, '890319324', 'SECAR INGENIEROS SA');

        $this->actingAs($this->contable())->get(route('contable.cruce-secar.excel'))->assertOk();

        Excel::assertDownloaded('Cruce_cuenta_14_vs_SECAR_'.date('Ymd').'.xlsx');
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_ver(): void
    {
        $ajeno = User::factory()->create(['rol' => 'operario', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver']]);
        $this->actingAs($ajeno)->get(route('contable.cruce-secar.index'))->assertForbidden();
    }
}
