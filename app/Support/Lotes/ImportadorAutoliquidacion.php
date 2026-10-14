<?php

namespace App\Support\Lotes;

use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importa la planilla de autoliquidación (PILA) por lotes, reutilizando el mapeo por nombre
 * de columna de AutoliquidacionImport (una sola fuente de la lógica de negocio).
 */
class ImportadorAutoliquidacion implements ImportadorLotes
{
    use InsertaLotes;

    private const LOTE_INSERT = 500;

    public function tipo(): string
    {
        return 'autoliquidacion';
    }

    public function preparar(string $rutaAbsoluta, array $contexto = []): array
    {
        $lector = new LectorExcel();
        $info   = $lector->infoHoja($rutaAbsoluta, null);   // primera hoja
        $hoja   = $info['nombre'];

        // Encabezado (fila 1) + primeras filas para reconocer columnas y período.
        $hasta   = min($info['total_filas'], 2001);
        $muestra = $lector->leerVentana($rutaAbsoluta, $hoja, 1, max(1, $hasta), null, $info['ultima_columna']);

        $mapa = AutoliquidacionImport::mapaColumnas($muestra[0] ?? []);
        if (! isset($mapa['empleado'], $mapa['aporte_empresa'], $mapa['fecha'])) {
            throw new \RuntimeException(
                'El archivo no tiene el formato de la planilla de autoliquidación (PILA): faltan las '.
                'columnas "Empleado", "Aporte empresa" y/o "Fecha". Revisa que sea el reporte correcto.'
            );
        }

        $periodo = $this->periodoDesdeFilas($muestra, $mapa);
        if ($periodo === null) {
            throw new \RuntimeException(
                'No pude leer el período de la columna "Fecha". Revisa que traiga fechas válidas (ej. 2026-08-31).'
            );
        }
        [$mes, $anio] = $periodo;

        // Reemplazo del período: borra lo anterior.
        AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)->delete();

        return [
            'mes'  => $mes,
            'anio' => $anio,
            'meta' => ['hoja' => $hoja, 'fila_encabezado' => 1, 'mapa' => $mapa],
        ];
    }

    public function procesarFilas(array $filas, array $meta, int $mes, int $anio): array
    {
        $mapa = array_map('intval', $meta['mapa'] ?? []);
        $imp  = new AutoliquidacionImport($mes, $anio, $mapa);
        $ahora = now();
        $base  = (int) ($meta['fila_base'] ?? 0);

        $buffer = [];
        $bufferFilas = [];
        $insertadas = 0;
        foreach ($filas as $i => $row) {
            $num = $base + $i;
            try {
                $datos = $imp->aFila(array_values($row));
            } catch (\Throwable $e) {
                $this->fallaDeFila($num, $row, $e);
            }
            if ($datos === null) {
                continue;
            }
            $datos['created_at'] = $ahora;
            $datos['updated_at'] = $ahora;
            $buffer[] = $datos;
            $bufferFilas[] = $num;

            if (count($buffer) >= self::LOTE_INSERT) {
                $this->insertarLoteSeguro('autoliquidacion_aportes', $buffer, $bufferFilas);
                $insertadas += count($buffer);
                $buffer = [];
                $bufferFilas = [];
            }
        }
        if ($buffer) {
            $this->insertarLoteSeguro('autoliquidacion_aportes', $buffer, $bufferFilas);
            $insertadas += count($buffer);
        }

        return ['insertadas' => $insertadas];
    }

    public function resumen(int $mes, int $anio, array $meta): array
    {
        $base       = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio);
        $nFilas     = (clone $base)->count();
        $colPersona = Schema::hasColumn('autoliquidacion_aportes', 'empleado')
            ? DB::raw("COALESCE(NULLIF(empleado, ''), cedula)")
            : 'cedula';
        $personas = (clone $base)->distinct()->count($colPersona);
        $empresa  = (float) (clone $base)->sum('aporte_empresa');
        $totalFmt = '$'.number_format($empresa, 0, ',', '.');

        return [
            'mensaje' => "Planilla {$mes}/{$anio}: {$nFilas} filas, {$personas} personas, aporte empresa {$totalFmt}.",
        ];
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
        return null; // la planilla PILA es angosta (13–27 columnas)
    }

    /**
     * Primer par [mes, anio] legible de la columna Fecha en la muestra; null si ninguna sirve.
     *
     * @param  array<int, array<int, mixed>>  $filas
     * @param  array<string, int>  $mapa
     */
    private function periodoDesdeFilas(array $filas, array $mapa): ?array
    {
        $col = $mapa['fecha'] ?? null;
        if ($col === null) {
            return null;
        }
        foreach ($filas as $i => $fila) {
            if ($i === 0) {
                continue; // encabezado
            }
            $fecha = AutoliquidacionImport::parsearFecha($fila[$col] ?? null);
            if ($fecha) {
                return [(int) $fecha->month, (int) $fecha->year];
            }
        }

        return null;
    }
}
