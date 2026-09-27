<?php

namespace App\Support\Lotes;

use App\Models\RegistroFinanciero;

/**
 * Candado anti-duplicados DENTRO de una misma carga (el mes se reemplaza al inicio, así que
 * todo lo que hay en registro_financieros para ese mes/anio pertenece a esta carga). Filtra un
 * lote de filas descartando las que repiten la huella dedup_hash, ya sea dentro del mismo lote
 * o contra lo ya insertado en esta carga. NO borra nada.
 */
trait DeduplicaRegistros
{
    /**
     * @param  array<int, array<string,mixed>>  $rows  filas a insertar (con 'dedup_hash')
     * @return array{rows: array<int, array<string,mixed>>, omitidas: int}
     */
    protected function filtrarDuplicados(array $rows, int $mes, int $anio): array
    {
        if (empty($rows)) {
            return ['rows' => [], 'omitidas' => 0];
        }

        $omitidas   = 0;
        $enLote     = [];   // dedup dentro del mismo lote
        $candidatos = [];
        foreach ($rows as $r) {
            $h = $r['dedup_hash'] ?? null;
            if ($h === null || $h === '') {
                $candidatos[] = $r;            // sin huella: no se deduplica
                continue;
            }
            if (isset($enLote[$h])) {
                $omitidas++;
                continue;
            }
            $enLote[$h] = true;
            $candidatos[] = $r;
        }

        // Huellas ya presentes en esta carga (lotes anteriores del mismo mes/anio).
        $hashes = array_keys($enLote);
        $existentes = empty($hashes)
            ? []
            : array_flip(
                RegistroFinanciero::where('mes', $mes)->where('anio', $anio)
                    ->whereIn('dedup_hash', $hashes)->pluck('dedup_hash')->all()
            );

        $keep = [];
        foreach ($candidatos as $r) {
            $h = $r['dedup_hash'] ?? null;
            if ($h !== null && $h !== '' && isset($existentes[$h])) {
                $omitidas++;
                continue;
            }
            $keep[] = $r;
        }

        return ['rows' => $keep, 'omitidas' => $omitidas];
    }
}
