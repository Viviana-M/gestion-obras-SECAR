<?php

namespace App\Support\Lotes;

use App\Imports\Financiero\MovimientoBiableImport;
use App\Models\RegistroFinanciero;
use App\Models\SaldoBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa el cierre contable (BIABLE) por lotes, reutilizando el mapeo de
 * MovimientoBiableImport (paraRegistro / paraSaldo) para no duplicar la lógica de negocio.
 * El período (mes/anio) lo fija el formulario, no el archivo.
 */
class ImportadorCierre implements ImportadorLotes
{
    use InsertaLotes;

    private const LOTE_INSERT = 500;

    public function tipo(): string
    {
        return 'cierre';
    }

    public function preparar(string $rutaAbsoluta, array $contexto = []): array
    {
        $mes  = (int) ($contexto['mes'] ?? 0);
        $anio = (int) ($contexto['anio'] ?? 0);
        if ($mes < 1 || $mes > 12 || $anio < 2000) {
            throw new \RuntimeException('El mes o el año del cierre no son válidos.');
        }

        $lector = new LectorExcel();
        $info   = $lector->infoHoja($rutaAbsoluta, null);   // primera hoja
        $hoja   = $info['nombre'];

        // Encabezado (fila 1): se mapea cada título a su "slug" (igual que Maatwebsite\Excel con el
        // formateador 'slug' = Str::slug($h,'_')) → offset de columna. Así paraRegistro/paraSaldo
        // (que resuelven las columnas por nombre vía MovimientoBiableImport::CAMPOS) funcionan sin cambios.
        $cabecera = $lector->leerVentana($rutaAbsoluta, $hoja, 1, 1, null, $info['ultima_columna'])[0] ?? [];
        $mapaSlug = [];
        foreach ($cabecera as $offset => $titulo) {
            $slug = Str::slug((string) $titulo, '_');
            if ($slug !== '' && ! isset($mapaSlug[$slug])) {
                $mapaSlug[$slug] = (int) $offset;
            }
        }

        // VALIDACIÓN POR NOMBRE: el mapeo del importador (MovimientoBiableImport::CAMPOS) es la única
        // fuente de verdad. Si falta alguna columna OBLIGATORIA, se rechaza la carga ANTES de borrar
        // el período, para no dejar el mes vacío por un archivo mal armado.
        $faltan = MovimientoBiableImport::encabezadosFaltantes(array_values($cabecera));
        if (! empty($faltan)) {
            throw new \RuntimeException(
                'El archivo no tiene las columnas obligatorias: '.implode(', ', $faltan).
                '. El sistema reconoce las columnas por su nombre; revisa el encabezado del BIABLE y vuelve a intentarlo.'
            );
        }

        // CANDADO ANTI-MES-EQUIVOCADO: el período lo fija el formulario, no el archivo. Si el archivo
        // corresponde a OTRO mes (según su columna 'periodo' AAAAMM, o la fecha del documento), se
        // rechaza ANTES de borrar nada, para no sobrescribir un mes con los datos de otro por un
        // selector mal puesto (causa de pérdidas de datos en producción).
        $this->verificarPeriodoDelArchivo($lector, $rutaAbsoluta, $hoja, $info, $mapaSlug, $mes, $anio);

        // Reemplazo del período: borra SOLO lo cargado desde BIABLE (origen='biable'); nunca los
        // movimientos generados por el sistema al aplicar planos (reverso_plano/distribucion_plano).
        RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->where('origen', 'biable')->delete();
        SaldoBalance::where('mes', $mes)->where('anio', $anio)->delete();

        return [
            'mes'  => $mes,
            'anio' => $anio,
            'meta' => ['hoja' => $hoja, 'fila_encabezado' => 1, 'mapa_slug' => $mapaSlug],
        ];
    }

    /**
     * Rechaza la carga si el período DENTRO del archivo no coincide con el mes/año elegidos en el
     * formulario. Mira una muestra de filas: primero la columna 'periodo' (AAAAMM, ignorando los
     * ajustes de cierre que terminan en '13'); si no hay, cae a la fecha del documento (AAAAMMDD).
     * Si el archivo no trae ninguna de las dos o no se puede determinar, NO bloquea (no hay con qué).
     */
    private function verificarPeriodoDelArchivo(LectorExcel $lector, string $ruta, ?string $hoja, array $info, array $mapaSlug, int $mes, int $anio): void
    {
        $offPeriodo = $mapaSlug['periodo'] ?? null;
        $offFecha   = null;
        foreach (['fecha_del_documento', 'fecha_documento', 'fecha_docto', 'fecha_dcto', 'fecha_doc', 'fecha'] as $a) {
            if (isset($mapaSlug[$a])) { $offFecha = $mapaSlug[$a]; break; }
        }
        if ($offPeriodo === null && $offFecha === null) {
            return; // el archivo no trae período ni fecha: no se puede validar.
        }

        $total = (int) ($info['total_filas'] ?? 0);
        $desde = 2;                               // fila 2 en adelante (1 es el encabezado)
        $hasta = min($total, $desde + 499);       // muestra de hasta 500 filas
        if ($hasta < $desde) {
            return; // archivo sin filas de datos: se tratará como "vacío" más adelante.
        }
        $filas = $lector->leerVentana($ruta, $hoja, $desde, $hasta, null, $info['ultima_columna'] ?? 'A');

        $conteo = [];   // 'AAAAMM' => cuántas filas
        foreach ($filas as $row) {
            $p = $offPeriodo !== null ? $this->periodoDesdeColumna($row[$offPeriodo] ?? null) : null;
            if ($p === null && $offFecha !== null) {
                $p = $this->periodoDesdeFecha($row[$offFecha] ?? null);
            }
            if ($p !== null) {
                $conteo[$p] = ($conteo[$p] ?? 0) + 1;
            }
        }
        if (empty($conteo)) {
            return; // no se pudo determinar el período del archivo: no se bloquea.
        }

        arsort($conteo);
        $periodoArchivo = (string) array_key_first($conteo);   // período dominante en la muestra
        $periodoSel     = sprintf('%04d%02d', $anio, $mes);

        if ($periodoArchivo !== $periodoSel) {
            $fmt = fn (string $p) => substr($p, 4, 2).'/'.substr($p, 0, 4);
            throw new \RuntimeException(
                "El archivo parece ser del período {$fmt($periodoArchivo)}, pero en el formulario ".
                "seleccionaste {$fmt($periodoSel)}. No se cargó ni se borró nada. ".
                'Verifica el mes y el año seleccionados y vuelve a intentarlo.'
            );
        }
    }

    /** Normaliza un valor de la columna 'periodo' a 'AAAAMM'; descarta el período 13 (ajuste de cierre). */
    private function periodoDesdeColumna($valor): ?string
    {
        $v = preg_replace('/\.0+$/', '', trim((string) $valor));   // 202609.0 → 202609
        if (preg_match('/^(\d{4})(\d{2})$/', (string) $v, $m)) {
            return $m[2] === '13' ? null : $m[1].$m[2];
        }

        return null;
    }

    /** Extrae 'AAAAMM' de una fecha de documento en formato AAAAMMDD (lo que trae el BIABLE). */
    private function periodoDesdeFecha($valor): ?string
    {
        $v = preg_replace('/\.0+$/', '', trim((string) $valor));
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string) $v, $m) && $m[2] >= '01' && $m[2] <= '12') {
            return $m[1].$m[2];
        }

        return null;
    }

    public function procesarFilas(array $filas, array $meta, int $mes, int $anio): array
    {
        $mapaSlug = array_map('intval', $meta['mapa_slug'] ?? []);
        $imp      = new MovimientoBiableImport($mes, $anio);
        $ahora    = now();
        $base     = (int) ($meta['fila_base'] ?? 0);

        $registros = [];      $regFilas = [];
        $saldos    = [];      $salFilas = [];
        $insertados = 0;

        foreach ($filas as $i => $row) {
            $num = $base + $i;
            // Fila cruda (por offset) → fila asociativa por slug de encabezado.
            $assoc = [];
            foreach ($mapaSlug as $slug => $offset) {
                $assoc[$slug] = $row[$offset] ?? null;
            }

            try {
                $r = $imp->paraRegistro($assoc, $ahora);
                $s = $imp->paraSaldo($assoc);
            } catch (\Throwable $e) {
                $this->fallaDeFila($num, $row, $e);
            }
            if ($r) { $registros[] = $r; $regFilas[] = $num; }
            if ($s) { $saldos[] = $s;    $salFilas[] = $num; }

            if (count($registros) >= self::LOTE_INSERT) {
                $insertados += $this->insertarRegistros($registros, $regFilas);
                $registros = []; $regFilas = [];
            }
            if (count($saldos) >= self::LOTE_INSERT) {
                $this->insertarLoteSeguro('saldos_balance', $saldos, $salFilas);
                $saldos = []; $salFilas = [];
            }
        }

        if ($registros) {
            $insertados += $this->insertarRegistros($registros, $regFilas);
        }
        if ($saldos) {
            $this->insertarLoteSeguro('saldos_balance', $saldos, $salFilas);
        }

        return ['insertadas' => $insertados];
    }

    /**
     * Inserta TODAS las filas del lote, fiel 1:1: ya no se descarta ninguna por huella (eso
     * botaba reversiones legítimas de SECAR). La sobre-reversión se detecta y depura después, en
     * el reporte "Validación de sobre-reversión". Devuelve cuántas insertó.
     */
    private function insertarRegistros(array $registros, array $regFilas): int
    {
        if (empty($registros)) {
            return 0;
        }

        $this->insertarLoteSeguro('registro_financieros', $registros, $regFilas);

        return count($registros);
    }

    public function resumen(int $mes, int $anio, array $meta): array
    {
        // Ya insertados todos los lotes de BIABLE: deduplicar contra los ajustes de plano aplicados
        // (si la recarga ya los trae contabilizados, se retira el 'ajuste_plano' duplicado).
        MovimientoBiableImport::deduplicarAjustes($mes, $anio);

        $n = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->count();

        return ['mensaje' => "Cierre {$mes}/{$anio}: {$n} registros contables cargados."];
    }

    public function hoja(array $meta): ?string
    {
        return $meta['hoja'] ?? null;
    }

    public function filaEncabezado(array $meta): int
    {
        return (int) ($meta['fila_encabezado'] ?? 1);
    }

    public function letras(array $meta): ?array
    {
        return null; // el BIABLE del cierre es angosto (~19 columnas)
    }
}
