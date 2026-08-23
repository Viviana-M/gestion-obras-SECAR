@extends('layouts.app')

@section('title', 'Autoliquidación (PILA)')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
@endphp

<x-page-banner title="Autoliquidación de aportes (PILA)" icon="🧾">
    Carga la planilla mensual de aportes; el período se toma de la columna <b>Fecha</b> del archivo y al recargar el mismo mes se reemplaza.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>
@endif

@php
    // Columnas que DEBE traer el archivo plano, en este orden.
    $columnasPila = ['ID Cuenta', 'Cuenta contable', 'Id. Tercero Mov', 'Razon Social', 'Id. U.N. Mov',
        'Fecha', 'Descripción UN', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl',
        'Aporte del empl', 'Aporte empresa', 'Real Descontado'];
@endphp

@if($puedeEditar)
<div class="card" style="padding:16px;margin-bottom:1.25rem">
    <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:10px">Cargar planilla</h2>
    <form method="POST" action="{{ route('contable.autoliquidacion.store') }}" enctype="multipart/form-data"
        style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Archivo Excel (.xlsx)</label>
            <input type="file" name="archivo" accept=".xlsx,.xls" required
                style="font-size:12px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white">
        </div>
        <button type="submit" style="padding:8px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Cargar</button>
    </form>
    <p style="font-size:11px;color:#9CA3AF;margin-top:8px">El período (mes/año) se toma de la columna <b>Fecha</b> del archivo (ej. <code>2026-04-30</code> → abril 2026). Al recargar el mismo mes se reemplaza.</p>

    {{-- Columnas exactas que debe traer el archivo plano --}}
    <x-columnas-plano titulo="El archivo plano debe traer estas 13 columnas, en este orden:" :columnas="$columnasPila" />
</div>
@endif

@if($periodos->isEmpty())
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">Aún no hay planillas cargadas.</div>
@else

{{-- Selector de período (conserva la pestaña activa) --}}
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
    <input type="hidden" name="tab" id="tab-input-periodo" value="{{ $tab }}">
</form>

{{-- Pestañas --}}
<div style="display:flex;gap:4px;border-bottom:2px solid #E5E7EB;margin-bottom:1.25rem">
    <button type="button" id="tabbtn-resumen" onclick="activarTab('resumen')"
        style="padding:9px 16px;border:none;background:none;font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px">Carga y resumen</button>
    <button type="button" id="tabbtn-personas" onclick="activarTab('personas')"
        style="padding:9px 16px;border:none;background:none;font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px">Seguridad social por persona</button>
</div>

{{-- ═══════════ PESTAÑA: CARGA Y RESUMEN ═══════════ --}}
<div id="tab-resumen">
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

    @if($puedeEditar && $resumen['filas'] > 0)
    <div class="card" style="padding:12px 16px;margin-bottom:1.25rem;display:flex;gap:12px;align-items:center;flex-wrap:wrap;border:1px solid #FECACA;background:#FEF2F2">
        <div style="flex:1;min-width:220px;font-size:12px;color:#7F1D1D">
            ¿Datos duplicados o incorrectos en <b>{{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</b>? Vacía el período y vuelve a cargar la planilla.
        </div>
        <form method="POST" action="{{ route('contable.autoliquidacion.vaciar') }}"
            onsubmit="return confirm('¿Vaciar TODOS los aportes de {{ $nombresMes[$mes] ?? $mes }} {{ $anio }}? Se borrarán {{ number_format($resumen['filas'],0,',','.') }} filas. Luego deberás volver a cargar la planilla.');"
            style="margin:0">
            @csrf
            <input type="hidden" name="mes" value="{{ $mes }}">
            <input type="hidden" name="anio" value="{{ $anio }}">
            <button type="submit" style="padding:8px 14px;background:#DC2626;color:white;border:none;border-radius:8px;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap">🗑 Vaciar {{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</button>
        </form>
    </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="grid-desgloses">
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
</div>

@include('contable.partials.seguridad-social')

<style>
@media (max-width: 820px) { .grid-desgloses { grid-template-columns: 1fr !important; } }
</style>

<script>
function activarTab(t){
    ['resumen','personas'].forEach(function(k){
        const sec = document.getElementById('tab-'+k);
        const btn = document.getElementById('tabbtn-'+k);
        const on = (k === t);
        if(sec) sec.style.display = on ? 'block' : 'none';
        if(btn){ btn.style.color = on ? '#1B3F6E' : '#9CA3AF'; btn.style.borderBottomColor = on ? '#1B3F6E' : 'transparent'; }
    });
    const hp = document.getElementById('tab-input-periodo'); if(hp) hp.value = t;
    const hu = document.getElementById('tab-input-un'); if(hu) hu.value = t;
}
activarTab(@json($tab));
</script>
@endif
@endsection
