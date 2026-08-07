@extends('layouts.app')

@section('title', 'Cierre de mes')

@section('content')
@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
    $fmtFecha = fn($f) => $f ? \Illuminate\Support\Carbon::parse($f)->format('d/m/Y H:i') : null;
@endphp

<h1 class="page-title">Cierre de mes (edición de Distribución)</h1>
<p style="color:#6B7280;font-size:13px;margin:-6px 0 16px">
    Contabilidad <b>abre el cierre</b> de un mes para que Operaciones pueda editar la distribución de ese período.
    Mientras esté <b>cerrado</b> (o sin abrir), la distribución de ese mes es de <b>solo lectura</b>.
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>
@endif

{{-- Filtro de año --}}
<form method="GET" action="{{ route('contable.cierre.index') }}" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:1.25rem;flex-wrap:wrap">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
        <select name="anio" onchange="this.form.submit()"
            style="padding:8px 14px;border:1px solid #E5E7EB;border-radius:8px;font-size:14px;font-weight:600;color:#1B3F6E">
            @foreach($anios as $a)
                <option value="{{ $a }}" {{ $a == $anio ? 'selected' : '' }}>{{ $a }}</option>
            @endforeach
        </select>
    </div>
    <div style="display:flex;gap:14px;align-items:center;font-size:11px;color:#6B7280;padding-bottom:6px">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#15803D;vertical-align:middle"></span> Abierto (Operaciones edita)</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#D1D5DB;vertical-align:middle"></span> Cerrado (solo lectura)</span>
    </div>
</form>

{{-- Calendario: 12 meses del año elegido --}}
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px">
    @foreach($meses as $p)
    <div class="card" style="padding:12px 14px;border-left:4px solid {{ $p['abierto'] ? '#15803D' : '#D1D5DB' }}">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
            <div style="font-weight:700;color:#1B3F6E;font-size:14px">{{ $nombresMes[$p['mes']] }}</div>
            <div style="font-size:11px;color:#9CA3AF">{{ $p['anio'] }}</div>
        </div>
        <div style="margin:8px 0">
            @if($p['abierto'])
                <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:10px;background:#DCFCE7;color:#15803D">🔓 Cierre abierto</span>
            @else
                <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:10px;background:#F3F4F6;color:#6B7280">🔒 Cerrado</span>
            @endif
        </div>
        @php $marca = $p['abierto'] ? $fmtFecha($p['abierto_at']) : $fmtFecha($p['cerrado_at']); @endphp
        @if($marca)
            <div style="font-size:10px;color:#9CA3AF;margin-bottom:8px">{{ $p['abierto'] ? 'Abierto' : 'Cerrado' }}: {{ $marca }}</div>
        @endif
        @if($puedeEditar)
        <form method="POST" action="{{ route('contable.cierre.toggle') }}" style="margin-top:4px">
            @csrf
            <input type="hidden" name="mes" value="{{ $p['mes'] }}">
            <input type="hidden" name="anio" value="{{ $p['anio'] }}">
            <input type="hidden" name="accion" value="{{ $p['abierto'] ? 'cerrar' : 'abrir' }}">
            @if($p['abierto'])
                <button type="submit" style="width:100%;padding:7px;border:1px solid #DC2626;border-radius:8px;background:white;color:#DC2626;font-size:12px;font-weight:600;cursor:pointer">Cerrar</button>
            @else
                <button type="submit" style="width:100%;padding:7px;border:none;border-radius:8px;background:#15803D;color:white;font-size:12px;font-weight:600;cursor:pointer">Abrir cierre</button>
            @endif
        </form>
        @endif
    </div>
    @endforeach
</div>
@endsection
