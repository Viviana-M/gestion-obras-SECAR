<?php

namespace Tests\Feature;

use App\Models\ItemDistribucion;
use App\Models\LlaveItemCuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MovimientoComercialTest extends TestCase
{
    use RefreshDatabase;

    private function contadora(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    /** Genera un xlsx con la hoja Comercial_Mvto (encabezados + filas). */
    private function biable(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Comercial_Mvto');
        $sheet->fromArray([
            'Unidad de Negocio', 'Periodo', 'Tipo de Inventario', 'motivo', 'Desc_motivo',
            'Nombre Item', 'Nombre Tercero', 'Cantidad_net_1', 'Fecha', 'Numero_documento', 'Costo_prom_net',
        ], null, 'A1');
        $r = 2;
        foreach ($filas as $f) { $sheet->fromArray($f, null, 'A'.$r); $r++; }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'biable.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * xlsx con las columnas de NOMBRE que trae el BIABLE real, justo después de sus
     * códigos: "nombre Unidad de Negocio" y "Nombre Tipo de Inventario". Cada fila:
     * [codObra, nombreObra, periodo, tipoInv, nombreTipoInv, motivo, desc, item, tercero, cant, fecha, ndoc, costo].
     */
    private function biableConNombres(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Comercial_Mvto');
        $sheet->fromArray([
            'Unidad de Negocio', 'nombre Unidad de Negocio', 'Periodo', 'Tipo de Inventario', 'Nombre Tipo de Inventario',
            'motivo', 'Desc_motivo', 'Nombre Item', 'Nombre Tercero', 'Cantidad_net_1', 'Fecha', 'Numero_documento', 'Costo_prom_net',
        ], null, 'A1');
        $r = 2;
        foreach ($filas as $f) { $sheet->fromArray($f, null, 'A'.$r); $r++; }
        $path = tempnam(sys_get_temp_dir(), 'biablen').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'biable.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * xlsx "ancho" como el real (columna basura al inicio + ~120 columnas de ruido al
     * final) para ejercitar el read filter por columnas. Fila:
     * [codObra, nombreObra, periodo, tipoInv, motivo, desc, item, tercero, cant, fecha, ndoc, costo].
     */
    private function biableAncho(array $filas): UploadedFile
    {
        $ruido = array_map(fn ($n) => 'colX'.$n, range(1, 120));
        $cab = array_merge(
            ['basura', 'Unidad de Negocio', 'nombre Unidad de Negocio', 'Periodo', 'Tipo de Inventario',
                'motivo', 'Desc_motivo', 'Nombre Item', 'Nombre Tercero', 'Cantidad_net_1', 'Fecha', 'Numero_documento', 'Costo_prom_net'],
            $ruido
        );
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Comercial_Mvto');
        $sheet->fromArray($cab, null, 'A1');
        $r = 2;
        foreach ($filas as $f) {
            $sheet->fromArray(array_merge(['x'], $f, array_fill(0, count($ruido), 'z')), null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'biablew').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'biable.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function seedLlave(): void
    {
        LlaveItemCuenta::create(['tipo_inventario' => '01', 'codigo_movimiento' => '14', 'cuenta' => '73950505', 'naturaleza' => 'Débito', 'activo' => true]);
        LlaveItemCuenta::create(['tipo_inventario' => '01', 'codigo_movimiento' => '15', 'cuenta' => '73950505', 'naturaleza' => 'Crédito', 'activo' => true]);
    }

    #[Test]
    public function carga_la_hoja_cruza_con_la_llave_y_reporta_los_sin_cuenta(): void
    {
        $this->seedLlave();

        $file = $this->biable([
            ['C-700', '202607', '01', '14', 'Salida Directa', 'Cemento', 'FERRETERIA X', 10, '2026-07-10', 'FAC-1', 500000],
            ['C-700', '202607', '01', '14', 'Salida Directa', 'Arena', 'AGREGADOS', 5, '2026-07-11', 'FAC-2', 300000],
            ['C-700', '202607', '01', '15', 'Reintegro', 'Cemento dev.', 'FERRETERIA X', 2, '2026-07-12', 'REI-1', 200000],
            ['C-700', '202607', '09', '99', 'Ajuste raro', 'Item sin llave', 'X', 1, '2026-07-13', 'DOC-9', 111],
        ]);

        $resp = $this->actingAs($this->contadora())
            ->post(route('contable.movimiento-comercial.store'), ['archivo' => $file]);

        $resp->assertRedirect();
        $resp->assertSessionHas('warning'); // reporta los sin cuenta
        $this->assertStringContainsString('tipo 09', session('warning'));
        $this->assertStringContainsString('motivo 99', session('warning'));

        // Se cargaron los 4 ítems; 3 con cuenta (cruzaron) y 1 sin cuenta.
        $this->assertSame(4, ItemDistribucion::count());
        $this->assertSame(3, ItemDistribucion::where('cuenta', '73950505')->count());
        $this->assertSame(1, ItemDistribucion::where('cuenta', '')->count());

        // El cruce trae cuenta y naturaleza de la llave.
        $this->assertDatabaseHas('items_distribucion', [
            'codigo_obra' => 'C-700', 'mes' => 7, 'anio' => 2026,
            'cuenta' => '73950505', 'codigo_movimiento' => '14', 'naturaleza' => 'Débito', 'costo' => 500000,
        ]);
        $this->assertDatabaseHas('items_distribucion', [
            'codigo_movimiento' => '15', 'naturaleza' => 'Crédito', 'cuenta' => '73950505',
        ]);
    }

    #[Test]
    public function recargar_el_mismo_periodo_reemplaza(): void
    {
        $this->seedLlave();

        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biable([
                ['C-700', '202607', '01', '14', 'Salida', 'A', 'X', 1, '2026-07-10', 'D1', 100],
                ['C-700', '202607', '01', '14', 'Salida', 'B', 'X', 1, '2026-07-10', 'D2', 200],
            ]),
        ])->assertRedirect();
        $this->assertSame(2, ItemDistribucion::where('anio', 2026)->where('mes', 7)->count());

        // Recargar el MISMO período con una sola fila reemplaza (no acumula).
        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biable([
                ['C-700', '202607', '01', '14', 'Salida', 'C', 'X', 1, '2026-07-10', 'D3', 999],
            ]),
        ])->assertRedirect();
        $this->assertSame(1, ItemDistribucion::where('anio', 2026)->where('mes', 7)->count());
        $this->assertSame('999.00', (string) ItemDistribucion::first()->costo);
    }

    #[Test]
    public function toma_la_cantidad_y_el_costo_de_las_columnas_especificas_no_las_decoy(): void
    {
        $this->seedLlave();

        // Hoja con varias "Cantidad*" y "Costo*" DECOY (vacías) antes de las reales
        // (Cantidad_net_1 / Costo_prom_net). El mapeo debe tomar las específicas, no las vacías.
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Comercial_Mvto');
        $sheet->fromArray([
            'Unidad de Negocio', 'Periodo', 'Tipo de Inventario', 'motivo', 'Desc_motivo', 'Nombre Item', 'Nombre Tercero',
            'Cantidad_bruta', 'Cantidad_net_1', 'Cantidad_facturada', 'Fecha', 'Numero_documento',
            'Costo_total', 'Costo_prom_net', 'Costo_ultimo',
        ], null, 'A1');
        // Decoys de cantidad (H, J) y de costo (M, O) van VACÍAS; las reales (I=cant, N=costo) traen el valor.
        $sheet->fromArray(
            ['MOB08644', '202606', '01', '14', 'Salida Directa Inventario en Obra', 'Cemento', 'FERRETERIA', '', 7, '', '2026-06-10', 'FAC-1', '', 90000, ''],
            null, 'A2'
        );
        $path = tempnam(sys_get_temp_dir(), 'biabled').'.xlsx';
        (new Xlsx($ss))->save($path);
        $file = new UploadedFile($path, 'biable.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), ['archivo' => $file])
            ->assertRedirect();

        $it = ItemDistribucion::where('codigo_obra', 'MOB08644')->first();
        $this->assertNotNull($it);
        // Cantidad y costo reales, no 0 (no tomó las columnas decoy vacías).
        $this->assertEqualsWithDelta(7, (float) $it->cantidad, 0.001);
        $this->assertEqualsWithDelta(90000, (float) $it->costo, 0.5);
    }

    #[Test]
    public function lee_hojas_anchas_por_read_filter_sin_desalinear_columnas(): void
    {
        $this->seedLlave();

        // Hoja con columna basura al inicio y ~120 de ruido: el read filter carga solo las
        // necesarias y el mapeo (por offset real) no se desalinea.
        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biableAncho([
                ['MOB08644', '360 GROUP SAS', '202606', '01', '14', 'Salida Directa Inventario en Obra', 'Cemento', 'FERRETERIA', 10, '2026-06-10', 'FAC-1', 500000],
            ]),
        ])->assertRedirect();

        $this->assertSame(1, ItemDistribucion::where('codigo_obra', 'MOB08644')->count());
        $it = ItemDistribucion::where('codigo_obra', 'MOB08644')->first();
        $this->assertSame('01', $it->tipo_inventario);
        $this->assertSame('14', $it->codigo_movimiento);
        $this->assertSame('73950505', $it->cuenta);
        $this->assertSame('Débito', $it->naturaleza);
        $this->assertEqualsWithDelta(500000, (float) $it->costo, 0.5);
        $this->assertSame('Cemento', $it->item);
    }

    #[Test]
    public function gana_la_columna_exacta_tipo_de_inventario_aunque_haya_variantes_despues(): void
    {
        LlaveItemCuenta::create(['tipo_inventario' => 'INV145525', 'codigo_movimiento' => '14', 'cuenta' => '14200105', 'naturaleza' => 'Débito', 'activo' => true]);

        // Hoja donde DESPUÉS de "Tipo de Inventario" (código) hay otra columna parecida con
        // el nombre ("Descripcion Tipo de Inventario") que NO contiene "NOMBRE": no debe pisar.
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Comercial_Mvto');
        $sheet->fromArray([
            'Unidad de Negocio', 'Periodo', 'Tipo de Inventario', 'Descripcion Tipo de Inventario',
            'motivo', 'Desc_motivo', 'Nombre Item', 'Nombre Tercero', 'Cantidad_net_1', 'Fecha', 'Numero_documento', 'Costo_prom_net',
        ], null, 'A1');
        $sheet->fromArray(
            ['MOB08644', '202606', 'INV145525', 'MATERIALES Y REPUESTOS', '14', 'Salida Directa Inventario en Obra', 'Cemento', 'FERR', 5, '2026-06-10', 'FAC-1', 90000],
            null, 'A2'
        );
        $path = tempnam(sys_get_temp_dir(), 'biablev').'.xlsx';
        (new Xlsx($ss))->save($path);
        $file = new UploadedFile($path, 'biable.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), ['archivo' => $file])
            ->assertRedirect();

        $it = ItemDistribucion::where('codigo_obra', 'MOB08644')->first();
        $this->assertNotNull($it);
        $this->assertSame('INV145525', $it->tipo_inventario);   // el código, NO "MATERIALES Y REPUESTOS"
        $this->assertGreaterThan(0, ItemDistribucion::where('codigo_obra', 'MOB08644')->where('cuenta', '14200105')->count());
    }

    #[Test]
    public function el_tipo_de_inventario_toma_el_codigo_para_cruzar_la_llave(): void
    {
        // Llave con el CÓDIGO real del tipo de inventario (no el nombre).
        LlaveItemCuenta::create(['tipo_inventario' => 'INV145525', 'codigo_movimiento' => '14', 'cuenta' => '14200105', 'naturaleza' => 'Débito', 'activo' => true]);

        // El BIABLE trae "Tipo de Inventario" (código INV145525) y "Nombre Tipo de
        // Inventario" (nombre MATERIALES Y REPUESTOS). tipo_inventario debe quedar con el CÓDIGO.
        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biableConNombres([
                ['MOB08644', '360 GROUP SAS', '202606', 'INV145525', 'MATERIALES Y REPUESTOS', '14', 'Salida Directa Inventario en Obra', 'Cemento', 'FERRETERIA', 1, '2026-06-10', 'FAC-1', 500000],
            ]),
        ])->assertRedirect();

        $it = ItemDistribucion::where('codigo_obra', 'MOB08644')->first();
        $this->assertSame('INV145525', $it->tipo_inventario);          // el código, no "MATERIALES Y REPUESTOS"
        // Al quedar el código, cruza la llave y obtiene la cuenta 14200105.
        $this->assertGreaterThan(0, ItemDistribucion::where('codigo_obra', 'MOB08644')->where('cuenta', '14200105')->count());
    }

    #[Test]
    public function guarda_el_codigo_de_la_unidad_de_negocio_no_el_nombre(): void
    {
        $this->seedLlave();

        // El BIABLE trae "Unidad de Negocio" (código) y "nombre Unidad de Negocio" (nombre).
        // codigo_obra debe quedar con el CÓDIGO, no con el nombre.
        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biableConNombres([
                ['MOB08644', '360 GROUP SAS', '202606', '01', 'Inventario en Obra', '14', 'Salida Directa Inventario en Obra', 'Cemento', 'FERRETERIA', 10, '2026-06-10', 'FAC-1', 500000],
                ['OB008657', 'CLINICA LILI', '202606', '01', 'Inventario en Obra', '14', 'Salida Directa Inventario en Obra', 'Arena', 'AGREGADOS', 5, '2026-06-11', 'FAC-2', 300000],
            ]),
        ])->assertRedirect();

        // Se guarda el código, nunca el nombre.
        $this->assertSame(1, ItemDistribucion::where('codigo_obra', 'MOB08644')->count());
        $this->assertSame(1, ItemDistribucion::where('codigo_obra', 'OB008657')->count());
        $this->assertSame(0, ItemDistribucion::where('codigo_obra', '360 GROUP SAS')->count());
        $this->assertSame(0, ItemDistribucion::where('codigo_obra', 'CLINICA LILI')->count());
        // El tipo de inventario también toma el código, no "Nombre Tipo de Inventario".
        $this->assertSame('01', ItemDistribucion::where('codigo_obra', 'MOB08644')->first()->tipo_inventario);
    }

    #[Test]
    public function la_naturaleza_se_toma_de_la_descripcion_no_del_codigo(): void
    {
        $this->seedLlave(); // código 14 → Débito en la llave

        // MISMO código 14, descripciones opuestas: la naturaleza la manda la descripción.
        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biable([
                ['C-700', '202607', '01', '14', 'Salida Directa Inventario en Obra', 'Cemento', 'X', 1, '2026-07-10', 'S1', 500000],
                ['C-700', '202607', '01', '14', 'Reintegro salida directa inv. en Obra', 'Cemento dev', 'X', 1, '2026-07-11', 'R1', 200000],
            ]),
        ])->assertRedirect();

        // La salida (código 14) queda Débito; el reintegro (mismo código 14) queda Crédito.
        $this->assertDatabaseHas('items_distribucion', ['item' => 'Cemento', 'codigo_movimiento' => '14', 'naturaleza' => 'Débito']);
        $this->assertDatabaseHas('items_distribucion', ['item' => 'Cemento dev', 'codigo_movimiento' => '14', 'naturaleza' => 'Crédito']);

        // La naturaleza define el signo del costo neto: salida suma, reintegro resta.
        $this->assertEqualsWithDelta(500000, ItemDistribucion::where('item', 'Cemento')->first()->costoNeto(), 0.5);
        $this->assertEqualsWithDelta(-200000, ItemDistribucion::where('item', 'Cemento dev')->first()->costoNeto(), 0.5);
    }

    #[Test]
    public function el_traslado_no_se_carga_como_costo_directo(): void
    {
        $this->seedLlave();
        LlaveItemCuenta::create(['tipo_inventario' => '01', 'codigo_movimiento' => '01', 'cuenta' => '14200105', 'naturaleza' => 'Débito', 'activo' => true]);

        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biable([
                ['C-700', '202607', '01', '01', 'TRASLADO OT', 'ItemTraslado', 'X', 1, '2026-07-10', 'T1', 300000],
                ['C-700', '202607', '01', '14', 'Salida Directa Inventario en Obra', 'ItemSalida', 'X', 1, '2026-07-10', 'S1', 100000],
            ]),
        ])->assertRedirect();

        // El traslado se omite (Fase D); solo entra la salida.
        $this->assertSame(0, ItemDistribucion::where('item', 'ItemTraslado')->count());
        $this->assertSame(1, ItemDistribucion::where('item', 'ItemSalida')->count());
        $this->assertStringContainsString('traslado', session('success'));
    }

    #[Test]
    public function limpia_el_codigo_de_obra_para_que_cruce_exacto(): void
    {
        $this->seedLlave();

        // El export comercial trae códigos "sucios": prefijo "ct " y espacios.
        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biable([
                ['ct MOB08644', '202607', '01', '14', 'Salida', 'A', 'X', 1, '2026-07-10', 'D1', 100],
                ['  MOB 08644 ', '202607', '01', '14', 'Salida', 'B', 'X', 1, '2026-07-11', 'D2', 200],
            ]),
        ])->assertRedirect();

        // Ambos quedan como 'MOB08644' (sin prefijo ni espacios), listos para cruzar.
        $this->assertSame(2, ItemDistribucion::where('codigo_obra', 'MOB08644')->count());
        $this->assertSame(0, ItemDistribucion::where('codigo_obra', 'like', '%ct %')->count());
        $this->assertSame(0, ItemDistribucion::where('codigo_obra', 'like', '% %')->count());
    }

    #[Test]
    public function falla_si_no_existe_la_hoja_comercial_mvto(): void
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->setTitle('OtraHoja');
        $ss->getActiveSheet()->fromArray(['x'], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'otra').'.xlsx';
        (new Xlsx($ss))->save($path);
        $file = new UploadedFile($path, 'otra.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contadora())->post(route('contable.movimiento-comercial.store'), ['archivo' => $file])
            ->assertRedirect();
        $this->assertSame(0, ItemDistribucion::count());
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_cargar(): void
    {
        $sinPermiso = User::factory()->create([
            'rol' => 'aux', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);

        $this->actingAs($sinPermiso)->get(route('contable.movimiento-comercial.index'))->assertForbidden();
        $this->actingAs($sinPermiso)->post(route('contable.movimiento-comercial.store'), [
            'archivo' => $this->biable([['C-700', '202607', '01', '14', 'x', 'i', 't', 1, '2026-07-10', 'D', 1]]),
        ])->assertForbidden();
    }
}
