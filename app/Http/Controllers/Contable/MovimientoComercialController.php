<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ItemDistribucion;
use App\Models\LlaveItemCuenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Cargue del movimiento comercial (BIABLE, hoja Comercial_Mvto) que puebla
 * items_distribucion. Cada ítem se cruza con la llave de cuentas (tipo de
 * inventario + código de movimiento) para asignar su cuenta y naturaleza; los que
 * no cruzan se reportan (no se descartan en silencio). Al recargar un período, se
 * reemplaza (borrar e insertar). Lectura de una sola hoja + inserción por lotes.
 */
class MovimientoComercialController extends Controller
{
    private const HOJA = 'Comercial_Mvto';
    private const LOTE = 500;

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        // Resumen de lo cargado: ítems por período.
        $porPeriodo = ItemDistribucion::selectRaw('anio, mes, COUNT(*) as filas, COUNT(DISTINCT codigo_obra) as obras')
            ->groupBy('anio', 'mes')->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.movimiento-comercial', ['porPeriodo' => $porPeriodo]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls|max:102400']);

        $full = $request->file('archivo')->getRealPath();

        // Cargar SOLO la hoja Comercial_Mvto (menos memoria en archivos grandes).
        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::HOJA]);
        $spreadsheet = $reader->load($full);
        $sheet = $spreadsheet->getSheetByName(self::HOJA);
        if ($sheet === null) {
            return back()->with('error', 'El archivo no tiene la hoja "'.self::HOJA.'".');
        }
        $rows = $sheet->toArray(null, true, false, false);
        if (empty($rows)) {
            return back()->with('error', 'La hoja "'.self::HOJA.'" está vacía.');
        }

        // Localizar encabezados y mapear columnas por nombre (flexible).
        [$headerIdx, $col] = $this->mapearColumnas($rows);
        if ($headerIdx === null) {
            return back()->with('error', 'No encontré los encabezados esperados en "'.self::HOJA.'" (Unidad de Negocio, Periodo, Tipo de Inventario, motivo, Cuenta/costo).');
        }

        // Llave precargada en memoria: [tipo|codigo] => ['cuenta','naturaleza'].
        $llaves = LlaveItemCuenta::activos()
            ->get(['tipo_inventario', 'codigo_movimiento', 'cuenta', 'naturaleza'])
            ->keyBy(fn ($l) => $l->tipo_inventario.'|'.$l->codigo_movimiento);

        $ahora     = now();
        $registros = [];
        $periodos  = [];       // "anio-mes" => [anio,mes]
        $sinLlave  = [];       // "tipo|codigo" => ['tipo','codigo','n']
        $saltadas  = 0;

        foreach ($rows as $i => $r) {
            if ($i <= $headerIdx) continue;

            $obra = $this->texto($r[$col['codigo_obra']] ?? null);
            [$mes, $anio] = $this->periodo($r[$col['periodo']] ?? null);
            $tipoInv = $this->texto($r[$col['tipo_inventario']] ?? null);
            $codMov  = $this->texto($r[$col['codigo_movimiento']] ?? null);
            if ($obra === null || $mes === null || $tipoInv === null || $codMov === null) {
                $saltadas++;
                continue;
            }

            $llave = $llaves[$tipoInv.'|'.$codMov] ?? null;
            if ($llave === null) {
                $k = $tipoInv.'|'.$codMov;
                $sinLlave[$k] ??= ['tipo' => $tipoInv, 'codigo' => $codMov, 'n' => 0];
                $sinLlave[$k]['n']++;
            }

            $periodos["{$anio}-{$mes}"] = [$anio, $mes];
            $registros[] = [
                'codigo_obra'       => $obra,
                'mes'               => $mes,
                'anio'              => $anio,
                'cuenta'            => $llave->cuenta ?? '',          // vacío = sin cruzar (reportado)
                'item'              => $this->texto($r[$col['item']] ?? null) ?? '',
                'tipo_inventario'   => $tipoInv,
                'codigo_movimiento' => $codMov,
                'tipo_movimiento'   => $col['tipo_movimiento'] !== null ? $this->texto($r[$col['tipo_movimiento']] ?? null) : null,
                'naturaleza'        => $llave->naturaleza ?? null,
                'tercero'           => $col['tercero'] !== null ? $this->texto($r[$col['tercero']] ?? null) : null,
                'cantidad'          => $col['cantidad'] !== null ? $this->num($r[$col['cantidad']] ?? null) : null,
                'fecha'             => $col['fecha'] !== null ? $this->fecha($r[$col['fecha']] ?? null) : null,
                'numero_documento'  => $col['numero_documento'] !== null ? $this->texto($r[$col['numero_documento']] ?? null) : null,
                'costo'             => abs($this->num($r[$col['costo']] ?? null)), // positivo; el signo lo da la naturaleza
                'created_at'        => $ahora,
                'updated_at'        => $ahora,
            ];
        }

        if (empty($registros)) {
            return back()->with('error', 'No encontré filas de datos válidas en la hoja.');
        }

        // Reemplazar los períodos presentes en el archivo (borrar e insertar por lotes).
        DB::transaction(function () use ($periodos, $registros) {
            foreach ($periodos as [$anio, $mes]) {
                ItemDistribucion::where('anio', $anio)->where('mes', $mes)->delete();
            }
            foreach (array_chunk($registros, self::LOTE) as $lote) {
                DB::table('items_distribucion')->insert($lote);
            }
        });

        // Mensaje + reporte de ítems sin llave.
        $n = count($registros);
        $sinN = array_sum(array_column($sinLlave, 'n'));
        $listaPeriodos = implode(', ', array_map(fn ($p) => sprintf('%02d/%d', $p[1], $p[0]), $periodos));
        $msg = "Se cargaron {$n} ítems ({$listaPeriodos}).";
        if ($saltadas > 0) $msg .= " Se saltaron {$saltadas} filas incompletas.";

        if ($sinN > 0) {
            $detalle = collect($sinLlave)->sortByDesc('n')->take(15)
                ->map(fn ($s) => "tipo {$s['tipo']} · motivo {$s['codigo']} ({$s['n']})")->implode(', ');
            $extra = count($sinLlave) > 15 ? ' …' : '';
            return redirect()->route('contable.movimiento-comercial.index')
                ->with('success', $msg)
                ->with('warning', "{$sinN} ítems sin cuenta en la llave (no cruzaron): {$detalle}{$extra}. Revisa el maestro \"Llave de cuentas por ítem\".");
        }

        return redirect()->route('contable.movimiento-comercial.index')->with('success', $msg);
    }

    /**
     * Encuentra la fila de encabezados y mapea las columnas por su nombre.
     * @return array{0:?int,1:array}
     */
    private function mapearColumnas(array $rows): array
    {
        $keys = ['codigo_obra', 'periodo', 'tipo_inventario', 'codigo_movimiento', 'tipo_movimiento', 'item', 'tercero', 'cantidad', 'fecha', 'numero_documento', 'costo'];
        foreach ($rows as $i => $r) {
            $found = array_fill_keys($keys, null);
            foreach ($r as $j => $cell) {
                $h = $this->norm((string) $cell);
                if ($h === '') continue;
                if (str_contains($h, 'PERIODO')) {
                    $found['periodo'] = $j;
                } elseif (str_contains($h, 'UNIDAD') && str_contains($h, 'NEGOCIO')) {
                    $found['codigo_obra'] = $j;
                } elseif (str_contains($h, 'TIPO') && str_contains($h, 'INVENTARIO')) {
                    $found['tipo_inventario'] = $j;
                } elseif (str_contains($h, 'MOTIVO')) {
                    $found[str_contains($h, 'DESC') ? 'tipo_movimiento' : 'codigo_movimiento'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'ITEM')) {
                    $found['item'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'TERCERO')) {
                    $found['tercero'] = $j;
                } elseif (str_contains($h, 'CANTIDAD')) {
                    $found['cantidad'] = $j;
                } elseif (str_contains($h, 'FECHA')) {
                    $found['fecha'] = $j;
                } elseif (str_contains($h, 'NUMERO') && str_contains($h, 'DOCUMENTO')) {
                    $found['numero_documento'] = $j;
                } elseif (str_contains($h, 'COSTO')) {
                    $found['costo'] = $j;
                }
            }
            if ($found['codigo_obra'] !== null && $found['periodo'] !== null
                && $found['tipo_inventario'] !== null && $found['codigo_movimiento'] !== null
                && $found['costo'] !== null) {
                return [$i, $found];
            }
        }
        return [null, []];
    }

    /** Normaliza encabezado: sin acentos, mayúsculas, "_" y espacios como separadores. */
    private function norm(string $s): string
    {
        $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U']);
        return strtoupper(trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $s))));
    }

    /** Periodo YYYYMM → [mes, anio]. */
    private function periodo($v): array
    {
        $d = preg_replace('/\D/', '', (string) $v);
        if (strlen($d) < 6) return [null, null];
        $anio = (int) substr($d, 0, 4);
        $mes  = (int) substr($d, 4, 2);
        if ($mes < 1 || $mes > 12) return [null, null];
        return [$mes, $anio];
    }

    private function texto($v): ?string
    {
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }

    private function num($v): float
    {
        if (is_numeric($v)) return (float) $v;
        $s = str_replace([' ', '$'], '', trim((string) $v));
        if ($s === '') return 0.0;
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = (strrpos($s, ',') > strrpos($s, '.')) ? str_replace('.', '', $s) : str_replace(',', '', $s);
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function fecha($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            try { return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d'); }
            catch (\Throwable $e) { return null; }
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y'] as $f) {
            $d = \DateTime::createFromFormat($f, $s);
            $err = \DateTime::getLastErrors();
            $ok = $err === false || (empty($err['warning_count']) && empty($err['error_count']));
            if ($d !== false && $ok) return $d->format('Y-m-d');
        }
        return null;
    }
}
