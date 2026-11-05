@extends('layouts.app')

@section('title', 'Facturado por tipo de obra')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $pct = fn($v) => $total != 0 ? number_format($v / $total * 100, 1, ',', '.').'%' : '—';
    $depLabel = ['mantenimiento' => 'Mantenimiento', 'instalaciones' => 'Instalaciones'];
@endphp

<x-page-banner title="Facturado por tipo de obra" icon="🧾" :badge="$depEfectivo ? ($depLabel[$depEfectivo] ?? $depEfectivo) : null">
    Ingresos facturados de <b>{{ $periodo }}</b> agrupados por tipo de obra, con su participación sobre el total.
    <x-slot:actions>
        <a href="{{ route('operativo.facturado', array_merge(request()->only('mes','anio','departamento'), ['descargar' => 1])) }}"
           style="padding:7px 16px;background:white;border:1px solid #15803D;border-radius:8px;font-size:13px;color:#15803D;text-decoration:none;height:36px;display:inline-flex;align-items:center">⬇ Descargar Excel</a>
    </x-slot:actions>
</x-page-banner>

<x-filtros-panel>
    <form method="GET" action="{{ route('operativo.facturado') }}" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        @if(is_null($depUsuario))
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Departamento</label>
            <select name="departamento" class="filtro-select">
                <option value="">Todos</option>
                <option value="mantenimiento" {{ $depEfectivo == 'mantenimiento' ? 'selected' : '' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $depEfectivo == 'instalaciones' ? 'selected' : '' }}>Instalaciones</option>
            </select>
        </div>
        @endif
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Mes</label>
            <select name="mes" class="filtro-select">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Año</label>
            <select name="anio" class="filtro-select">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" class="btn-filtrar">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Filtrar
        </button>
    </form>
</x-filtros-panel>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:420px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="text-align:left;padding:10px 14px">Tipo de obra</th>
                <th style="text-align:right;padding:10px 14px">Total facturado</th>
                <th style="text-align:right;padding:10px 14px">Participación</th>
            </tr>
        </thead>
        <tbody>
            @forelse($filas as $f)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:9px 14px;font-weight:500">{{ $f['label'] }}</td>
                <td style="padding:9px 14px;text-align:right">{{ $fmt($f['total']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:#6B7280">{{ $pct($f['total']) }}</td>
            </tr>
            @empty
            <tr><td colspan="3" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay facturación en este período / filtro.</td></tr>
            @endforelse
        </tbody>
        @if(count($filas))
        <tfoot>
            <tr style="background:#BBF7D0;font-weight:700">
                <td style="padding:11px 14px">TOTAL</td>
                <td style="padding:11px 14px;text-align:right">{{ $fmt($total) }}</td>
                <td style="padding:11px 14px;text-align:right">100%</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
@endsection
