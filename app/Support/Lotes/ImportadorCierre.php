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
    use DeduplicaRegistros;

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
        // (que leen $row['movto_libro2'], etc.) funcionan sin cambios.
        $cabecera = $lector->leerVentana($rutaAbsoluta, $hoja, 1, 1, null, $info['ultima_columna'])[0] ?? [];
        $mapaSlug = [];
        foreach ($cabecera as $offset => $titulo) {
            $slug = Str::slug((string) $titulo, '_');
            if ($slug !== '' && ! isset($mapaSlug[$slug])) {
                $mapaSlug[$slug] = (int) $offset;
            }
        }

        // Reemplazo del período: borra lo anterior (registros y saldos).
        RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->delete();
        SaldoBalance::where('mes', $mes)->where('anio', $anio)->delete();

        return [
            'mes'  => $mes,
            'anio' => $anio,
            'meta' => ['hoja' => $hoja, 'fila_encabezado' => 1, 'mapa_slug' => $mapaSlug],
        ];
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
        $duplicadas = 0;

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
                $insertados += $this->insertarRegistros($registros, $regFilas, $mes, $anio, $duplicadas);
                $registros = []; $regFilas = [];
            }
            if (count($saldos) >= self::LOTE_INSERT) {
                $this->insertarLoteSeguro('saldos_balance', $saldos, $salFilas);
                $saldos = []; $salFilas = [];
            }
        }

        if ($registros) {
            $insertados += $this->insertarRegistros($registros, $regFilas, $mes, $anio, $duplicadas);
        }
        if ($saldos) {
            $this->insertarLoteSeguro('saldos_balance', $saldos, $salFilas);
        }

        return ['insertadas' => $insertados, 'contadores' => ['duplicadas' => $duplicadas]];
    }

    /**
     * Aplica el candado anti-duplicados y luego inserta. Devuelve cuántas insertó y suma en
     * $duplicadas (por referencia) las omitidas.
     */
    private function insertarRegistros(array $registros, array $regFilas, int $mes, int $anio, int &$duplicadas): int
    {
        // Alinear filas de origen con las filas que sobreviven al filtro (por dedup_hash).
        $filtro = $this->filtrarDuplicados($registros, $mes, $anio);
        $duplicadas += $filtro['omitidas'];

        $rows = $filtro['rows'];
        if (empty($rows)) {
            return 0;
        }

        // Mapa hash → fila de origen (para reportar la fila culpable si falla el insert).
        $filaDe = [];
        foreach ($registros as $k => $r) {
            $filaDe[$r['dedup_hash'] ?? ('#'.$k)] = $regFilas[$k] ?? '?';
        }
        $filasOrigen = array_map(fn ($r) => $filaDe[$r['dedup_hash'] ?? ''] ?? '?', $rows);

        $this->insertarLoteSeguro('registro_financieros', $rows, $filasOrigen);

        return count($rows);
    }

    public function resumen(int $mes, int $anio, array $meta): array
    {
        $n = RegistroFinanciero::where('mes', $mes)->where('anio', $anio)->count();
        $dup = (int) ($meta['contadores']['duplicadas'] ?? 0);

        $mensaje = "Cierre {$mes}/{$anio}: {$n} registros contables cargados.";
        if ($dup > 0) {
            $mensaje .= " {$dup} filas duplicadas omitidas.";
        }

        return ['mensaje' => $mensaje];
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
