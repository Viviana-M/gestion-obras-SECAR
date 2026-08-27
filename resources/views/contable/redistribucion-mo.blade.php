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
    (cruzada de la autoliquidación por cédula). Los % son <b>por período</b> y el saldo <b>no distribuido se arrastra</b>
    al mes siguiente (queda en la cuenta 14 hasta llevarlo a costo real).
</x-page-banner>

@if(session('success'))<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>@endif
@if($hayHeredados)<div style="background:#FEFCE8;border:1px solid #FDE68A;color:#92400E;border-radius:8px;padding:9px 14px;font-size:12.5px;margin-bottom:1rem">↩ <b>% precargados del mes anterior</b> — revísalos y pulsa <b>Guardar</b> para dejarlos fijados en este período. Son editables.</div>@endif

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
    <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:4px">Agregar persona de apoyo administrativo y operativo</h2>
    <p style="font-size:11.5px;color:#6B7280;margin:0 0 10px">Lo más seguro es agregarlas desde la lista de abajo (usan el identificador exacto de la bolsa). También puedes escribir el nombre a mano; la cédula es opcional.</p>
    <form method="POST" action="{{ route('contable.redistribucion-mo.persona') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
        @csrf
        <div><label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Cédula (opcional)</label>
            <input type="text" name="cedula" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px"></div>
        <div style="flex:1;min-width:200px"><label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Nombre (igual al de la bolsa)</label>
            <input type="text" name="nombre" required style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px"></div>
        <button type="submit" style="padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:12px;cursor:pointer">Agregar</button>
    </form>
</div>
@endif

{{-- Diagnóstico: terceros de MO en bolsas que NO cruzaron con el maestro (con alta directa) --}}
@if(!empty($sinCruzar))
<div class="card" style="padding:14px 16px;margin-bottom:1rem;border-left:4px solid #D97706">
    <h2 style="font-size:13px;font-weight:700;color:#B45309;margin:0 0 4px">⚠ Terceros con mano de obra en bolsas que aún no gestiona este módulo</h2>
    <p style="font-size:11.5px;color:#6B7280;margin:0 0 8px">Su costo sigue en la bolsa de Operaciones. Pulsa <b>Agregar</b> para registrarlos con su identificador exacto: así cruzan seguro, se retiran de la bolsa y entran a la redistribución.</p>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="text-align:left;color:#6B7280;border-bottom:1px solid #E5E7EB">
            <th style="padding:5px 8px">Documento</th><th style="padding:5px 8px">Nombre (razón social)</th><th style="padding:5px 8px;text-align:right">Monto MO</th>
            @if($puedeEditar)<th style="padding:5px 8px;text-align:right">Acción</th>@endif
        </tr></thead>
        <tbody>
        @foreach(array_slice($sinCruzar, 0, 50) as $t)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:5px 8px;font-family:monospace">{{ $t['doc'] !== '' ? $t['doc'] : '—' }}</td>
                <td style="padding:5px 8px">{{ $t['nombre'] !== '' ? $t['nombre'] : '—' }}</td>
                <td style="padding:5px 8px;text-align:right">{{ $fmt($t['monto']) }}</td>
                @if($puedeEditar)
                <td style="padding:5px 8px;text-align:right">
                    <form method="POST" action="{{ route('contable.redistribucion-mo.persona') }}" style="margin:0;display:inline">
                        @csrf
                        <input type="hidden" name="cedula" value="{{ $t['doc'] }}">
                        <input type="hidden" name="nombre" value="{{ $t['nombre'] }}">
                        <button type="submit" @disabled($t['nombre']==='') style="padding:4px 12px;background:#1B3F6E;color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer">+ Agregar</button>
                    </form>
                </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Validación: descuadres entre la cuenta 14 del fondo y la autoliquidación --}}
@if(!empty($descuadres))
<div class="card" style="padding:14px 16px;margin-bottom:1rem;border-left:4px solid #DC2626">
    <h2 style="font-size:13px;font-weight:700;color:#B91C1C;margin:0 0 4px">⚠ Fondos que no cuadran (cuenta 14 vs autoliquidación)</h2>
    <p style="font-size:11.5px;color:#6B7280;margin:0 0 8px">La seguridad social sale de la <b>cuenta 14</b> del fondo y la autoliquidación solo dice cómo repartirla. Si no coinciden, revísalo: la atribución por persona puede quedar desajustada.</p>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="text-align:left;color:#6B7280;border-bottom:1px solid #E5E7EB">
            <th style="padding:5px 8px">Fondo / EPS</th>
            <th style="padding:5px 8px;text-align:right">Cuenta 14</th>
            <th style="padding:5px 8px;text-align:right">Autoliquidación</th>
            <th style="padding:5px 8px;text-align:right">Diferencia</th>
        </tr></thead>
        <tbody>
        @foreach($descuadres as $d)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:5px 8px">{{ $d['fondo'] }}</td>
                <td style="padding:5px 8px;text-align:right">{{ $fmt($d['cuenta14']) }}</td>
                <td style="padding:5px 8px;text-align:right">{{ $fmt($d['autoliq']) }}</td>
                <td style="padding:5px 8px;text-align:right;font-weight:700;color:#B91C1C">{{ ($d['diferencia']>=0?'+':'−').$fmt(abs($d['diferencia'])) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Resumen agregado en vivo: total a redistribuir + chips por bolsa destino --}}
<div class="card" style="padding:14px 16px;margin-bottom:1rem">
    <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF;font-weight:700;margin-bottom:8px">Resumen — mano de obra que se retira de las bolsas y se redistribuye por %</div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
        <span style="font-size:12px;color:#6B7280">Total a redistribuir</span>
        <span id="s-total" style="font-size:22px;font-weight:800;color:#1B3F6E">$0</span>
        <div id="s-chips" style="display:flex;gap:8px;flex-wrap:wrap;margin-left:auto"></div>
    </div>
</div>

{{-- Personas + costo + % --}}
<form method="POST" action="{{ route('contable.redistribucion-mo.porcentajes') }}">
    @csrf
    <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
    <div class="card" style="padding:0;overflow-x:auto;margin-bottom:1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #E5E7EB;flex-wrap:wrap;gap:8px">
            <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin:0">Personas de apoyo administrativo y operativo · {{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</h2>
            @if($puedeEditar)<button type="submit" style="padding:8px 16px;background:#15803D;color:white;border:none;border-radius:8px;font-size:12px;cursor:pointer">💾 Guardar distribución</button>@endif
        </div>
        @forelse($personas as $per)
        <div style="padding:16px;border-bottom:1px solid #F3F4F6">
            {{-- Encabezado: nombre + chips de costo --}}
            <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px">
                <div>
                    <div style="font-weight:700;font-size:15px;color:#1B3F6E">{{ $per['nombre'] }}
                        @if($per['heredado'])<span title="Heredado del mes anterior" style="font-size:10px;font-weight:700;color:#92400E;background:#FEF3C7;border-radius:20px;padding:2px 8px;margin-left:6px;vertical-align:middle">↩ heredado</span>@endif
                    </div>
                    <div style="font-size:12px;color:#9CA3AF;font-family:monospace">CC {{ $per['cedula'] }}</div>
                </div>
                <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <span style="font-size:12px;padding:5px 11px;border-radius:10px;background:#F3F4F6;font-weight:600;color:#6B7280">MO directa <b style="color:#374151">{{ $fmt($per['directo']) }}</b></span>
                    <span style="font-size:12px;padding:5px 11px;border-radius:10px;background:#F3F4F6;font-weight:600;color:#6B7280">Seg. social <b style="color:#374151">{{ $fmt($per['ss']) }}</b></span>
                    <span title="Saldo acumulado en la cuenta 14 (incluye lo no distribuido de meses anteriores)" style="font-size:12px;padding:5px 11px;border-radius:10px;background:#E5EEF8;font-weight:700;color:#1B3F6E">Disponible <b>{{ $fmt($per['total']) }}</b></span>
                    @if($puedeEditar)
                    <a href="#" onclick="if(confirm('¿Eliminar a {{ $per['nombre'] }} de MO Apoyo administrativo y operativo?')){document.getElementById('del-{{ $per['id'] }}').submit()}return false" style="color:#DC2626;text-decoration:none">✕</a>
                    @endif
                </div>
            </div>

            @if($per['total'] > 0.005)
            {{-- Monto a distribuir: la totalidad o una porción del disponible; el resto se arrastra --}}
            <div style="margin-bottom:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <label style="font-size:11px;color:#6B7280">Monto a distribuir a costo real</label>
                <input type="number" step="0.01" min="0" max="{{ $per['total'] }}" name="monto[{{ $per['cedula'] }}]"
                    value="{{ rtrim(rtrim(number_format($per['monto_distribuir'],2,'.',''),'0'),'.') }}"
                    class="monto-dist" data-ced="{{ $per['cedula'] }}" data-total="{{ $per['total'] }}"
                    oninput="recalcPct('{{ $per['cedula'] }}')" @readonly(!$puedeEditar)
                    style="width:150px;padding:6px;border:1px solid #CBD5E1;border-radius:8px;font-size:12px;text-align:right">
                <span style="font-size:11px;color:#6B7280">de {{ $fmt($per['total']) }} · Pendiente (se arrastra):
                    <b class="pendiente-dist" data-ced="{{ $per['cedula'] }}" style="color:#B45309">{{ $fmt($per['pendiente']) }}</b></span>
                @if($puedeEditar)<button type="button" onclick="setMontoTotal('{{ $per['cedula'] }}')" style="font-size:10.5px;padding:3px 8px;border:1px solid #1B3F6E;border-radius:6px;background:white;color:#1B3F6E;cursor:pointer">Todo</button>@endif
            </div>
            @endif

            {{-- Barra visual del reparto por bolsa --}}
            <div class="barwrap" data-ced="{{ $per['cedula'] }}" style="display:flex;height:12px;border-radius:7px;overflow:hidden;margin:2px 0 12px;background:#EEF2F8"></div>

            {{-- Editor de % por bolsa --}}
            <div id="filas-{{ $loop->index }}" data-ced="{{ $per['cedula'] }}" data-total="{{ $per['total'] }}" style="display:flex;flex-direction:column;gap:8px">
                @foreach($per['porcentajes'] as $un => $pct)
                <div class="fila-pct" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <span class="sw" style="width:10px;height:10px;border-radius:3px;flex:none;background:{{ $coloresBolsa[$un] ?? '#94A3B8' }}"></span>
                    <select name="pct[{{ $per['cedula'] }}][{{ $loop->index }}][un]" onchange="recalcPct('{{ $per['cedula'] }}')" style="flex:1;min-width:200px;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:12px">
                        @foreach($bolsas as $b)<option value="{{ $b->codigo }}" {{ $un==$b->codigo?'selected':'' }}>{{ $b->codigo }} · {{ \Illuminate\Support\Str::limit($b->nombre,28) }}</option>@endforeach
                    </select>
                    <input type="number" step="0.01" min="0" max="100" name="pct[{{ $per['cedula'] }}][{{ $loop->index }}][pct]" value="{{ rtrim(rtrim(number_format($pct,2),'0'),'.') }}" oninput="recalcPct('{{ $per['cedula'] }}')" style="width:74px;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:12px;text-align:right"><span style="color:#6B7280;font-weight:600">%</span>
                    <span class="val-dist" style="margin-left:auto;font-size:13px;color:#1B3F6E;font-weight:700;min-width:110px;text-align:right">{{ $per['monto_distribuir'] > 0 ? $fmt($per['monto_distribuir'] * $pct / 100) : '' }}</span>
                    <a href="#" onclick="this.closest('.fila-pct').remove();recalcPct('{{ $per['cedula'] }}');return false" style="color:#DC2626;text-decoration:none;font-size:16px">✕</a>
                </div>
                @endforeach
            </div>
            @if($puedeEditar)
            <button type="button" onclick="agregarFila('{{ $per['cedula'] }}','{{ $loop->index }}')" style="margin-top:10px;font-size:12px;padding:6px 12px;border:1px dashed #94A3B8;border-radius:8px;background:transparent;color:#2563a8;font-weight:600;cursor:pointer">+ Bolsa destino</button>
            @endif

            {{-- Pie: indicador de suma 100% + distribuido --}}
            <div style="display:flex;align-items:center;gap:10px;margin-top:12px;padding-top:10px;border-top:1px solid #F3F4F6;font-size:12.5px;flex-wrap:wrap">
                <span class="suma-pill" data-ced="{{ $per['cedula'] }}" style="font-weight:700;padding:2px 10px;border-radius:20px"></span>
                <span style="margin-left:auto;color:#6B7280">Distribuido: <b class="distribuido" data-ced="{{ $per['cedula'] }}" style="color:#1B3F6E">$0</b></span>
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
        <div>
            <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin:0">Bolsas: crudo → retirado → redistribuido</h2>
            @if(($resumen['total_pendiente'] ?? 0) > 0.5)
            <div style="font-size:11.5px;color:#B45309;margin-top:2px">Pendiente por distribuir en Contabilidad: <b>{{ $fmt($resumen['total_pendiente']) }}</b> (queda en la cuenta 14, sin llevar a costo real todavía).</div>
            @endif
        </div>
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
    <div class="fila-pct" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span class="sw" style="width:10px;height:10px;border-radius:3px;flex:none;background:#94A3B8"></span>
        <select data-name="un" style="flex:1;min-width:200px;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:12px">
            @foreach($bolsas as $b)<option value="{{ $b->codigo }}">{{ $b->codigo }} · {{ \Illuminate\Support\Str::limit($b->nombre,28) }}</option>@endforeach
        </select>
        <input type="number" step="0.01" min="0" max="100" data-name="pct" value="" style="width:74px;padding:7px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:12px;text-align:right"><span style="color:#6B7280;font-weight:600">%</span>
        <span class="val-dist" style="margin-left:auto;font-size:13px;color:#1B3F6E;font-weight:700;min-width:110px;text-align:right"></span>
        <a href="#" onclick="this.closest('.fila-pct').remove();return false" style="color:#DC2626;text-decoration:none;font-size:16px">✕</a>
    </div>
</template>

<script>
const BCOL = @json($coloresBolsa ?? []);
const BNOM = @json($bolsas->pluck('nombre','codigo'));
const fmtCOP = n => '$' + Math.round(n).toLocaleString('es-CO');
const colorBolsa = c => BCOL[c] || '#94A3B8';
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
    recalcPct(cedula);
}
function baseMonto(cedula){
    const inp = document.querySelector(`.monto-dist[data-ced="${cedula}"]`);
    if (inp){
        const total = parseFloat(inp.dataset.total || 0);
        let v = parseFloat(inp.value || 0); if (isNaN(v)) v = 0;
        return { base: Math.max(0, Math.min(v, total)), total };
    }
    const cont = document.querySelector(`[id^="filas-"][data-ced="${cedula}"]`);
    const total = cont ? parseFloat(cont.dataset.total || 0) : 0;
    return { base: total, total };
}
function setMontoTotal(cedula){
    const inp = document.querySelector(`.monto-dist[data-ced="${cedula}"]`);
    if (inp){ inp.value = inp.dataset.total; recalcPct(cedula); }
}
function recalcPct(cedula){
    const { base, total } = baseMonto(cedula);
    const pend = document.querySelector(`.pendiente-dist[data-ced="${cedula}"]`);
    if (pend) pend.textContent = fmtCOP(Math.max(0, total - base));
    let suma = 0;
    const segs = [];
    document.querySelectorAll(`[id^="filas-"][data-ced="${cedula}"]`).forEach(cont => {
        cont.querySelectorAll('.fila-pct').forEach(row => {
            const sel = row.querySelector('select');
            const inp = row.querySelector('input[name$="[pct]"]');
            const pct = parseFloat((inp && inp.value) || 0);
            suma += pct;
            const col = colorBolsa(sel && sel.value);
            const sw = row.querySelector('.sw'); if (sw) sw.style.background = col;
            const span = row.querySelector('.val-dist');
            if (span) span.textContent = (base > 0 && pct > 0) ? fmtCOP(base * pct / 100) : '';
            if (pct > 0) segs.push({ col, pct });
        });
    });
    // barra visual
    const bar = document.querySelector(`.barwrap[data-ced="${cedula}"]`);
    if (bar) bar.innerHTML = segs.map(s => `<div style="height:100%;width:${Math.min(s.pct,100)}%;background:${s.col};transition:width .18s ease"></div>`).join('');
    // indicador de suma 100%
    const pill = document.querySelector(`.suma-pill[data-ced="${cedula}"]`);
    if (pill){
        const ok = Math.abs(suma-100) < 0.05;
        pill.textContent = ok ? '✓ Suma 100%' : ('⚠ Suma ' + (Math.round(suma*10)/10) + '% (debe ser 100%)');
        pill.style.background = ok ? '#DCFCE7' : '#FEE2E2';
        pill.style.color = ok ? '#15803D' : '#DC2626';
    }
    const dist = document.querySelector(`.distribuido[data-ced="${cedula}"]`);
    if (dist) dist.textContent = fmtCOP(base * Math.min(suma,100) / 100);
    recalcResumen();
}
function recalcResumen(){
    const agg = {}; let gran = 0;
    document.querySelectorAll(`[id^="filas-"]`).forEach(cont => {
        const cedula = cont.dataset.ced;
        const { base } = baseMonto(cedula);
        cont.querySelectorAll('.fila-pct').forEach(row => {
            const sel = row.querySelector('select');
            const inp = row.querySelector('input[name$="[pct]"]');
            const pct = parseFloat((inp && inp.value) || 0);
            if (!sel || pct <= 0 || base <= 0) return;
            const v = base * pct / 100;
            agg[sel.value] = (agg[sel.value] || 0) + v;
            gran += v;
        });
    });
    const tot = document.getElementById('s-total');
    if (tot) tot.textContent = fmtCOP(gran);
    const chips = document.getElementById('s-chips');
    if (chips) chips.innerHTML = Object.entries(agg).sort((a,b)=>b[1]-a[1]).map(([k,v]) => {
        const col = colorBolsa(k);
        const nom = BNOM[k] ? (' · ' + BNOM[k]) : '';
        return `<span title="${k}${nom}" style="font-size:12px;font-weight:700;padding:5px 11px;border-radius:20px;display:flex;gap:7px;align-items:center;background:${col}22;color:${col}"><span style="width:9px;height:9px;border-radius:50%;background:${col}"></span>${k}: ${fmtCOP(v)}</span>`;
    }).join('');
}
// Cálculo inicial de todas las personas (barras, indicadores y resumen).
document.querySelectorAll(`[id^="filas-"]`).forEach(c => recalcPct(c.dataset.ced));
</script>
@endsection
