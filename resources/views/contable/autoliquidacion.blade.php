@extends('layouts.app')

@section('title', 'Autoliquidación (PILA)')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
@endphp

<x-page-banner title="Autoliquidación de aportes (PILA)" icon="🧾">
    Carga la planilla mensual de aportes; el período se toma del <b>nombre del archivo</b> (patrón <code>AAAA_MM</code>) y al recargar el mes se reemplaza.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>
@endif

@if($puedeEditar)
<div class="card" style="padding:16px;margin-bottom:1.25rem">
    <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:10px">Cargar planilla</h2>
    <form method="POST" action="{{ route('contable.autoliquidacion.store') }}" enctype="multipart/form-data"
        style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Archivo Excel (.xlsx) — nómbralo <code>AAAA_MM</code></label>
            <input type="file" name="archivo" accept=".xlsx,.xls" required
                style="font-size:12px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white">
        </div>
        <button type="submit" style="padding:8px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Cargar</button>
    </form>
    <p style="font-size:11px;color:#9CA3AF;margin-top:8px">El período (mes/año) se toma del nombre del archivo, ej. <code>2026_06.xlsx</code> → junio 2026.</p>
</div>
@endif

@if($periodos->isEmpty())
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">Aún no hay planillas cargadas.</div>
@else

{{-- Selector de período --}}
<form method="GET" action="{{ route('contable.autoliquidacion.index') }}" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:1rem;flex-wrap:wrap">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Período</label>
        <select name="periodo" onchange="const [a,m]=this.value.split('-');this.form.mes.value=m;this.form.anio.value=a;this.form.submit()"
            style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
            @foreach($periodos as $p)
                <option value="{{ $p->anio }}-{{ $p->mes }}" {{ ($p->mes==$mes && $p->anio==$anio) ? 'selected' : '' }}>
                    {{ $nombresMes[$p->mes] ?? $p->mes }} {{ $p->anio }}
                </option>
            @endforeach
        </select>
    </div>
    <input type="hidden" name="mes" value="{{ $mes }}">
    <input type="hidden" name="anio" value="{{ $anio }}">
</form>

{{-- Resumen --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:1.25rem">
    <div class="card" style="padding:12px 16px">
        <div style="font-size:11px;color:#6B7280">Personas</div>
        <div style="font-size:20px;font-weight:700;color:#1B3F6E">{{ number_format($resumen['personas'], 0, ',', '.') }}</div>
        <div style="font-size:10px;color:#9CA3AF">{{ number_format($resumen['filas'], 0, ',', '.') }} filas</div>
    </div>
    <div class="card" style="padding:12px 16px">
        <div style="font-size:11px;color:#6B7280">Aporte empresa</div>
        <div style="font-size:20px;font-weight:700;color:#15803D">{{ $fmt($resumen['aporte_empresa']) }}</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="grid-desgloses">
    {{-- Por unidad de negocio --}}
    <div class="card" style="padding:0;overflow-x:auto">
        <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;padding:14px 16px 8px">Por unidad de negocio</h2>
        <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:360px">
            <thead>
                <tr style="color:#9CA3AF;text-align:left;border-bottom:1px solid #E5E7EB">
                    <th style="padding:8px 12px">UN</th>
                    <th style="padding:8px 12px;text-align:right">Personas</th>
                    <th style="padding:8px 12px;text-align:right">Aporte empresa</th>
                </tr>
            </thead>
            <tbody>
                @foreach($porUN as $u)
                <tr style="border-bottom:1px solid #F3F4F6">
                    <td style="padding:7px 12px;font-weight:600;color:#1B3F6E;font-family:monospace">{{ $u->un_codigo ?: '—' }}</td>
                    <td style="padding:7px 12px;text-align:right">{{ number_format($u->personas, 0, ',', '.') }}</td>
                    <td style="padding:7px 12px;text-align:right;color:#15803D">{{ $fmt($u->aporte_empresa) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Por concepto PILA --}}
    <div class="card" style="padding:0;overflow-x:auto">
        <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;padding:14px 16px 8px">Por concepto PILA</h2>
        <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:320px">
            <thead>
                <tr style="color:#9CA3AF;text-align:left;border-bottom:1px solid #E5E7EB">
                    <th style="padding:8px 12px">Concepto</th>
                    <th style="padding:8px 12px;text-align:right">Aporte empresa</th>
                </tr>
            </thead>
            <tbody>
                @foreach($porConcepto as $c)
                <tr style="border-bottom:1px solid #F3F4F6">
                    <td style="padding:7px 12px;color:#374151">{{ \Illuminate\Support\Str::limit($c->concepto_pila, 40) ?: '—' }}</td>
                    <td style="padding:7px 12px;text-align:right;color:#15803D">{{ $fmt($c->aporte_empresa) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<style>
@media (max-width: 820px) { .grid-desgloses { grid-template-columns: 1fr !important; } }
</style>
@endif
@endsection
