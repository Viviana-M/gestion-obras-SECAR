<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Homologación de cuentas 14 → 61, CON VIGENCIA.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  IMPORTANTE — GLOBAL SCOPE "vigente"
 * ────────────────────────────────────────────────────────────────────────
 *  Por defecto, TODA consulta a este modelo devuelve solo las versiones
 *  vigentes hoy (vigente_hasta IS NULL). Eso hace que el código que ya
 *  existía —Homologacion::get()->keyBy('cuenta_14')— siga funcionando
 *  correctamente aunque una cuenta tenga varias versiones.
 *
 *  Para consultar el histórico o la versión de un período concreto hay que
 *  saltarse el scope explícitamente. Usa los helpers:
 *
 *      Homologacion::mapaEn(202603)      // mapa cuenta_14 => homologación de marzo 2026
 *      Homologacion::historial('14200105')  // todas las versiones de una cuenta
 *      Homologacion::sinFiltro()            // query builder sin el scope
 * ────────────────────────────────────────────────────────────────────────
 */
class Homologacion extends Model
{
    protected $table = 'homologaciones';

    /** Período de arranque: las homologaciones originales aplican "desde siempre". */
    public const PERIODO_INICIAL = 200001;

    protected $fillable = [
        'cuenta_14',
        'cuenta_61',
        'nombre',
        'estructura',
        'vigente_desde',
        'vigente_hasta',
        'version',
        'reemplaza_a',
        'motivo',
        'requiere_reclasificacion',
        'reclasificar_desde',
        'reclasificado_at',
        'reclasificado_por',
        'origen',
        'user_id',
    ];

    protected $casts = [
        'vigente_desde'            => 'integer',
        'vigente_hasta'            => 'integer',
        'version'                  => 'integer',
        'requiere_reclasificacion' => 'boolean',
        'reclasificar_desde'       => 'integer',
        'reclasificado_at'         => 'datetime',
    ];

    /**
     * Global scope: por defecto solo las versiones vigentes.
     * Esto protege a los 6 lectores que hacen keyBy('cuenta_14').
     */
    protected static function booted(): void
    {
        static::addGlobalScope('vigente', function (Builder $q) {
            $q->whereNull('homologaciones.vigente_hasta');
        });
    }

    /** Query sin el global scope (para histórico y consultas por período). */
    public static function sinFiltro(): Builder
    {
        return static::query()->withoutGlobalScope('vigente');
    }

    // ═══════════════════ Helpers de período (AAAAMM) ═══════════════════

    public static function periodo(int $anio, int $mes): int
    {
        return $anio * 100 + $mes;
    }

    public static function anioDe(int $periodo): int
    {
        return intdiv($periodo, 100);
    }

    public static function mesDe(int $periodo): int
    {
        return $periodo % 100;
    }

    public static function siguientePeriodo(int $periodo): int
    {
        $a = self::anioDe($periodo);
        $m = self::mesDe($periodo) + 1;
        if ($m > 12) { $m = 1; $a++; }
        return self::periodo($a, $m);
    }

    public static function periodoAnterior(int $periodo): int
    {
        $a = self::anioDe($periodo);
        $m = self::mesDe($periodo) - 1;
        if ($m < 1) { $m = 12; $a--; }
        return self::periodo($a, $m);
    }

    public static function nombrePeriodo(?int $periodo): string
    {
        if ($periodo === null) return 'vigente';
        if ($periodo <= self::PERIODO_INICIAL) return 'desde el inicio';

        $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
                  7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        $m = self::mesDe($periodo);
        return ($meses[$m] ?? '?') . ' ' . self::anioDe($periodo);
    }

    // ═══════════════════ Consultas por vigencia ═══════════════════

    /** Versiones vigentes en un período dado (AAAAMM). */
    public function scopeVigentesEn(Builder $q, int $periodo): Builder
    {
        return $q->withoutGlobalScope('vigente')
            ->where('vigente_desde', '<=', $periodo)
            ->where(function (Builder $q2) use ($periodo) {
                $q2->whereNull('vigente_hasta')
                   ->orWhere('vigente_hasta', '>=', $periodo);
            });
    }

    /**
     * Mapa cuenta_14 => homologación, tal como estaba en ese período.
     * Es el reemplazo período-consciente de Homologacion::get()->keyBy('cuenta_14').
     */
    public static function mapaEn(int $periodo)
    {
        return static::vigentesEn($periodo)
            ->orderBy('cuenta_14')
            ->get()
            ->keyBy(fn($h) => (string) $h->cuenta_14);
    }

    /** Todas las versiones de una cuenta, de la más nueva a la más vieja. */
    public static function historial(string $cuenta14)
    {
        return static::sinFiltro()
            ->where('cuenta_14', $cuenta14)
            ->orderByDesc('version')
            ->get();
    }

    /** La versión vigente hoy de una cuenta (o null). */
    public static function actual(string $cuenta14): ?self
    {
        return static::where('cuenta_14', $cuenta14)->first();
    }

    // ═══════════════════ Reglas de negocio ═══════════════════

    /**
     * Primer período al que se le puede aplicar un cambio.
     *
     * Regla: no se puede tocar un período que ya tenga una distribución ENVIADA
     * a contabilidad, porque ese plano ya salió hacia SIESA.
     * => mínimo permitido = el período siguiente al último enviado.
     */
    public static function periodoMinimoPermitido(): int
    {
        $ultimo = Distribucion::where('estado', 'enviado')
            ->selectRaw('MAX(anio * 100 + mes) as p')
            ->value('p');

        if (!$ultimo) {
            // Nada enviado todavía: no hay nada que proteger.
            return self::PERIODO_INICIAL;
        }

        return self::siguientePeriodo((int) $ultimo);
    }

    /** El último período ya enviado a contabilidad (o null si no hay ninguno). */
    public static function ultimoPeriodoEnviado(): ?int
    {
        $p = Distribucion::where('estado', 'enviado')
            ->selectRaw('MAX(anio * 100 + mes) as p')
            ->value('p');

        return $p ? (int) $p : null;
    }

    /** ¿Es la versión vigente hoy? */
    public function esVigente(): bool
    {
        return $this->vigente_hasta === null;
    }

    /** Texto legible del rango de vigencia. */
    public function rangoVigencia(): string
    {
        $desde = $this->vigente_desde <= self::PERIODO_INICIAL
            ? 'Desde el inicio'
            : 'Desde ' . self::nombrePeriodo($this->vigente_desde);

        $hasta = $this->vigente_hasta === null
            ? 'vigente'
            : 'hasta ' . self::nombrePeriodo($this->vigente_hasta);

        return $desde . ' · ' . $hasta;
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}