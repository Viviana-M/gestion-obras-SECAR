<?php

namespace App\Http\Controllers\Contable;

use App\Exports\Recon14Export;
use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reconciliación de la cuenta 14 ("Costos por aplicar") entre el SISTEMA y el archivo del ERP.
 * El usuario sube el auxiliar de cuenta 14 del ERP; se compara el saldo por obra contra el del
 * sistema para detectar obras que no cuadran (meses faltantes, cargues viejos o incompletos),
 * con drill-down por mes para saber cuál mes recargar.
 *
 * Nota de signo: el sistema guarda los costos negados (estado_er negativo = pendiente) y el ERP
 * los muestra positivos. Para comparar se usa la convención ERP: saldo_sistema = −SUM(estado_er).
 */
class ReconciliacionErpController extends Controller
{
    private const CLAVE_SESION = 'recon14';

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        return view('contable.recon14', ['recon' => session(self::CLAVE_SESION)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls|max:102400']);

        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        DB::connection()->disableQueryLog();

        $full = $request->file('archivo')->getRealPath();

        try {
            $erp = $this->leerErp($full);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo leer el archivo del ERP: '.$e->getMessage());
        }

        if (empty($erp['obras']) && $erp['detalle'] === 0) {
            return back()->with('error',
                'No encontré filas de detalle en el archivo. Revisa que traiga las columnas "U.N.", '.
                '"Fecha" y "Nit movto." (el reporte cuenta solo las filas con fecha y NIT).');
        }

        $recon = $this->comparar($erp, $request->file('archivo')->getClientOriginalName());
        session([self::CLAVE_SESION => $recon]);

        return redirect()->route('contable.recon14.index')
            ->with('success', "ERP procesado: {$erp['detalle']} movimientos de detalle, ".count($recon['filas']).' obras comparadas.');
    }

    public function excel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $recon = session(self::CLAVE_SESION);
        if (! $recon) {
            return redirect()->route('contable.recon14.index')
                ->with('error', 'Primero sube el archivo del ERP para generar la comparación.');
        }

        $rows = [['Código', 'Obra', 'Saldo ERP', 'Saldo sistema', 'Diferencia (ERP − sistema)', 'Estado']];
        foreach ($recon['filas'] as $f) {
            $rows[] = [$f['codigo'], $f['nombre'], round($f['saldo_erp'], 2), round($f['saldo_sistema'], 2),
                round($f['diferencia'], 2), $f['cuadra'] ? 'Cuadra' : 'Revisar'];
        }
        $rows[] = ['', 'TOTAL',
            round(array_sum(array_column($recon['filas'], 'saldo_erp')), 2),
            round(array_sum(array_column($recon['filas'], 'saldo_sistema')), 2),
            round(array_sum(array_column($recon['filas'], 'diferencia')), 2), ''];

        return Excel::download(new Recon14Export($rows), 'Reconciliacion_cuenta_14_'.date('Ymd').'.xlsx');
    }

    public function limpiar(Request $request)
    {
        session()->forget(self::CLAVE_SESION);

        return redirect()->route('contable.recon14.index');
    }

    // ═══════════════════════ Lectura del ERP ═══════════════════════

    /**
     * Lee el auxiliar del ERP de forma robusta: localiza los encabezados por nombre (no por
     * posición), ignora "Gran total", filas sin código y subtotales (sin fecha o sin NIT), y
     * acumula por obra el Neto (o Débitos − Créditos) y el neto por mes.
     *
     * @return array{obras:array<string,float>, porMes:array<string,array<string,float>>, nombres:array<string,string>, detalle:int}
     */
    private function leerErp(string $full): array
    {
        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($full);

        $obras   = [];   // codigoNorm => neto
        $porMes  = [];   // codigoNorm => ['YYYY-MM' => neto]
        $nombres = [];   // codigoNorm => código tal como viene (display)
        $detalle = 0;

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, false, false);

            [$headerIdx, $col] = $this->mapearColumnas($rows);
            if ($headerIdx === null) {
                continue; // esta hoja no tiene los encabezados esperados
            }

            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $r   = $rows[$i];
                $un  = trim((string) ($r[$col['un']] ?? ''));
                if ($un === '' || $this->norm($un) === 'GRAN TOTAL') {
                    continue; // sin código o gran total
                }

                // Solo filas de DETALLE: con fecha Y con NIT (los subtotales no traen ambas).
                $periodo = isset($col['fecha']) ? $this->periodo($r[$col['fecha']] ?? null) : null;
                $nit     = isset($col['nit']) ? trim((string) ($r[$col['nit']] ?? '')) : '';
                if ($periodo === null || $nit === '') {
                    continue;
                }

                $neto = $this->netoFila($r, $col);
                $key  = $this->normCod($un);

                $obras[$key]  = ($obras[$key] ?? 0) + $neto;
                $nombres[$key] ??= $un;
                $ym = sprintf('%04d-%02d', $periodo[0], $periodo[1]);
                $porMes[$key][$ym] = ($porMes[$key][$ym] ?? 0) + $neto;
                $detalle++;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return ['obras' => $obras, 'porMes' => $porMes, 'nombres' => $nombres, 'detalle' => $detalle];
    }

    /**
     * Localiza la fila de encabezados y mapea columnas por nombre. Devuelve [idxFila, mapa].
     * @return array{0:?int,1:array<string,int>}
     */
    private function mapearColumnas(array $rows): array
    {
        foreach ($rows as $i => $r) {
            $found = [];
            foreach ($r as $j => $cell) {
                $h = $this->norm((string) $cell);
                if ($h === '') continue;
                if ($h === 'UN' || (str_contains($h, 'UNIDAD') && str_contains($h, 'NEGOCIO'))) {
                    $found['un'] ??= $j;
                } elseif (str_contains($h, 'DEBITO')) {
                    $found['debitos'] ??= $j;
                } elseif (str_contains($h, 'CREDITO')) {
                    $found['creditos'] ??= $j;
                } elseif ($h === 'NETO' || str_contains($h, 'NETO')) {
                    $found['neto'] ??= $j;
                } elseif (str_contains($h, 'FECHA')) {
                    $found['fecha'] ??= $j;
                } elseif (str_contains($h, 'NIT')) {
                    $found['nit'] ??= $j;
                }
            }
            // Encabezado válido: al menos U.N. y (Neto o Débitos/Créditos).
            if (isset($found['un']) && (isset($found['neto']) || (isset($found['debitos']) && isset($found['creditos'])))) {
                return [$i, $found];
            }
        }
        return [null, []];
    }

    private function netoFila(array $r, array $col): float
    {
        if (isset($col['neto'])) {
            $n = $this->num($r[$col['neto']] ?? null);
            if (abs($n) > 0.0000001) {
                return $n;
            }
        }
        $deb = isset($col['debitos'])  ? $this->num($r[$col['debitos']]  ?? null) : 0.0;
        $cre = isset($col['creditos']) ? $this->num($r[$col['creditos']] ?? null) : 0.0;
        return $deb - $cre;
    }

    // ═══════════════════════ Comparación ═══════════════════════

    /**
     * Cruza el ERP contra el sistema (convención ERP), arma la tabla y el detalle por mes.
     *
     * @param  array{obras:array,porMes:array,nombres:array,detalle:int}  $erp
     */
    private function comparar(array $erp, string $archivo): array
    {
        // Sistema: saldo por obra y por obra/mes (convención ERP = −SUM(estado_er)).
        $sisObra = [];
        foreach (RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as s')->groupBy('codigo_proyecto')->get() as $r) {
            $sisObra[$this->normCod($r->codigo_proyecto)] = -1 * (float) $r->s;
        }

        $sisMes = [];  // codigoNorm => ['YYYY-MM' => saldoErp]
        foreach (RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, anio, mes, SUM(estado_er) as s')
            ->groupBy('codigo_proyecto', 'anio', 'mes')->get() as $r) {
            $ym = sprintf('%04d-%02d', (int) $r->anio, (int) $r->mes);
            $sisMes[$this->normCod($r->codigo_proyecto)][$ym] = -1 * (float) $r->s;
        }

        // Nombres desde la ficha y desde los movimientos del sistema.
        $nombres = [];
        foreach (FichaProyecto::get(['codigo_proyecto', 'nombre_obra']) as $f) {
            $nombres[$this->normCod($f->codigo_proyecto)] = (string) $f->nombre_obra;
        }
        foreach (RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, MAX(nombre_proyecto) as n')->groupBy('codigo_proyecto')->get() as $r) {
            $k = $this->normCod($r->codigo_proyecto);
            if (empty($nombres[$k])) $nombres[$k] = (string) $r->n;
        }

        $codigos = array_unique(array_merge(array_keys($erp['obras']), array_keys($sisObra)));

        $filas = [];
        $mesDetalle = [];
        foreach ($codigos as $k) {
            $saldoErp = round((float) ($erp['obras'][$k] ?? 0), 2);
            $saldoSis = round((float) ($sisObra[$k] ?? 0), 2);
            $dif      = round($saldoErp - $saldoSis, 2);
            $cuadra   = abs($dif) <= 1000;

            $filas[] = [
                'codigo'        => $erp['nombres'][$k] ?? $this->displayDe($k, $sisObra, $nombres),
                'nombre'        => $nombres[$k] ?? '',
                'saldo_erp'     => $saldoErp,
                'saldo_sistema' => $saldoSis,
                'diferencia'    => $dif,
                'cuadra'        => $cuadra,
                'en_erp'        => isset($erp['obras'][$k]),
                'en_sistema'    => isset($sisObra[$k]),
            ];

            // Detalle por mes solo para las que NO cuadran (para el drill-down).
            if (! $cuadra) {
                $mesDetalle[$k] = $this->detallePorMes($erp['porMes'][$k] ?? [], $sisMes[$k] ?? []);
            }
        }

        usort($filas, fn ($a, $b) => abs($b['diferencia']) <=> abs($a['diferencia']));

        // Reindexar el detalle por el código visible (para el JS).
        $mesPorCodigo = [];
        foreach ($filas as $f) {
            $k = $this->normCod($f['codigo']);
            if (isset($mesDetalle[$k])) {
                $mesPorCodigo[$f['codigo']] = $mesDetalle[$k];
            }
        }

        return [
            'generado' => now()->format('Y-m-d H:i'),
            'archivo'  => $archivo,
            'filas'    => $filas,
            'mes'      => $mesPorCodigo,
        ];
    }

    /** Combina el neto por mes del ERP y del sistema, marcando los meses que difieren. */
    private function detallePorMes(array $erpMes, array $sisMes): array
    {
        $meses = array_unique(array_merge(array_keys($erpMes), array_keys($sisMes)));
        sort($meses);

        $out = [];
        foreach ($meses as $ym) {
            $e = round((float) ($erpMes[$ym] ?? 0), 2);
            $s = round((float) ($sisMes[$ym] ?? 0), 2);
            $out[] = ['mes' => $ym, 'erp' => $e, 'sistema' => $s, 'dif' => round($e - $s, 2),
                'resaltar' => abs($e - $s) > 1000];
        }
        return $out;
    }

    private function displayDe(string $k, array $sisObra, array $nombres): string
    {
        return $k; // respaldo: el código normalizado
    }

    // ═══════════════════════ Helpers ═══════════════════════

    /** Normaliza encabezado: sin acentos, mayúsculas, sin puntos, espacios colapsados. */
    private function norm(string $s): string
    {
        $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U']);
        $s = str_replace('.', '', $s);
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /** Clave de cruce de código de obra: sin espacios, mayúsculas. */
    private function normCod($s): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $s)));
    }

    /** [anio, mes] de una fecha (serial Excel, ISO o dd/mm/aaaa); null si no es fecha. */
    private function periodo($v): ?array
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            try {
                $d = ExcelDate::excelToDateTimeObject((float) $v);
                return [(int) $d->format('Y'), (int) $d->format('n')];
            } catch (\Throwable $e) { return null; }
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y'] as $f) {
            $d = \DateTime::createFromFormat($f, $s);
            $err = \DateTime::getLastErrors();
            $ok = $err === false || (empty($err['warning_count']) && empty($err['error_count']));
            if ($d !== false && $ok) return [(int) $d->format('Y'), (int) $d->format('n')];
        }
        return null;
    }

    private function num($v): float
    {
        if (is_numeric($v)) return (float) $v;
        $s = str_replace(['$', ' '], '', trim((string) $v));
        if ($s === '' || $s === '-') return 0.0;
        // Paréntesis = negativo (contable): (1.234) → −1234
        $neg = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) { $neg = true; $s = substr($s, 1, -1); }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = (strrpos($s, ',') > strrpos($s, '.')) ? str_replace('.', '', $s) : str_replace(',', '', $s);
        }
        $s = str_replace(',', '.', $s);
        $n = is_numeric($s) ? (float) $s : 0.0;
        return $neg ? -$n : $n;
    }
}
