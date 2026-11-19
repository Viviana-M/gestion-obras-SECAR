<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Distribucion;

/**
 * Limpieza de una vez: por el bug de "borrador no se retoma", al guardar sin ?dist se creaban
 * borradores NUEVOS del mismo mes/departamento una y otra vez. Esto consolida esos duplicados:
 * por cada (mes, año, departamento) de tipo 'obras' con varios borradores ABIERTOS (estado
 * 'borrador', no reemplazados), conserva el más reciente (mayor versión, desempate por id) y marca
 * los demás como reemplazada = true. No toca los 'enviado' ni los de tipo 'areas'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grupos = Distribucion::where('tipo', 'obras')
            ->where('estado', 'borrador')
            ->where('reemplazada', false)
            ->orderByDesc('version')->orderByDesc('id')
            ->get()
            ->groupBy(fn ($d) => $d->mes.'|'.$d->anio.'|'.($d->departamento ?? ''));

        foreach ($grupos as $borradores) {
            if ($borradores->count() <= 1) {
                continue; // no hay duplicados en este período/departamento
            }
            // El primero (mayor versión / id) es el vigente; el resto se marcan como reemplazados.
            foreach ($borradores->slice(1) as $sobrante) {
                $sobrante->reemplazada = true;
                $sobrante->save();
            }
        }
    }

    public function down(): void
    {
        // No se revierte: no se puede saber con certeza cuáles estaban marcados antes de la limpieza.
    }
};
