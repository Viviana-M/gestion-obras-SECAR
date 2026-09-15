<?php

namespace App\Support\Lotes;

use App\Models\ItemDistribucion;
use App\Models\LlaveItemCuenta;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importa el movimiento de almacén (BIABLE, hoja Comercial_Mvto) por lotes hacia
 * items_distribucion. Cada ítem se cruza con la llave de cuentas (tipo de inventario +
 * código de movimiento). Al recargar un período se reemplaza. Contiene todo el mapeo de
 * columnas (antes en el controlador) como fuente única.
 */
class ImportadorMovimiento implements ImportadorLotes
{
    private const HOJA = 'Comercial_Mvto';
    private const LOTE_INSERT = 500;

    public function tipo(): string
    {
        return 'movimiento';
    }

    public function preparar(string $rutaAbsoluta, array $contexto = []): array
    {
        $lector = new LectorExcel();
        $info   = $lector->infoHoja($rutaAbsoluta, self::HOJA);
        if ($info['nombre'] !== self::HOJA) {
            throw new \RuntimeException('El archivo no tiene la hoja "'.self::HOJA.'".');
        }
        if ($info['total_filas'] < 1) {
            throw new \RuntimeException('La hoja "'.self::HOJA.'" está vacía.');
        }

        // Muestra (primeras filas, todas las columnas) para localizar encabezados.
        $topeMuestra = min($info['total_filas'], 100);
        $muestra = $lector->leerVentana($rutaAbsoluta, self::HOJA, 1, $topeMuestra, null, $info['ultima_columna']);

        [$headerIdx, $col] = $this->mapearColumnas($muestra);
        if ($headerIdx === null) {
            throw new \RuntimeException(
                'No encontré los encabezados esperados en "'.self::HOJA.'" (Unidad de Negocio, Periodo, '.
                'Tipo de Inventario, motivo, Cuenta/costo).'
            );
        }

        // Solo cargamos las columnas necesarias (hoja ancha: ~128 columnas → ~11).
        $letras = [];
        foreach ($col as $offset) {
            if ($offset !== null) {
                $letras[Coordinate::stringFromColumnIndex($offset + 1)] = true;
            }
        }

        $filaEncabezado = $headerIdx + 1;                     // 1-based
        $primeraData    = $filaEncabezado + 1;

        // Períodos presentes: leemos SOLO la columna de período en todas las filas (barato) para
        // saber qué meses reemplazar antes de insertar.
        $letraPeriodo = Coordinate::stringFromColumnIndex(((int) $col['periodo']) + 1);
        $periodos     = [];
        if ($info['total_filas'] >= $primeraData) {
            $soloPeriodo = $lector->leerVentana(
                $rutaAbsoluta, self::HOJA, $primeraData, $info['total_filas'],
                [$letraPeriodo => true], $info['ultima_columna']
            );
            foreach ($soloPeriodo as $r) {
                [$mes, $anio] = $this->periodo($r[$col['periodo']] ?? null);
                if ($mes !== null) {
                    $periodos["{$anio}-{$mes}"] = [$anio, $mes];
                }
            }
        }

        if (empty($periodos)) {
            throw new \RuntimeException('No encontré filas con período válido en la hoja "'.self::HOJA.'".');
        }

        // Reemplazo: borra cada período presente en el archivo.
        foreach ($periodos as [$anio, $mes]) {
            ItemDistribucion::where('anio', $anio)->where('mes', $mes)->delete();
        }

        // mes/anio "dominante" (el primero) solo para mostrar en el registro de progreso.
        [$anioDom, $mesDom] = array_values($periodos)[0];

        return [
            'mes'  => $mesDom,
            'anio' => $anioDom,
            'meta' => [
                'hoja'            => self::HOJA,
                'fila_encabezado' => $filaEncabezado,
                'col'             => $col,
                'letras'          => array_keys($letras),
                'periodos'        => array_values($periodos),
            ],
        ];
    }

    public function procesarFilas(array $filas, array $meta, int $mes, int $anio): array
    {
        $col = array_map(fn ($v) => $v === null ? null : (int) $v, $meta['col'] ?? []);

        $llaves = LlaveItemCuenta::activos()
            ->get(['tipo_inventario', 'codigo_movimiento', 'cuenta', 'naturaleza'])
            ->keyBy(fn ($l) => $l->tipo_inventario.'|'.$l->codigo_movimiento);

        $ahora     = now();
        $registros = [];
        $insertadas = 0;
        $saltadas  = 0;
        $traslados = 0;
        $sinLlave  = [];

        foreach ($filas as $r) {
            $obra = $this->codigoObra($r[$col['codigo_obra']] ?? null);
            [$mesFila, $anioFila] = $this->periodo($r[$col['periodo']] ?? null);
            $tipoInv = $this->texto($r[$col['tipo_inventario']] ?? null);
            $codMov  = $this->texto($r[$col['codigo_movimiento']] ?? null);
            if ($obra === null || $mesFila === null || $tipoInv === null || $codMov === null) {
                // Fila realmente vacía (relleno del final de la ventana) no cuenta como saltada.
                if ($this->filaVacia($r)) {
                    continue;
                }
                $saltadas++;
                continue;
            }

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

            $naturaleza = $this->naturalezaPorDescripcion($desc) ?? ($llave->naturaleza ?? null);

            $registros[] = [
                'codigo_obra'       => $obra,
                'mes'               => $mesFila,
                'anio'              => $anioFila,
                'cuenta'            => $llave->cuenta ?? '',
                'item'              => $this->texto($r[$col['item']] ?? null) ?? '',
                'tipo_inventario'   => $tipoInv,
                'codigo_movimiento' => $codMov,
                'tipo_movimiento'   => $desc,
                'naturaleza'        => $naturaleza,
                'tercero'           => $col['tercero'] !== null ? $this->texto($r[$col['tercero']] ?? null) : null,
                'cantidad'          => $col['cantidad'] !== null ? abs($this->num($r[$col['cantidad']] ?? null)) : null,
                'fecha'             => $col['fecha'] !== null ? $this->fecha($r[$col['fecha']] ?? null) : null,
                'numero_documento'  => $col['numero_documento'] !== null ? $this->texto($r[$col['numero_documento']] ?? null) : null,
                'costo'             => abs($this->num($r[$col['costo']] ?? null)),
                'created_at'        => $ahora,
                'updated_at'        => $ahora,
            ];

            if (count($registros) >= self::LOTE_INSERT) {
                DB::table('items_distribucion')->insert($registros);
                $insertadas += count($registros);
                $registros = [];
            }
        }

        if ($registros) {
            DB::table('items_distribucion')->insert($registros);
            $insertadas += count($registros);
        }

        return [
            'insertadas' => $insertadas,
            'contadores' => ['saltadas' => $saltadas, 'traslados' => $traslados, 'sin_llave' => $sinLlave],
        ];
    }

    public function resumen(int $mes, int $anio, array $meta): array
    {
        $periodos = $meta['periodos'] ?? [[$anio, $mes]];
        $cont     = $meta['contadores'] ?? [];

        $n = 0;
        foreach ($periodos as [$a, $m]) {
            $n += ItemDistribucion::where('anio', $a)->where('mes', $m)->count();
        }
        $listaPeriodos = implode(', ', array_map(fn ($p) => sprintf('%02d/%d', $p[1], $p[0]), $periodos));

        $msg = "Se cargaron {$n} ítems ({$listaPeriodos}).";
        if (($cont['saltadas'] ?? 0) > 0)  $msg .= " Se saltaron {$cont['saltadas']} filas incompletas.";
        if (($cont['traslados'] ?? 0) > 0) $msg .= " Se omitieron {$cont['traslados']} traslados (se manejan como reasignación).";

        $warning = null;
        $sinLlave = $cont['sin_llave'] ?? [];
        $sinN = array_sum(array_column($sinLlave, 'n'));
        if ($sinN > 0) {
            $detalle = collect($sinLlave)->sortByDesc('n')->take(15)
                ->map(fn ($s) => "tipo {$s['tipo']} · motivo {$s['codigo']} ({$s['n']})")->implode(', ');
            $extra = count($sinLlave) > 15 ? ' …' : '';
            $warning = "{$sinN} ítems sin cuenta en la llave (no cruzaron): {$detalle}{$extra}. ".
                'Revisa el maestro "Llave de cuentas por ítem".';
        }

        return ['mensaje' => $msg, 'warning' => $warning];
    }

    public function hoja(array $meta): ?string
    {
        return $meta['hoja'] ?? self::HOJA;
    }

    public function filaEncabezado(array $meta): int
    {
        return (int) ($meta['fila_encabezado'] ?? 1);
    }

    public function letras(array $meta): ?array
    {
        $letras = $meta['letras'] ?? null;
        if (empty($letras)) {
            return null;
        }
        return array_fill_keys($letras, true);
    }

    // ═══════════════════ Mapeo de columnas y helpers (movidos del controlador) ═══════════════════

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
                $esNombre = str_contains($h, 'NOMBRE') || str_contains($h, 'DESCRIP') || str_contains($h, 'DETALLE');
                if (str_contains($h, 'PERIODO')) {
                    $found['periodo'] = $j;
                } elseif (str_contains($h, 'UNIDAD') && str_contains($h, 'NEGOCIO') && !$esNombre) {
                    if ($found['codigo_obra'] === null || $h === 'UNIDAD DE NEGOCIO') $found['codigo_obra'] = $j;
                } elseif (str_contains($h, 'TIPO') && str_contains($h, 'INVENTARIO') && !$esNombre) {
                    if ($found['tipo_inventario'] === null || $h === 'TIPO DE INVENTARIO') $found['tipo_inventario'] = $j;
                } elseif (str_contains($h, 'MOTIVO')) {
                    $found[str_contains($h, 'DESC') ? 'tipo_movimiento' : 'codigo_movimiento'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'ITEM')) {
                    $found['item'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'TERCERO')) {
                    $found['tercero'] = $j;
                } elseif (str_contains($h, 'CANTIDAD') && str_contains($h, 'NET') && str_contains($h, '1')) {
                    $found['cantidad'] = $j;
                } elseif (str_contains($h, 'FECHA')) {
                    $found['fecha'] = $j;
                } elseif (str_contains($h, 'NUMERO') && str_contains($h, 'DOCUMENTO')) {
                    $found['numero_documento'] = $j;
                } elseif (str_contains($h, 'COSTO') && str_contains($h, 'PROM') && str_contains($h, 'NET')) {
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

    private function filaVacia(array $r): bool
    {
        foreach ($r as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
    }

    private function naturalezaPorDescripcion(?string $desc): ?string
    {
        $d = $this->norm((string) $desc);
        if ($d === '') return null;
        if (str_contains($d, 'REINTEGRO') || str_contains($d, 'ENTRADA')) return 'Crédito';
        if (str_contains($d, 'SALIDA')) return 'Débito';
        return null;
    }

    private function esTraslado(?string $desc): bool
    {
        return str_contains($this->norm((string) $desc), 'TRASLADO');
    }

    private function codigoObra($v): ?string
    {
        $t = trim((string) $v);
        if ($t === '') return null;
        $t = preg_replace('/^ct\s+/i', '', $t);
        $t = preg_replace('/\s+/', '', $t);
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
