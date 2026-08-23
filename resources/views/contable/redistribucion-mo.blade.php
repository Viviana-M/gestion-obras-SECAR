@extends('layouts.app')

@section('title', 'MO Apoyo administrativo y operativo')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
@endphp

<x-page-banner title="MO Apoyo administrativo y operativo" icon="🔀">
    Mano de obra de <b>apoyo administrativo y operativo</b> que se retira de las bolsas y se <b>redistribuye por porcentaje</b>
    entre ellas (no por proyecto). El costo por persona suma su <b>MO directa</b> más su <b>seguridad social</b>
    (cruzada de la autoliquidación por cédula). Los % son <b>por período</b>.
</x-page-banner>

@if(session('success'))<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>@endif

{{-- Filtro de período --}}
<form method="GET" action="{{ route('contable.redistribucion-mo.index') }}" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:1rem;flex-wrap:wrap">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Período</label>
        <select name="periodo" onchange="const [a,m]=this.value.split('-');this.form.mes.value=m;this.form.anio.value=a;this.form.submit()"
            style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
            @forelse($periodos as $p)
                <option value="{{ $p->anio }}-{{ $p->mes }}" {{ ($p->mes==$mes && $p->anio==$anio) ? 'selected' : '' }}>{{ $nombresMes[$p->mes] ?? $p->mes }} {{ $p->anio }}</option>
            @empty
                <option>{{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</option>
            @endforelse
        </select>
        <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
    </div>
</form>

{{-- Maestro MO Apoyo administrativo y operativo --}}
@if($puedeEditar)
<div class="card" style="padding:14px 16px;margin-bottom:1rem">
    <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:10px">Agregar persona de apoyo administrativo y operativo</h2>
    <form method="POST" action="{{ route('contable.redistribucion-mo.persona') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
        @csrf
        <div><label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Cédula</label>
            <input type="text" name="cedula" required style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px"></div>
        <div style="flex:1;min-width:200px"><label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Nombre</label>
            <input type="text" name="nombre" required style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px"></div>
        <button type="submit" style="padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:12px;cursor:pointer">Agregar</button>
    </form>
</div>
@endif

{{-- Personas + costo + % --}}
<form method="POST" action="{{ route('contable.redistribucion-mo.porcentajes') }}">
    @csrf
    <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
    <div class="card" style="padding:0;overflow-x:auto;margin-bottom:1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #E5E7EB;flex-wrap:wrap;gap:8px">
            <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin:0">Personas de apoyo administrativo y operativo · {{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</h2>
            @if($puedeEditar)<button type="submit" style="padding:8px 16px;background:#15803D;color:white;border:none;border-radius:8px;font-size:12px;cursor:pointer">💾 Guardar porcentajes</button>@endif
        </div>
        @forelse($personas as $per)
        <div style="padding:12px 16px;border-bottom:1px solid #F3F4F6">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
                <div>
                    <span style="font-weight:600;color:#1B3F6E">{{ $per['nombre'] }}</span>
                    <span style="font-family:monospace;color:#9CA3AF;font-size:11px">· {{ $per['cedula'] }}</span>
                </div>
                <div style="font-size:12px;color:#6B7280">
                    MO directa <b style="color:#374151">{{ $fmt($per['directo']) }}</b> +
                    Seg. social <b style="color:#374151">{{ $fmt($per['ss']) }}</b> =
                    Total <b style="color:#15803D">{{ $fmt($per['total']) }}</b>
                    @if($puedeEditar)
                    <a href="#" onclick="if(confirm('¿Eliminar a {{ $per['nombre'] }} de MO Apoyo administrativo y operativo?')){document.getElementById('del-{{ $per['id'] }}').submit()}return false" style="color:#DC2626;text-decoration:none;margin-left:8px">✕</a>
                    @endif
                </div>
            </div>

            {{-- Editor de % por bolsa --}}
            <div style="margin-top:8px">
                <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Redistribuir entre bolsas (deben sumar 100%):
                    <span class="suma-pct" data-ced="{{ $per['cedula'] }}" style="font-weight:700;color:{{ abs($per['suma_pct']-100)<0.05 || $per['suma_pct']==0 ? '#15803D' : '#DC2626' }}">{{ rtrim(rtrim(number_format($per['suma_pct'],1),'0'),'.') }}%</span>
                </div>
                <div id="filas-{{ $loop->index }}" data-ced="{{ $per['cedula'] }}">
                    @foreach($per['porcentajes'] as $un => $pct)
                    <div class="fila-pct" style="display:flex;gap:8px;margin-bottom:4px;align-items:center">
                        <select name="pct[{{ $per['cedula'] }}][{{ $loop->index }}][un]" onchange="recalcPct('{{ $per['cedula'] }}')" style="padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;min-width:220px">
                            @foreach($bolsas as $b)<option value="{{ $b->codigo }}" {{ $un==$b->codigo?'selected':'' }}>{{ $b->codigo }} · {{ \Illuminate\Support\Str::limit($b->nombre,28) }}</option>@endforeach
                        </select>
                        <input type="number" step="0.01" min="0" max="100" name="pct[{{ $per['cedula'] }}][{{ $loop->index }}][pct]" value="{{ rtrim(rtrim(number_format($pct,2),'0'),'.') }}" oninput="recalcPct('{{ $per['cedula'] }}')" style="width:90px;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-align:right">%
                        <a href="#" onclick="this.closest('.fila-pct').remove();recalcPct('{{ $per['cedula'] }}');return false" style="color:#DC2626;text-decoration:none">✕</a>
                    </div>
                    @endforeach
                </div>
                @if($puedeEditar)
                <button type="button" onclick="agregarFila('{{ $per['cedula'] }}','{{ $loop->index }}')" style="font-size:11px;padding:4px 10px;border:1px solid #1B3F6E;border-radius:6px;background:white;color:#1B3F6E;cursor:pointer;margin-top:2px">+ Bolsa destino</button>
                @endif
            </div>
        </div>
        @empty
        <div style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay personas de apoyo administrativo y operativo. Agrega una arriba.</div>
        @endforelse
    </div>
</form>

@if($puedeEditar)
@foreach($personas as $per)
<form id="del-{{ $per['id'] }}" method="POST" action="{{ route('contable.redistribucion-mo.persona.eliminar', $per['id']) }}" style="display:none">@csrf @method('DELETE')</form>
@endforeach
@endif

{{-- Resumen de bolsas --}}
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #E5E7EB;flex-wrap:wrap;gap:8px">
        <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin:0">Bolsas: crudo → retirado → redistribuido</h2>
        <form method="GET" action="{{ route('contable.redistribucion-mo.plano') }}" style="display:flex;gap:8px;align-items:flex-end;margin:0">
            <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            <div><label style="font-size:11px;color:#6B7280;display:block;margin-bottom:2px">N° doc</label>
                <input type="number" name="documento" min="1" value="1" style="width:90px;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px"></div>
            <button type="submit" style="padding:8px 14px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">⬇ Plano (Excel)</button>
        </form>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:680px">
        <thead>
            <tr style="background:#1B3F6E;color:white;text-align:right">
                <th style="padding:9px 12px;text-align:left">Bolsa (UN)</th>
                <th style="padding:9px 12px">MO cruda</th>
                <th style="padding:9px 12px">Retirado (Apoyo adm. y oper.)</th>
                <th style="padding:9px 12px">Neto</th>
                <th style="padding:9px 12px">Redistribuido</th>
                <th style="padding:9px 12px">Final</th>
            </tr>
        </thead>
        <tbody>
            @forelse($resumen['filas'] as $f)
            <tr style="border-bottom:1px solid #F3F4F6;text-align:right">
                <td style="padding:8px 12px;text-align:left;font-family:monospace;color:#1B3F6E">{{ $f['un'] }} <span style="color:#9CA3AF">{{ \Illuminate\Support\Str::limit($f['nombre'],24) }}</span></td>
                <td style="padding:8px 12px">{{ $fmt($f['crudo']) }}</td>
                <td style="padding:8px 12px;color:#B45309">{{ $f['retirado'] > 0 ? '−'.$fmt($f['retirado']) : '—' }}</td>
                <td style="padding:8px 12px">{{ $fmt($f['neto']) }}</td>
                <td style="padding:8px 12px;color:#15803D">{{ $f['redistribuido'] > 0 ? '+'.$fmt($f['redistribuido']) : '—' }}</td>
                <td style="padding:8px 12px;font-weight:700">{{ $fmt($f['final']) }}</td>
            </tr>
            @empty
            <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay mano de obra en bolsas para este período.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700;text-align:right">
                <td style="padding:9px 12px;text-align:left;color:#1B3F6E">Total</td>
                <td style="padding:9px 12px">{{ $fmt($resumen['total_crudo']) }}</td>
                <td style="padding:9px 12px;color:#B45309">−{{ $fmt($resumen['total_retirado']) }}</td>
                <td style="padding:9px 12px">{{ $fmt($resumen['total_crudo'] - $resumen['total_retirado']) }}</td>
                <td style="padding:9px 12px;color:#15803D">+{{ $fmt($resumen['total_redistribuido']) }}</td>
                <td style="padding:9px 12px">{{ $fmt($resumen['total_crudo'] - $resumen['total_retirado'] + $resumen['total_redistribuido']) }}</td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Plantilla de fila de % (para clonar) --}}
<template id="tpl-fila-pct">
    <div class="fila-pct" style="display:flex;gap:8px;margin-bottom:4px;align-items:center">
        <select data-name="un" style="padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;min-width:220px">
            @foreach($bolsas as $b)<option value="{{ $b->codigo }}">{{ $b->codigo }} · {{ \Illuminate\Support\Str::limit($b->nombre,28) }}</option>@endforeach
        </select>
        <input type="number" step="0.01" min="0" max="100" data-name="pct" value="" style="width:90px;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-align:right">%
        <a href="#" onclick="this.closest('.fila-pct').remove();return false" style="color:#DC2626;text-decoration:none">✕</a>
    </div>
</template>

<script>
let filaSeq = 1000;
function agregarFila(cedula, idx){
    const cont = document.getElementById('filas-'+idx);
    const tpl = document.getElementById('tpl-fila-pct').content.cloneNode(true);
    const i = filaSeq++;
    const sel = tpl.querySelector('[data-name="un"]');
    const inp = tpl.querySelector('[data-name="pct"]');
    sel.name = `pct[${cedula}][${i}][un]`; sel.setAttribute('onchange', `recalcPct('${cedula}')`);
    inp.name = `pct[${cedula}][${i}][pct]`; inp.setAttribute('oninput', `recalcPct('${cedula}')`);
    cont.appendChild(tpl);
}
function recalcPct(cedula){
    const cont = document.querySelector(`[data-ced="${cedula}"]#filas-0, [data-ced="${cedula}"]`);
    let suma = 0;
    document.querySelectorAll(`[id^="filas-"][data-ced="${cedula}"] input[name$="[pct]"]`).forEach(i => suma += parseFloat(i.value||0));
    const badge = document.querySelector(`.suma-pct[data-ced="${cedula}"]`);
    if (badge){ badge.textContent = (Math.round(suma*10)/10)+'%'; badge.style.color = (Math.abs(suma-100)<0.05||suma===0) ? '#15803D' : '#DC2626'; }
}
</script>
@endsection
