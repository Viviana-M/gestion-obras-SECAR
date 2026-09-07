<?php

namespace App\Imports\Contable;

use App\Models\AutoliquidacionAporte;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStartRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importa la planilla de autoliquidación (PILA) reconociendo las columnas POR SU NOMBRE, no por
 * su posición. Así funciona con dos exportes distintos del ERP que traen los mismos datos:
 *
 *  - El PLANO estándar (13 columnas), y
 *  - El movimiento contable de la PILA (SIESA, ~27 columnas), donde el detalle por empleado va
 *    en columnas aparte (Empleado, Aporte empresa, Descripción Codigo PILA) junto al fondo/EPS
 *    (Id. Tercero Mov / Razon Social) de cada línea de aporte.
 *
 * Columnas que se leen (por nombre, sin importar el orden):
 *   ID Cuenta                → id_cuenta
 *   Cuenta contable          → cuenta_contable
 *   Id. Tercero Mov          → cedula          (NIT del fondo/EPS de la línea)
 *   Razon Social             → razon_social    (nombre del fondo/EPS)
 *   Id. U.N. Mov             → un_codigo
 *   Fecha                    → (período mes/anio)
 *   Descripción UN           → un_descripcion
 *   Descripción Codigo PILA  → concepto_pila
 *   Empleado                 → empleado        (cédula de la persona)
 *   Nombre del empl          → empleado_nombre
 *   Aporte del empl          → aporte_empleado
 *   Aporte empresa           → aporte_empresa
 *   Real Descontado          → real_descontado
 *
 * El período (mes/anio) se fija por fuera (se toma de la columna Fecha) y se aplica a todas las
 * filas. Solo se importan las filas con "Empleado" (cada línea es de una persona); así se
 * descartan renglones de totales al pie y contrapartidas sin empleado.
 */
class AutoliquidacionImport implements ToModel, WithChunkReading, WithBatchInserts, WithStartRow, WithMultipleSheets
{
    /**
     * @param  array<string,int>  $mapa  campo canónico → índice de columna (0-based)
     */
    public function __construct(
        private int $mes,
        private int $anio,
        private array $mapa,
    ) {}

    /**
     * Importar SOLO la primera hoja. Sin esto, un .xlsx con varias hojas (p. ej. una copia
     * o un resumen) se importaría en todas y duplicaría los aportes.
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    public function startRow(): int
    {
        return 2; // saltar el encabezado
    }

    public function chunkSize(): int
    {
        // Chunks moderados: equilibran memoria (planillas de miles de filas no deben disparar la
        // memoria del servidor) y velocidad (cada chunk reabre el archivo).
        return 1000;
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function model(array $row)
    {
        // Este módulo trata del APORTE EMPRESA (lo que va a la cuenta 14 y se reclasifica a la 61).
        // Solo se importan las líneas de aporte de una persona: deben traer Empleado y un aporte
        // empresa distinto de cero. Así se descartan el renglón de totales al pie (sin empleado) y
        // las contrapartidas/puente (mismo empleado y concepto, pero aporte empresa en 0), que si
        // no inflarían el conteo de filas y personas.
        $empleado = $this->celda($row, 'empleado');
        $aporteEmpresa = $this->num($this->valor($row, 'aporte_empresa'));
        if ($empleado === null || abs($aporteEmpresa) < 0.005) {
            return null;
        }

        return new AutoliquidacionAporte([
            'id_cuenta'        => $this->celda($row, 'id_cuenta'),
            'cuenta_contable'  => $this->celda($row, 'cuenta_contable'),
            'cedula'           => $this->celda($row, 'cedula'),          // NIT del fondo/EPS
            'razon_social'     => $this->celda($row, 'razon_social'),    // nombre del fondo/EPS
            'un_codigo'        => $this->celda($row, 'un_codigo'),
            'fecha'            => self::parsearFecha($this->valor($row, 'fecha'))?->format('Y-m-d'),
            'un_descripcion'   => $this->celda($row, 'un_descripcion'),
            'concepto_pila'    => $this->celda($row, 'concepto_pila'),
            'empleado'         => $empleado,                             // cédula del empleado
            'empleado_nombre'  => $this->celda($row, 'empleado_nombre'), // nombre del empleado
            'aporte_empleado'  => $this->num($this->valor($row, 'aporte_empleado')),
            'aporte_empresa'   => $aporteEmpresa,
            'real_descontado'  => $this->num($this->valor($row, 'real_descontado')),
            'mes'              => $this->mes,
            'anio'            => $this->anio,
        ]);
    }

    /**
     * Construye el mapa de columnas (campo canónico → índice) a partir del encabezado, sin
     * depender del orden ni del número de columnas. Devuelve solo los campos encontrados.
     *
     * @param  array<int, mixed>  $encabezado
     * @return array<string, int>
     */
    public static function mapaColumnas(array $encabezado): array
    {
        $mapa = [];
        foreach ($encabezado as $i => $titulo) {
            $h = self::normalizar($titulo);
            if ($h === '') {
                continue;
            }
            $campo = self::clasificar($h);
            // Primera columna que coincide gana (no sobrescribir).
            if ($campo !== null && ! isset($mapa[$campo])) {
                $mapa[$campo] = (int) $i;
            }
        }

        return $mapa;
    }

    /**
     * Asigna un encabezado normalizado a un campo canónico. El orden de las reglas evita
     * colisiones (p. ej. "Descripción UN" vs "Id. U.N. Mov", o los tres "…empl").
     */
    private static function clasificar(string $h): ?string
    {
        $tiene = fn (string $x) => str_contains($h, $x);

        return match (true) {
            $h === 'id cuenta'                     => 'id_cuenta',
            $tiene('cuenta') && $tiene('contable') => 'cuenta_contable',
            $tiene('tercero')                      => 'cedula',
            $tiene('razon') && $tiene('social')    => 'razon_social',
            $tiene('fecha')                        => 'fecha',
            $tiene('descripcion') && $tiene('pila') => 'concepto_pila',  // Descripción Codigo PILA (texto, no el código)
            $tiene('descripcion') && $tiene('un')  => 'un_descripcion',  // Descripción UN
            $tiene('un') && $tiene('mov')          => 'un_codigo',       // Id. U.N. Mov
            $tiene('aporte') && $tiene('empresa')  => 'aporte_empresa',
            $tiene('aporte') && $tiene('empl')     => 'aporte_empleado', // Aporte del empl
            $tiene('nombre') && $tiene('empl')     => 'empleado_nombre', // Nombre del empl
            $h === 'empleado'                      => 'empleado',
            $tiene('real') && $tiene('descontado') => 'real_descontado',
            default                                => null,
        };
    }

    /**
     * Normaliza un encabezado: minúsculas, sin acentos, sin puntos, y con los separadores
     * colapsados a un solo espacio. "Id. U.N. Mov" → "id un mov"; "Descripción Codigo PILA" →
     * "descripcion codigo pila".
     */
    private static function normalizar($s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $s = str_replace('.', '', $s);              // "u.n." → "un"
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s); // resto de separadores → espacio
        return trim($s);
    }

    /** Valor crudo de un campo (o null si la columna no existe en este archivo). */
    private function valor(array $row, string $campo)
    {
        $i = $this->mapa[$campo] ?? null;
        return $i === null ? null : ($row[$i] ?? null);
    }

    /** Valor de texto de un campo, o null si viene vacío. */
    private function celda(array $row, string $campo): ?string
    {
        return $this->texto($this->valor($row, $campo));
    }

    /**
     * Interpreta la columna Fecha: serial de Excel (número), ISO "2026-04-30" o
     * "dd/mm/aaaa". Devuelve un Carbon o null si no se puede leer.
     */
    public static function parsearFecha($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v));
            } catch (\Throwable $e) {
                return null;
            }
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s)) {
            try {
                return Carbon::createFromFormat('d/m/Y', $s)->startOfDay();
            } catch (\Throwable $e) {
                // sigue al parseo genérico
            }
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function texto($v): ?string
    {
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }

    private function num($v): float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }
        // Texto tipo "1.234,56" o "1,234.56": quitar separadores de miles.
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace([' ', '$'], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            // el último separador es el decimal
            $s = (strrpos($s, ',') > strrpos($s, '.'))
                ? str_replace('.', '', $s)               // 1.234,56
                : str_replace(',', '', $s);              // 1,234.56
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }
}
