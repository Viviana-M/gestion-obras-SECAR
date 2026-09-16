@extends('layouts.app')

@section('title', 'Distribución de áreas')

@php
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $depLabel = ['mantenimiento' => 'Mantenimiento (MT)', 'instalaciones' => 'Instalaciones (IN)'];
    $semColor = ['rojo'=>['#FEF2F2','#DC2626'],'ambar'=>['#FFFBEB','#B45309'],'verde'=>['#F0FDF4','#15803D'],'gris'=>['#F9FAFB','#6B7280']];
    $totalBolsa = collect($saldoBolsa)->sum('pendiente');
@endphp

@section('content')

<x-page-banner title="Distribución de costos de áreas" icon="🏢" :badge="$depEfectivo ? ($depLabel[$depEfectivo] ?? $depEfectivo) : null">
    Reparte el costo de las <b>bolsas de área</b> entre los proyectos sin bajar el margen ofertado.
</x-page-banner>

{{-- Filtros: departamento (si aplica), mes, año, bolsa --}}
<x-filtros-panel>
    <form method="GET" action="{{ route('operativo.distribucion-areas') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        @if(is_null($depUsuario))
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Departamento</label>
            <select name="departamento" onchange="this.form.submit()" class="filtro-select">
                <option value="">— Elegir —</option>
                <option value="mantenimiento" {{ $depEfectivo=='mantenimiento'?'selected':'' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $depEfectivo=='instalaciones'?'selected':'' }}>Instalaciones</option>
            </select>
        </div>
        @endif
        <div class="filtro-field" style="flex:2 1 260px">
            <label class="filtro-label">Bolsa (UN) a repartir</label>
            <select name="bolsa" onchange="this.form.submit()" class="filtro-select">
                <option value="">— Elegir bolsa —</option>
                @foreach($bolsas as $b)
                    <option value="{{ $b->codigo }}" {{ $bolsaSel==$b->codigo?'selected':'' }}>{{ $b->codigo }} · {{ $b->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Mes</label>
            <select name="mes" class="filtro-select">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i=>$m)
                    <option value="{{ $i+1 }}" {{ ($i+1)==$mes?'selected':'' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 90px">
            <label class="filtro-label">Año</label>
            <input type="number" name="anio" value="{{ $anio }}" class="filtro-input">
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>
</x-filtros-panel>

@if(is_null($depEfectivo))
    <div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">Elige un departamento para empezar.</div>
@elseif(!$bolsaSel)
    <div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">Elige una bolsa (UN) para ver su saldo a repartir.</div>
@else

{{-- Saldo de la bolsa por cuenta 14 --}}
<div class="card" style="margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <h3 style="margin:0;font-size:15px;color:#1B3F6E">Saldo a repartir · {{ $bolsaSel }}</h3>
        <span style="font-size:15px;font-weight:700;color:#B45309">{{ $fmt($totalBolsa) }}</span>
    </div>
    @if(empty($saldoBolsa))
        <div style="text-align:center;color:#9CA3AF;padding:1rem;font-size:13px">Esta bolsa no tiene saldo pendiente en cuenta 14 para repartir.</div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6;color:#374151">
                <th style="padding:7px 10px;text-align:left">Cuenta 14</th>
                <th style="padding:7px 10px;text-align:left">→ Cuenta 61</th>
                <th style="padding:7px 10px;text-align:left">Concepto</th>
                <th style="padding:7px 10px;text-align:left">Categoría</th>
                <th style="padding:7px 10px;text-align:right">Pendiente</th>
            </tr>
        </thead>
        <tbody>
            @foreach($saldoBolsa as $l)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:6px 10px;font-family:monospace;color:#854D0E">{{ $l['cuenta_14'] }}</td>
                <td style="padding:6px 10px;font-family:monospace;color:#15803D">{{ $l['cuenta_61'] }}</td>
                <td style="padding:6px 10px;color:#6B7280">{{ \Illuminate\Support\Str::limit($l['nombre'], 40) }}</td>
                <td style="padding:6px 10px">{{ $l['estructura'] }}</td>
                <td style="padding:6px 10px;text-align:right;font-weight:600">
                    @if(($l['alerta'] ?? 0) > 0)
                        <span style="color:#DC2626" title="Saldo negativo: se reversó de más">⚠ -{{ $fmt($l['alerta']) }}</span>
                    @else
                        {{ $fmt($l['pendiente']) }}
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

{{-- Proyectos (OT) del departamento con sus tres bloques --}}
<h3 style="font-size:14px;color:#1B3F6E;margin:1rem 0 .5rem">Proyectos disponibles ({{ count($proyectos) }})</h3>

@forelse($proyectos as $o)
@php $sc = $semColor[$o['semaforo']] ?? $semColor['gris']; @endphp
<div class="card" style="padding:0;overflow:hidden;margin-bottom:12px">
    {{-- Encabezado de la OT --}}
    <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #F3F4F6;flex-wrap:wrap">
        <span style="width:9px;height:9px;border-radius:50%;background:{{ $sc[1] }}"></span>
        {{-- Encabezado: código - nombre del proyecto · cliente (completo, con tooltip) --}}
        <span style="font-weight:700;color:#1B3F6E">{{ $o['codigo'] }}</span>
        <span style="color:#374151;font-size:13px;word-break:break-word" title="{{ $o['nombre'] }}">- {{ $o['nombre'] }}</span>
        @if(!empty($o['cliente']))
            <span style="color:#6B7280;font-size:12px;word-break:break-word" title="{{ $o['cliente'] }}">· 🏢 {{ $o['cliente'] }}</span>
        @endif
        <span style="font-size:11px;padding:2px 10px;border-radius:8px;background:{{ $sc[0] }};color:{{ $sc[1] }}">{{ ucfirst($o['estado']) }}</span>
    </div>

    {{-- Bloque 1: Estado de avance --}}
    <div style="padding:10px 16px;background:#F9FAFB;border-bottom:1px solid #F3F4F6">
        <div style="font-size:10px;font-weight:700;color:#6B7280;letter-spacing:.5px;margin-bottom:6px">ESTADO DE AVANCE DE OBRA · ACUMULADO</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:12px">
            <div><span style="color:#9CA3AF">Facturado acum. recon.</span><br><b>{{ $fmt($o['fact_acum_rec']) }}</b></div>
            <div><span style="color:#9CA3AF">Costo acum. recon.</span><br><b>{{ $fmt($o['costo_acum_rec']) }}</b></div>
            <div><span style="color:#9CA3AF">Margen acum. ($)</span><br><b style="color:#15803D">{{ $fmt($o['margen_acum_pesos']) }}</b></div>
            <div><span style="color:#9CA3AF">MC %</span><br><b>{{ $o['mc_pct_acum']===null?'—':$o['mc_pct_acum'].'%' }}</b></div>
        </div>
    </div>

    {{-- Bloque 2: Rentabilidad del mes --}}
    <div style="padding:10px 16px;background:#FFFBEB;border-bottom:1px solid #F3F4F6">
        <div style="font-size:10px;font-weight:700;color:#B45309;letter-spacing:.5px;margin-bottom:6px">RENTABILIDAD DEL MES</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:12px">
            <div><span style="color:#9CA3AF">Ingresos del mes</span><br><b>{{ $fmt($o['ingreso_mes']) }}</b></div>
            <div><span style="color:#9CA3AF">Costo del mes (cta 6)</span><br><b>{{ $fmt($o['costo_mes_c6']) }}</b></div>
            <div><span style="color:#9CA3AF">MC del mes ($)</span><br><b style="color:{{ $o['mc_mes_pesos']>=0?'#15803D':'#DC2626' }}">{{ $fmt($o['mc_mes_pesos']) }}</b></div>
            <div><span style="color:#9CA3AF">MC del mes %</span><br><b>{{ $o['mc_mes_pct']===null?'—':$o['mc_mes_pct'].'%' }}</b></div>
        </div>
    </div>

    {{-- Bloque 3: Proyección --}}
    <div style="padding:10px 16px;background:#EEF2FF">
        <div style="font-size:10px;font-weight:700;color:#4338CA;letter-spacing:.5px;margin-bottom:6px">PROYECCIÓN DE RENTABILIDAD · OFERTA vs REALIDAD</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:12px">
            <div><span style="color:#9CA3AF">MC % ofertado</span><br><b>{{ $o['pr_mc_ofertado']===null?'—':$o['pr_mc_ofertado'].'%' }}</b></div>
            <div><span style="color:#9CA3AF">MC % proyección</span><br><b style="color:#15803D">{{ $o['pr_mc_proy']===null?'—':$o['pr_mc_proy'].'%' }}</b></div>
            <div><span style="color:#9CA3AF">Inventario en obra (cta 14)</span><br><b>{{ $fmt($o['pr_inv_obra']) }}</b></div>
            <div><span style="color:#9CA3AF">Costo total</span><br><b>{{ $fmt($o['pr_costo_total']) }}</b></div>
            <div><span style="color:#9CA3AF">Avance ejecución</span><br><b>{{ $o['pr_avance_ejec']===null?'—':$o['pr_avance_ejec'].'%' }}</b></div>
        </div>
    </div>
</div>
@empty
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay proyectos con actividad en este departamento.</div>
@endforelse

@endif
@endsection