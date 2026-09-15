<?php

namespace App\Support\Lotes;

use Illuminate\Support\Facades\DB;

/**
 * Motor genérico de importación POR LOTES. Orquesta, para CUALQUIER importador:
 *   analizar()          → reconoce el archivo, borra el período y cuenta las filas.
 *   procesarSiguiente() → lee y procesa la SIGUIENTE ventana de filas (un lote).
 *   correrCompleto()    → recorre todos los lotes en la misma petición (uso síncrono).
 *
 * El flujo web usa analizar()+procesarSiguiente() a través de peticiones AJAX (barra de
 * progreso). El flujo síncrono (formulario clásico y pruebas) usa correrCompleto().
 */
class MotorLotes
{
    public function __construct(private LectorExcel $lector = new LectorExcel()) {}

    /**
     * Reconoce el archivo, borra el período que reemplaza y cuenta las filas de datos.
     *
     * @param  array<string,mixed>  $contexto
     * @return array{mes:int,anio:int,total:int,meta:array<string,mixed>}
     */
    public function analizar(ImportadorLotes $imp, string $rutaRelativa, array $contexto = []): array
    {
        $abs  = storage_path('app/'.$rutaRelativa);
        $prep = $imp->preparar($abs, $contexto);          // valida + borra el período

        $meta         = $prep['meta'];
        $meta['ruta'] = $rutaRelativa;

        $info                   = $this->lector->infoHoja($abs, $imp->hoja($meta));
        $meta['ultima_columna'] = $info['ultima_columna'];

        $total = max(0, $info['total_filas'] - $imp->filaEncabezado($meta));

        return ['mes' => $prep['mes'], 'anio' => $prep['anio'], 'total' => $total, 'meta' => $meta];
    }

    /**
     * Procesa la siguiente ventana de filas. Actualiza y guarda el progreso. Si algo falla,
     * marca la carga en 'error' y relanza (para que el controlador responda).
     */
    public function procesarSiguiente(ImportadorLotes $imp, ProgresoCarga $carga, int $tam = 1000): void
    {
        if ($carga->getEstado() !== 'procesando') {
            return;
        }

        $meta  = $carga->getMetaLotes();
        $abs   = storage_path('app/'.($meta['ruta'] ?? ''));
        $total = $carga->getTotalFilas();
        $hechas = $carga->getFilasProcesadas();

        if ($hechas >= $total) {
            $this->finalizar($carga);
            $carga->guardar();
            return;
        }

        $fe    = $imp->filaEncabezado($meta);
        $desde = $fe + 1 + $hechas;
        $hasta = min($fe + $total, $desde + $tam - 1);

        DB::connection()->disableQueryLog();

        try {
            $filas = $this->lector->leerVentana(
                $abs, $imp->hoja($meta), $desde, $hasta, $imp->letras($meta), $meta['ultima_columna'] ?? 'A'
            );
            // Cada lote es atómico: si falla a mitad, no deja el bloque a medias.
            $res = DB::transaction(fn () => $imp->procesarFilas($filas, $meta, $carga->getMes(), $carga->getAnio()));
        } catch (\Throwable $e) {
            report($e);
            $carga->setEstado('error');
            $carga->setError($e->getMessage());
            $carga->guardar();
            throw $e;
        }

        $carga->setFilasInsertadas($carga->getFilasInsertadas() + (int) ($res['insertadas'] ?? 0));
        $carga->setFilasProcesadas(min($total, $hechas + ($hasta - $desde + 1)));

        if (! empty($res['contadores'])) {
            $meta['contadores'] = $this->acumular($meta['contadores'] ?? [], $res['contadores']);
            $carga->setMetaLotes($meta);
        }

        if ($carga->getFilasProcesadas() >= $total) {
            $this->finalizar($carga);
        }

        $carga->guardar();
    }

    /** Recorre todos los lotes en la misma petición (formulario clásico / pruebas). */
    public function correrCompleto(ImportadorLotes $imp, ProgresoCarga $carga, int $tam = 1000): void
    {
        $guardia = 0;
        while ($carga->getEstado() === 'procesando') {
            $this->procesarSiguiente($imp, $carga, $tam);
            if (++$guardia > 1_000_000) {
                break; // seguro anti-bucle
            }
        }
    }

    private function finalizar(ProgresoCarga $carga): void
    {
        $carga->setEstado('completado');
        $carga->setError(null);
    }

    /**
     * Suma contadores entre lotes: enteros se suman; arreglos asociativos se combinan
     * (sumando su clave numérica 'n' cuando existe, o recursivamente).
     *
     * @param  array<string,mixed>  $acc
     * @param  array<string,mixed>  $nuevo
     * @return array<string,mixed>
     */
    private function acumular(array $acc, array $nuevo): array
    {
        foreach ($nuevo as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $acc[$k] = ($acc[$k] ?? 0) + $v;
            } elseif (is_array($v)) {
                $acc[$k] = $this->acumular($acc[$k] ?? [], $v);
            } else {
                $acc[$k] = $v;
            }
        }
        return $acc;
    }
}
