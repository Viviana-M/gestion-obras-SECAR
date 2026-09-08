<?php

namespace App\Imports\Financiero;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

/**
 * IMPORTADOR UNIFICADO DE BIABLE — UNA SOLA PASADA
 *
 * Reemplaza a RegistroFinancieroImport + BalanceImport, que leían el mismo Excel
 * DOS VECES completo (cada una descartando la mayoría de las filas).
 *
 * Qué cambia respecto de los dos anteriores:
 *
 *   1) UNA sola lectura del archivo en vez de dos.
 *   2) INSERTs crudos por lotes (DB::table()->insert) en vez de instanciar un modelo
 *      Eloquent por fila (con sus eventos, casts y $fillable).
 *   3) chunkSize 2000 en vez de 500: WithChunkReading REABRE y REPARSEA el Excel en
 *      cada chunk, así que menos chunks = muchas menos aperturas del archivo.
 *
 * La lógica de negocio es IDÉNTICA a la de los dos importadores originales.
 * Ojo con un detalle que se conserva a propósito: las cuentas 1420 entran en las DOS
 * tablas (en registro_financieros como "Costos por aplicar" y en saldos_balance como
 * clase 1). Eso ya era así y no se toca.
 */
class MovimientoBiableImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected int $mes;
    protected int $anio;

    /** Filas por INSERT. 500 × ~19 columnas queda muy por debajo del límite de MySQL. */
    private const LOTE = 500;

    /** Prefijos de obra válidos (mismos que RegistroFinancieroImport). */
    private array $prefijosValidos = ['O', 'GI', 'MOA', 'MOB', 'MOC', 'MO', 'C', 'R', 'GM', 'MTO', 'INS'];

    private array $bufferRegistros = [];
    private array $bufferSaldos    = [];

    /** Contadores, para poder informar al terminar. */
    public int $filasLeidas     = 0;
    public int $insertRegistros = 0;
    public int $insertSaldos    = 0;

    public function __construct($mes, $anio)
    {
        $this->mes  = (int) $mes;
        $this->anio = (int) $anio;
    }

    /**
     * Chunks grandes: cada chunk implica reabrir el Excel, así que conviene que sean pocos.
     * Si el servidor tuviera poca RAM, bájalo a 1000.
     */
    public function chunkSize(): int
    {
        return 2000;
    }

    public function collection(Collection $filas): void
    {
        // Sin log de queries: en importaciones largas se come la memoria.
        DB::connection()->disableQueryLog();

        $ahora = now();

        foreach ($filas as $fila) {
            $row = $fila->toArray();
            $this->filasLeidas++;

            // Una sola lectura de la fila, dos destinos posibles.
            if ($r = $this->paraRegistro($row, $ahora)) {
                $this->bufferRegistros[] = $r;
            }
            if ($s = $this->paraSaldo($row)) {
                $this->bufferSaldos[] = $s;
            }

            if (count($this->bufferRegistros) >= self::LOTE) $this->volcarRegistros();
            if (count($this->bufferSaldos)    >= self::LOTE) $this->volcarSaldos();
        }

        // Al cerrar el chunk vaciamos los buffers: así la memoria no crece.
        $this->volcarRegistros();
        $this->volcarSaldos();
    }

    // ═══════════════════ Destino 1: registro_financieros ═══════════════════

    private function paraRegistro(array $row, $ahora): ?array
    {
        $movto = $this->limpiarNumero($row['movto_libro2'] ?? 0);
        if ($movto == 0) {
            $debito  = $this->limpiarNumero($row['debitos']  ?? 0);
            $credito = $this->limpiarNumero($row['creditos'] ?? 0);
            $movto   = $debito - $credito;
        }
        if ($movto == 0) return null;

        $periodo = trim((string) ($row['periodo'] ?? ''));
        if (str_ends_with($periodo, '13')) return null;

        $unidad = trim((string) ($row['unidad_de_negocio'] ?? ''));
        if (!$this->tienePrefijoValido($unidad)) return null;

        $nombreUnidad = trim((string) ($row['nombre_unidad_de_negocio'] ?? ''));
        $unidadUnificada = $unidad && $nombreUnidad
            ? $unidad . ' - ' . $nombreUnidad
            : ($unidad ?: $nombreUnidad);
        if (str_contains($unidadUnificada, 'COM00099')) return null;

        $cuenta      = trim((string) ($row['cuenta'] ?? ''));
        $cuentaMayor = $this->clasificarCuenta($cuenta);

        if (in_array($cuentaMayor, ['Activo', 'Pasivo', 'Patrimonio', 'No clasificado'], true)) {
            return null;
        }

        $signo = (str_starts_with($cuenta, '1420') || str_starts_with($cuenta, '6130')) ? -1 : 1;

        $estadoER = (str_starts_with($cuenta, '14') ||
                     str_starts_with($cuenta, '61') ||
                     str_starts_with($cuenta, '4'))
            ? $movto * -1
            : $movto;

        return [
            'codigo_proyecto'  => $unidad,
            'nombre_proyecto'  => $nombreUnidad,
            'cuenta_contable'  => $cuenta,
            'descripcion'      => trim((string) ($row['nombre_auxiliar'] ?? '')),
            // Tercero del MOVIMIENTO (col "Tercero"/"Nombre Tercero"), que en las líneas de nómina es
            // el EMPLEADO. NO el "Tercero Docto" (tercero del documento), que en nómina es SECAR y
            // hacía que el cruce por cédula de MO Apoyo no encontrara a nadie. Si el movimiento no
            // trae tercero, cae al del documento.
            'tercero_dcto'     => (trim((string) ($row['tercero'] ?? '')) ?: trim((string) ($row['tercero_docto'] ?? ''))) ?: null,
            'razon_social'     => (trim((string) ($row['nombre_tercero'] ?? '')) ?: trim((string) ($row['razon_social_docto'] ?? ''))) ?: null,
            'valor_debito'     => $this->limpiarNumero($row['debitos']  ?? 0),
            'valor_credito'    => $this->limpiarNumero($row['creditos'] ?? 0),
            'movto_libro2'     => $movto,
            'cuenta_mayor'     => $cuentaMayor,
            'signo_contable'   => $signo,
            'estado_er'        => $estadoER,
            'unidad_unificada' => $unidadUnificada,
            'periodo'          => $periodo,
            'mes'              => $this->mes,
            'anio'             => $this->anio,
            'origen'           => 'biable',
            'created_at'       => $ahora,
            'updated_at'       => $ahora,
        ];
    }

    // ═══════════════════ Destino 2: saldos_balance ═══════════════════

    private function paraSaldo(array $row): ?array
    {
        $cuenta = trim((string) ($row['cuenta'] ?? ''));
        if ($cuenta === '') return null;

        // Solo cuentas de balance: 1 activo, 2 pasivo, 3 patrimonio.
        $clase = substr($cuenta, 0, 1);
        if (!in_array($clase, ['1', '2', '3'], true)) return null;

        $debito  = $this->limpiarNumero($row['debitos']  ?? 0);
        $credito = $this->limpiarNumero($row['creditos'] ?? 0);
        $movto   = $this->limpiarNumero($row['movto_libro2'] ?? 0);
        if ($movto == 0) $movto = $debito - $credito;
        if ($debito == 0 && $credito == 0) return null;

        // A propósito NO se filtra por prefijo de obra: las cuentas globales
        // (pasivo / patrimonio) vienen sin proyecto y deben entrar igual.
        $unidad       = trim((string) ($row['unidad_de_negocio'] ?? ''));
        $nombreUnidad = trim((string) ($row['nombre_unidad_de_negocio'] ?? ''));

        return [
            'cuenta_contable' => $cuenta,
            'descripcion'     => trim((string) ($row['nombre_auxiliar'] ?? '')),
            'clase'           => $clase,
            'codigo_proyecto' => $unidad ?: null,
            'nombre_proyecto' => $nombreUnidad ?: null,
            'valor_debito'    => $debito,
            'valor_credito'   => $credito,
            'movto'           => $movto,
            'periodo'         => trim((string) ($row['periodo'] ?? '')),
            'mes'             => $this->mes,
            'anio'            => $this->anio,
            'origen'          => 'biable',
            // OJO: saldos_balance NO tiene created_at / updated_at.
            // Como el insert es crudo (DB::table), mandárselos revienta con QueryException.
        ];
    }

    // ═══════════════════ Volcado por lotes ═══════════════════

    private function volcarRegistros(): void
    {
        if (empty($this->bufferRegistros)) return;

        DB::table('registro_financieros')->insert($this->bufferRegistros);
        $this->insertRegistros += count($this->bufferRegistros);
        $this->bufferRegistros = [];
    }

    private function volcarSaldos(): void
    {
        if (empty($this->bufferSaldos)) return;

        DB::table('saldos_balance')->insert($this->bufferSaldos);
        $this->insertSaldos += count($this->bufferSaldos);
        $this->bufferSaldos = [];
    }

    // ═══════════════════ Helpers (idénticos a los originales) ═══════════════════

    private function tienePrefijoValido(string $unidad): bool
    {
        foreach ($this->prefijosValidos as $prefijo) {
            if (str_starts_with($unidad, $prefijo)) return true;
        }
        return false;
    }

    private function clasificarCuenta(string $cuenta): string
    {
        if (str_starts_with($cuenta, '1420')) return 'Costos por aplicar';
        if (str_starts_with($cuenta, '6'))    return 'Costos aplicados';
        if (str_starts_with($cuenta, '1'))    return 'Activo';
        if (str_starts_with($cuenta, '2'))    return 'Pasivo';
        if (str_starts_with($cuenta, '3'))    return 'Patrimonio';
        if (str_starts_with($cuenta, '4'))    return 'Ingreso';
        if (str_starts_with($cuenta, '5'))    return 'Gasto';
        if (str_starts_with($cuenta, '7'))    return 'Costos';
        return 'No clasificado';
    }

    private function limpiarNumero($valor): float
    {
        if (is_numeric($valor)) return floatval($valor);
        $valor = str_replace(['$', '.', ' '], '', (string) $valor);
        $valor = str_replace(',', '.', $valor);
        return is_numeric($valor) ? floatval($valor) : 0;
    }
}