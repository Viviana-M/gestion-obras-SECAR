<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\ItemDistribucion;
use App\Models\LlaveItemCuenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
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

        // El Comercial_Mvto real trae ~14.000 filas × 128 columnas; leerlas todas es muy
        // lento. Quitamos el tope de tiempo y aseguramos memoria suficiente.
        @set_time_limit(0);
        $this->subirMemoria('1024M');

        $full = $request->file('archivo')->getRealPath();

        // Pasada 1 (barata): leer solo las primeras filas (todas las columnas) para localizar
        // los encabezados y las columnas que necesitamos.
        $muestra = $this->leerHoja($full, new class implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool { return $row <= 100; }
        });
        if ($muestra === null) {
            return back()->with('error', 'El archivo no tiene la hoja "'.self::HOJA.'".');
        }
        if (empty($muestra)) {
            return back()->with('error', 'La hoja "'.self::HOJA.'" está vacía.');
        }

        [$headerIdx, $col] = $this->mapearColumnas($muestra);
        if ($headerIdx === null) {
            return back()->with('error', 'No encontré los encabezados esperados en "'.self::HOJA.'" (Unidad de Negocio, Periodo, Tipo de Inventario, motivo, Cuenta/costo).');
        }

        // Pasada 2 (rápida): leer TODAS las filas pero SOLO las columnas necesarias
        // (~11 en vez de 128). Un read filter por columnas acelera muchísimo.
        $letras = [];
        foreach ($col as $offset) {
            if ($offset !== null) $letras[Coordinate::stringFromColumnIndex($offset + 1)] = true;
        }
        $rows = $this->leerHoja($full, new class($letras) implements IReadFilter {
            public function __construct(private array $letras) {}
            public function readCell($columnAddress, $row, $worksheetName = ''): bool { return isset($this->letras[$columnAddress]); }
        });
        if (empty($rows)) {
            return back()->with('error', 'No encontré filas de datos válidas en la hoja.');
        }

        // Llave precargada en memoria: [tipo|codigo] => ['cuenta','naturaleza'].
        $llaves = LlaveItemCuenta::activos()
            ->get(['tipo_inventario', 'codigo_movimiento', 'cuenta', 'naturaleza'])
            ->keyBy(fn ($l) => $l->tipo_inventario.'|'.$l->codigo_movimiento);

        $ahora      = now();
        $registros  = [];
        $periodos   = [];      // "anio-mes" => [anio,mes]
        $sinLlave   = [];      // "tipo|codigo" => ['tipo','codigo','n']
        $saltadas   = 0;
        $traslados  = 0;       // TRASLADO OT: se manejan como reasignación (Fase D), no como costo

        foreach ($rows as $i => $r) {
            if ($i <= $headerIdx) continue;

            $obra = $this->codigoObra($r[$col['codigo_obra']] ?? null);
            [$mes, $anio] = $this->periodo($r[$col['periodo']] ?? null);
            $tipoInv = $this->texto($r[$col['tipo_inventario']] ?? null);
            $codMov  = $this->texto($r[$col['codigo_movimiento']] ?? null);
            if ($obra === null || $mes === null || $tipoInv === null || $codMov === null) {
                $saltadas++;
                continue;
            }

            // La descripción del motivo (Desc_motivo) manda sobre la naturaleza: un mismo
            // código se usa para movimientos opuestos (14 = Salida y Reintegro; 01 =
            // Traslado, Salida, Entrada). El traslado NO es costo directo: es reasignación (Fase D).
            $desc = $col['tipo_movimiento'] !== null ? $this->texto($r[$col['tipo_movimiento']] ?? null) : null;
            if ($this->esTraslado($desc)) {
                $traslados++;
                continue;
            }

            $llave = $llaves[$tipoInv.'|'.$codMov] ?? null;
            if ($llave === null) {
                $k = $tipoInv.'|'.$codMov;
                $sinLlave[$k] ??= ['tipo' => $tipoInv, 'codigo' => $codMov, 'n' => 0];
                $sinLlave[$k]['n']++;
            }

            // Naturaleza por descripción; si la descripción no la define, cae a la de la llave.
            $naturaleza = $this->naturalezaPorDescripcion($desc) ?? ($llave->naturaleza ?? null);

            $periodos["{$anio}-{$mes}"] = [$anio, $mes];
            $registros[] = [
                'codigo_obra'       => $obra,
                'mes'               => $mes,
                'anio'              => $anio,
                'cuenta'            => $llave->cuenta ?? '',          // vacío = sin cruzar (reportado)
                'item'              => $this->texto($r[$col['item']] ?? null) ?? '',
                'tipo_inventario'   => $tipoInv,
                'codigo_movimiento' => $codMov,
                'tipo_movimiento'   => $desc,
                'naturaleza'        => $naturaleza,
                'tercero'           => $col['tercero'] !== null ? $this->texto($r[$col['tercero']] ?? null) : null,
                'cantidad'          => $col['cantidad'] !== null ? abs($this->num($r[$col['cantidad']] ?? null)) : null, // valor absoluto
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
        if ($traslados > 0) $msg .= " Se omitieron {$traslados} traslados (se manejan como reasignación).";

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
     * Lee la hoja Comercial_Mvto (solo esa, datos crudos) aplicando un read filter
     * (por filas o por columnas) para no cargar todo el archivo. Devuelve las filas como
     * array, o null si la hoja no existe.
     */
    private function leerHoja(string $full, IReadFilter $filtro): ?array
    {
        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::HOJA]);
        $reader->setReadFilter($filtro);
        $spreadsheet = $reader->load($full);
        $sheet = $spreadsheet->getSheetByName(self::HOJA);
        if ($sheet === null) {
            return null;
        }
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /** Sube el límite de memoria al objetivo solo si el actual es menor (nunca lo baja). */
    private function subirMemoria(string $objetivo): void
    {
        $actual = ini_get('memory_limit');
        if ($actual === false || $actual === '-1') return; // sin límite
        if ($this->aBytes($actual) < $this->aBytes($objetivo)) {
            @ini_set('memory_limit', $objetivo);
        }
    }

    private function aBytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1024 ** 3,
            'm'     => $n * 1024 ** 2,
            'k'     => $n * 1024,
            default => $n,
        };
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
                // "Unidad de Negocio" trae DOS columnas: el CÓDIGO (ej. MOB08644) y el
                // NOMBRE ("nombre Unidad de Negocio", ej. 360 GROUP SAS). El código_obra es
                // la que NO contiene "NOMBRE"; igual criterio para el tipo de inventario.
                if (str_contains($h, 'PERIODO')) {
                    $found['periodo'] = $j;
                } elseif (str_contains($h, 'UNIDAD') && str_contains($h, 'NEGOCIO') && !str_contains($h, 'NOMBRE')) {
                    $found['codigo_obra'] = $j;
                } elseif (str_contains($h, 'TIPO') && str_contains($h, 'INVENTARIO') && !str_contains($h, 'NOMBRE')) {
                    $found['tipo_inventario'] = $j;
                } elseif (str_contains($h, 'MOTIVO')) {
                    $found[str_contains($h, 'DESC') ? 'tipo_movimiento' : 'codigo_movimiento'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'ITEM')) {
                    $found['item'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'TERCERO')) {
                    $found['tercero'] = $j;
                } elseif (str_contains($h, 'CANTIDAD') && str_contains($h, 'NET') && str_contains($h, '1')) {
                    // Hay muchas "Cantidad*"; la que usamos es la cantidad neta (Cantidad_net_1).
                    $found['cantidad'] = $j;
                } elseif (str_contains($h, 'FECHA')) {
                    $found['fecha'] = $j;
                } elseif (str_contains($h, 'NUMERO') && str_contains($h, 'DOCUMENTO')) {
                    $found['numero_documento'] = $j;
                } elseif (str_contains($h, 'COSTO') && str_contains($h, 'PROM') && str_contains($h, 'NET')) {
                    // Hay muchas "Costo*"; usamos el costo promedio neto (Costo_prom_net).
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

    /**
     * Naturaleza contable a partir de la DESCRIPCIÓN del movimiento (no del código, que
     * es ambiguo): "Reintegro"/"Entrada" → Crédito (resta costo); "Salida" → Débito
     * (suma). Devuelve null si la descripción no lo define (se cae a la llave).
     */
    private function naturalezaPorDescripcion(?string $desc): ?string
    {
        $d = $this->norm((string) $desc);
        if ($d === '') return null;
        if (str_contains($d, 'REINTEGRO') || str_contains($d, 'ENTRADA')) return 'Crédito';
        if (str_contains($d, 'SALIDA')) return 'Débito';
        return null;
    }

    /** Un traslado ("TRASLADO OT") no es costo directo: es reasignación entre OT (Fase D). */
    private function esTraslado(?string $desc): bool
    {
        return str_contains($this->norm((string) $desc), 'TRASLADO');
    }

    /**
     * Limpia el código de obra para que cruce EXACTO con el de la distribución:
     * trim, quita un prefijo tipo "ct " que trae el export comercial (ct MOB08644 →
     * MOB08644) y elimina espacios sobrantes (el código no lleva espacios internos).
     */
    private function codigoObra($v): ?string
    {
        $t = trim((string) $v);
        if ($t === '') return null;
        $t = preg_replace('/^ct\s+/i', '', $t);   // "ct MOB08644" → "MOB08644"
        $t = preg_replace('/\s+/', '', $t);         // "MOB 08644" → "MOB08644"
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
