@extends('layouts.app')

@section('title', 'Facturado por tipo de obra')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $pct = fn($v) => $total != 0 ? number_format($v / $total * 100, 1, ',', '.').'%' : '—';
    $depLabel = ['mantenimiento' => 'Mantenimiento', 'instalaciones' => 'Instalaciones'];
@endphp

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:1rem">
    <h1 class="page-title" style="margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        Facturado por tipo de obra · {{ $periodo }}
        @if($depEfectivo)
            <span style="font-size:12px;font-weight:600;padding:3px 12px;border-radius:10px;background:#EEF2FF;color:#4338CA">{{ $depLabel[$depEfectivo] ?? $depEfectivo }}</span>
        @endif
    </h1>
</div>

<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('operativo.facturado') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        @if(is_null($depUsuario))
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Departamento</label>
            <select name="departamento" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="">Todos</option>
                <option value="mantenimiento" {{ $depEfectivo == 'mantenimiento' ? 'selected' : '' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $depEfectivo == 'instalaciones' ? 'selected' : '' }}>Instalaciones</option>
            </select>
        </div>
        @endif
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Mes</label>
            <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Filtrar</button>
        <a href="{{ route('operativo.facturado', array_merge(request()->only('mes','anio','departamento'), ['descargar' => 1])) }}"
           style="padding:7px 16px;background:white;border:1px solid #15803D;border-radius:8px;font-size:13px;color:#15803D;text-decoration:none;height:36px;display:inline-flex;align-items:center">⬇ Descargar Excel</a>
    </form>
</div>

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
