@extends('layouts.app')

@section('title', 'Cierre de mes')

@section('content')
@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
    $fmtFecha = fn($f) => $f ? \Illuminate\Support\Carbon::parse($f)->format('d/m/Y H:i') : null;
@endphp

{{-- Banner del título. Color sólido de respaldo ANTES del degradado (nunca gris). --}}
<div style="background:#1B3F6E;background:linear-gradient(120deg,#1B3F6E,#2C5FA0);border-radius:14px;padding:20px 24px;margin-bottom:1.25rem;color:#fff;box-shadow:0 6px 18px rgba(27,63,110,.18)">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="font-size:26px">🗓️</span>
        <div>
            <div style="font-size:20px;font-weight:700">Cierre de mes · edición de Distribución</div>
            <div style="font-size:12.5px;color:#DCE6F5;margin-top:2px">
                Abre el cierre de un mes para que Operaciones pueda editar su distribución. Mientras esté cerrado, es de solo lectura.
            </div>
        </div>
    </div>
</div>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>
@endif

{{-- Filtro de año + leyenda --}}
<form method="GET" action="{{ route('contable.cierre.index') }}" style="display:flex;gap:14px;align-items:flex-end;margin-bottom:1.25rem;flex-wrap:wrap">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
        <select name="anio" onchange="this.form.submit()"
            style="padding:8px 14px;border:1px solid #E5E7EB;border-radius:8px;font-size:14px;font-weight:600;color:#1B3F6E">
            @foreach($anios as $a)
                <option value="{{ $a }}" {{ $a == $anio ? 'selected' : '' }}>{{ $a }}</option>
            @endforeach
        </select>
    </div>
    <div style="display:flex;gap:16px;align-items:center;font-size:11.5px;color:#6B7280;padding-bottom:6px">
        <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:#15803D;vertical-align:middle;margin-right:4px"></span>Abierto — Operaciones edita</span>
        <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:#E5E7EB;border:1px solid #D1D5DB;vertical-align:middle;margin-right:4px"></span>Cerrado — solo lectura</span>
    </div>
</form>

{{-- Calendario: 3 filas × 4 columnas --}}
<div class="cierre-grid">
    @foreach($meses as $p)
    @php $abierto = $p['abierto']; @endphp
    <div class="card" style="padding:14px;background:{{ $abierto ? '#ECFDF5' : '#fff' }};border:1px solid {{ $abierto ? '#86EFAC' : '#E5E7EB' }};border-top:4px solid {{ $abierto ? '#15803D' : '#CBD5E1' }}">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
            <div style="font-weight:700;color:#1B3F6E;font-size:15px">{{ $nombresMes[$p['mes']] }}</div>
            <div style="font-size:11px;color:#9CA3AF">{{ $p['anio'] }}</div>
        </div>
        <div style="margin:9px 0">
            @if($abierto)
                <span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:#15803D;color:#fff">🔓 Abierto</span>
            @else
                <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;background:#F3F4F6;color:#6B7280;border:1px solid #E5E7EB">🔒 Cerrado</span>
            @endif
        </div>
        @php $marca = $abierto ? $fmtFecha($p['abierto_at']) : $fmtFecha($p['cerrado_at']); @endphp
        <div style="font-size:10px;color:#9CA3AF;min-height:14px;margin-bottom:9px">{{ $marca ? ($abierto ? 'Abierto: ' : 'Cerrado: ').$marca : '' }}</div>
        @if($puedeEditar)
        <form method="POST" action="{{ route('contable.cierre.toggle') }}">
            @csrf
            <input type="hidden" name="mes" value="{{ $p['mes'] }}">
            <input type="hidden" name="anio" value="{{ $p['anio'] }}">
            <input type="hidden" name="accion" value="{{ $abierto ? 'cerrar' : 'abrir' }}">
            @if($abierto)
                <button type="submit" style="width:100%;padding:8px;border:1px solid #DC2626;border-radius:8px;background:#fff;color:#DC2626;font-size:12px;font-weight:600;cursor:pointer">Cerrar mes</button>
            @else
                <button type="submit" style="width:100%;padding:8px;border:none;border-radius:8px;background:#1B3F6E;color:#fff;font-size:12px;font-weight:600;cursor:pointer">Abrir cierre</button>
            @endif
        </form>
        @endif
    </div>
    @endforeach
</div>

<style>
    .cierre-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; }
    @media (max-width: 900px) { .cierre-grid { grid-template-columns:repeat(2, 1fr); } }
    @media (max-width: 520px) { .cierre-grid { grid-template-columns:1fr; } }
</style>
@endsection
