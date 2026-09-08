@extends('layouts.app')

@section('title', 'MO Apoyo administrativo y operativo')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
@endphp

<x-page-banner title="MO Apoyo administrativo y operativo" icon="🔀">
    Mano de obra de <b>apoyo administrativo y operativo</b>. La distribución por unidad de negocio la hace
    <b>Nómina</b> y ya viene en el archivo de cierre; Contabilidad no asigna porcentajes. El módulo muestra esa MO
    por UN y genera el <b>plano de reclasificación 14→61</b>: acredita la cuenta 14 (conservando los terceros del ERP)
    y debita la cuenta 61 a nombre de la persona, en la misma UN.
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

{{-- La lista de personas se administra en Administración → Mano de obra directa --}}
<div class="card" style="padding:12px 16px;margin-bottom:1rem;background:#F8FAFC">
    <p style="font-size:12px;color:#6B7280;margin:0">Las personas de mano de obra directa se administran en <b>Administración → Mano de obra directa</b>. Aquí solo se ven sus costos y se descarga el plano de reclasificación.</p>
</div>

{{-- Diagnóstico: terceros de MO en bolsas que NO cruzan con el maestro --}}
@if(!empty($sinCruzar))
<div class="card" style="padding:14px 16px;margin-bottom:1rem;border-left:4px solid #D97706">
    <h2 style="font-size:13px;font-weight:700;color:#B45309;margin:0 0 4px">⚠ Terceros con mano de obra en bolsas que no están en la lista</h2>
    <p style="font-size:11.5px;color:#6B7280;margin:0 0 8px">Si alguno de estos es mano de obra directa, agrégalo en <b>Administración → Mano de obra directa</b> (con su cédula o el mismo nombre) para que entre a la reclasificación.</p>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead><tr style="text-align:left;color:#6B7280;border-bottom:1px solid #E5E7EB">
            <th style="padding:5px 8px">Documento</th><th style="padding:5px 8px">Nombre (razón social)</th><th style="padding:5px 8px;text-align:right">Monto MO</th>
        </tr></thead>
        <tbody>
        @foreach(array_slice($sinCruzar, 0, 50) as $t)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:5px 8px;font-family:monospace">{{ $t['doc'] !== '' ? $t['doc'] : '—' }}</td>
                <td style="padding:5px 8px">{{ $t['nombre'] !== '' ? $t['nombre'] : '—' }}</td>
                <td style="padding:5px 8px;text-align:right">{{ $fmt($t['monto']) }}</td>
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
    <p style="font-size:11.5px;color:#6B7280;margin:0 0 8px">La seguridad social sale de la <b>cuenta 14</b> del fondo y la autoliquidación solo dice cómo atribuirla a cada persona. Si no coinciden, revísalo.</p>
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

{{-- Resumen + descarga del plano de reclasificación --}}
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;padding:12px 16px;border-bottom:1px solid #E5E7EB;flex-wrap:wrap;gap:8px">
        <div>
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF;font-weight:700">Reclasificación 14 → 61 · {{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</div>
            <div style="font-size:12px;color:#6B7280;margin-top:2px">Total a reclasificar a costo real: <b style="font-size:16px;color:#15803D">{{ $fmt($resumen['total_reclasificado']) }}</b></div>
        </div>
        <form method="GET" action="{{ route('contable.redistribucion-mo.plano') }}" style="display:flex;gap:8px;align-items:flex-end;margin:0">
            <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            <div><label style="font-size:11px;color:#6B7280;display:block;margin-bottom:2px">N° doc</label>
                <input type="number" name="documento" min="1" value="1" style="width:90px;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px"></div>
            <button type="submit" style="padding:8px 14px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">⬇ Plano (Excel)</button>
        </form>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:560px">
        <thead>
            <tr style="background:#1B3F6E;color:white;text-align:right">
                <th style="padding:9px 12px;text-align:left">Bolsa (UN)</th>
                <th style="padding:9px 12px">MO cruda (cuenta 14)</th>
                <th style="padding:9px 12px">Reclasificado a 61 (estas personas)</th>
                <th style="padding:9px 12px">Queda en cuenta 14</th>
            </tr>
        </thead>
        <tbody>
            @forelse($resumen['filas'] as $f)
            <tr style="border-bottom:1px solid #F3F4F6;text-align:right">
                <td style="padding:8px 12px;text-align:left;font-family:monospace;color:#1B3F6E">{{ $f['un'] }} <span style="color:#9CA3AF">{{ \Illuminate\Support\Str::limit($f['nombre'],24) }}</span></td>
                <td style="padding:8px 12px">{{ $fmt($f['crudo']) }}</td>
                <td style="padding:8px 12px;color:#15803D">{{ $f['reclasificado'] > 0 ? $fmt($f['reclasificado']) : '—' }}</td>
                <td style="padding:8px 12px">{{ $fmt($f['queda']) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay mano de obra en bolsas para este período.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700;text-align:right">
                <td style="padding:9px 12px;text-align:left;color:#1B3F6E">Total</td>
                <td style="padding:9px 12px">{{ $fmt($resumen['total_crudo']) }}</td>
                <td style="padding:9px 12px;color:#15803D">{{ $fmt($resumen['total_reclasificado']) }}</td>
                <td style="padding:9px 12px">{{ $fmt($resumen['total_crudo'] - $resumen['total_reclasificado']) }}</td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Personas: costo por persona y su distribución por UN (solo lectura; viene del cierre) --}}
<div class="card" style="padding:0;overflow-x:auto;margin-bottom:1rem">
    <div style="padding:12px 16px;border-bottom:1px solid #E5E7EB">
        <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin:0">Personas de apoyo administrativo y operativo · {{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</h2>
    </div>
    @forelse($personas as $per)
    <div style="padding:16px;border-bottom:1px solid #F3F4F6">
        <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
            <div>
                <div style="font-weight:700;font-size:15px;color:#1B3F6E">{{ $per['nombre'] }}</div>
                <div style="font-size:12px;color:#9CA3AF;font-family:monospace">CC {{ $per['cedula'] }}</div>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <span style="font-size:12px;padding:5px 11px;border-radius:10px;background:#F3F4F6;font-weight:600;color:#6B7280">MO directa <b style="color:#374151">{{ $fmt($per['directo']) }}</b></span>
                <span style="font-size:12px;padding:5px 11px;border-radius:10px;background:#F3F4F6;font-weight:600;color:#6B7280">Seg. social <b style="color:#374151">{{ $fmt($per['ss']) }}</b></span>
                <span title="Total de MO de la persona en la cuenta 14 (se reclasifica a la 61)" style="font-size:12px;padding:5px 11px;border-radius:10px;background:#E5EEF8;font-weight:700;color:#1B3F6E">Total <b>{{ $fmt($per['total']) }}</b></span>
            </div>
        </div>
        @if(!empty($per['dist_un']))
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <span style="font-size:11px;color:#6B7280">Distribución por UN (del cierre):</span>
            @foreach($per['dist_un'] as $du)
                <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px;background:#EEF2F8;color:#1B3F6E">{{ $du['un'] }} <b>{{ $fmt($du['monto']) }}</b></span>
            @endforeach
        </div>
        @else
        <div style="margin-top:8px;font-size:11.5px;color:#9CA3AF">Sin mano de obra en bolsas para este período.</div>
        @endif
    </div>
    @empty
    <div style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay personas de mano de obra directa. Agrégalas en Administración → Mano de obra directa.</div>
    @endforelse
</div>
@endsection
