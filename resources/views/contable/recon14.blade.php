@extends('layouts.app')

@section('title', 'Reconciliación cuenta 14 (Sistema vs ERP)')

@section('content')
<x-page-banner title="Reconciliación cuenta 14 — Sistema vs ERP" icon="⚖️">
    Compara el saldo de la cuenta 14 ("Costos por aplicar") de cada obra en el <b>sistema</b> contra el
    <b>archivo del ERP</b> para detectar obras que no cuadran (meses faltantes, cargues viejos o incompletos).
    Haz clic en una obra con ❌ para ver el detalle por mes y saber cuál recargar.
    @isset($recon)
    <x-slot:actions>
        <a href="{{ route('contable.recon14.excel') }}" class="btn-banner">⬇ Exportar Excel</a>
    </x-slot:actions>
    @endisset
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

{{-- SUBIR ERP --}}
<div class="card" style="padding:14px;margin-bottom:1rem">
    <div style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:4px">Cargar auxiliar de cuenta 14 del ERP</div>
    <div style="font-size:12px;color:#9CA3AF;margin-bottom:10px">
        Excel exportado del ERP. El reporte reconoce las columnas por su nombre (<b>U.N.</b>, <b>Débitos</b>, <b>Créditos</b>, <b>Neto</b>, <b>Fecha</b>, <b>Nit movto.</b>) e ignora "Gran total" y subtotales.
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <form method="POST" action="{{ route('contable.recon14.store') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0">
            @csrf
            <input type="file" name="archivo" accept=".xlsx,.xls" required style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white">
            <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Comparar</button>
        </form>
        @isset($recon)
        <form method="POST" action="{{ route('contable.recon14.limpiar') }}" style="margin:0">@csrf
            <button type="submit" style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;cursor:pointer">Limpiar</button>
        </form>
        @endisset
    </div>
</div>

@isset($recon)
@php
    $fmt = fn ($n) => '$'.number_format($n, 0, ',', '.');
    $col = fn ($n) => abs($n) < 0.5 ? '#6B7280' : ($n < 0 ? '#DC2626' : '#15803D');
    $tErp = array_sum(array_column($recon['filas'], 'saldo_erp'));
    $tSis = array_sum(array_column($recon['filas'], 'saldo_sistema'));
    $tDif = array_sum(array_column($recon['filas'], 'diferencia'));
    $nOk  = count(array_filter($recon['filas'], fn ($f) => $f['cuadra']));
    $nBad = count($recon['filas']) - $nOk;
@endphp

<div style="font-size:12px;color:#6B7280;margin-bottom:8px">
    Archivo: <b>{{ $recon['archivo'] }}</b> · generado {{ $recon['generado'] }} ·
    <span style="color:#15803D;font-weight:600">{{ $nOk }} cuadran</span> ·
    <span style="color:#DC2626;font-weight:600">{{ $nBad }} a revisar</span>
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:860px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Código</th>
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:right">Saldo ERP</th>
                <th style="padding:10px 14px;text-align:right">Saldo sistema</th>
                <th style="padding:10px 14px;text-align:right">Diferencia</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse($recon['filas'] as $f)
            @php $tieneDetalle = isset($recon['mes'][$f['codigo']]); @endphp
            <tr style="border-bottom:1px solid #E5E7EB;{{ $tieneDetalle ? 'cursor:pointer' : '' }}"
                @if($tieneDetalle) onclick="verDetalle('{{ $f['codigo'] }}')" title="Ver detalle por mes" @endif>
                <td style="padding:9px 14px;font-family:monospace;font-weight:600;color:#1B3F6E">
                    {{ $f['codigo'] }}
                    @if(!$f['en_sistema'])<span style="font-size:10px;color:#B45309"> · solo ERP</span>@endif
                    @if(!$f['en_erp'])<span style="font-size:10px;color:#B45309"> · solo sistema</span>@endif
                </td>
                <td style="padding:9px 14px;color:#374151">{{ $f['nombre'] ?: '—' }}</td>
                <td style="padding:9px 14px;text-align:right;color:{{ $col($f['saldo_erp']) }}">{{ $fmt($f['saldo_erp']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:{{ $col($f['saldo_sistema']) }}">{{ $fmt($f['saldo_sistema']) }}</td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:{{ $col($f['diferencia']) }}">{{ $fmt($f['diferencia']) }}</td>
                <td style="padding:9px 14px;text-align:center">
                    @if($f['cuadra'])
                    <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:10px;background:#F0FDF4;color:#15803D">✅ Cuadra</span>
                    @else
                    <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:10px;background:#FEF2F2;color:#DC2626">❌ Revisar</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay obras para comparar.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td colspan="2" style="padding:10px 14px;text-align:right;color:#374151">TOTAL</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($tErp) }}">{{ $fmt($tErp) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($tSis) }}">{{ $fmt($tSis) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($tDif) }}">{{ $fmt($tDif) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Panel de detalle por mes (drill-down) --}}
<div id="detalle-mes" style="display:none;margin-top:1rem" class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div style="font-size:14px;font-weight:700;color:#1B3F6E" id="detalle-titulo"></div>
        <button onclick="document.getElementById('detalle-mes').style.display='none'" style="border:none;background:#F3F4F6;width:28px;height:28px;border-radius:8px;cursor:pointer;color:#6B7280">×</button>
    </div>
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:520px">
            <thead>
                <tr style="background:#F3F4F6;color:#374151">
                    <th style="padding:8px 12px;text-align:left">Mes</th>
                    <th style="padding:8px 12px;text-align:right">Neto ERP</th>
                    <th style="padding:8px 12px;text-align:right">Neto sistema</th>
                    <th style="padding:8px 12px;text-align:right">Diferencia</th>
                </tr>
            </thead>
            <tbody id="detalle-cuerpo"></tbody>
        </table>
    </div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:8px">Las filas resaltadas en rojo son los meses donde el sistema no cuadra con el ERP: recarga ese mes.</div>
</div>

<script>
const RECON_MES = @json($recon['mes']);
function fmtMoneda(n){ try { return '$'+Number(n).toLocaleString('es-CO'); } catch(e){ return '$'+n; } }
function verDetalle(cod){
    const data = RECON_MES[cod];
    if(!data) return;
    document.getElementById('detalle-titulo').textContent = 'Detalle por mes · ' + cod;
    const cuerpo = document.getElementById('detalle-cuerpo');
    cuerpo.innerHTML = '';
    data.forEach(function(m){
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #E5E7EB';
        if(m.resaltar){ tr.style.background = '#FEF2F2'; }
        const c = (v) => Math.abs(v) < 0.5 ? '#6B7280' : (v < 0 ? '#DC2626' : '#15803D');
        tr.innerHTML =
            '<td style="padding:7px 12px;font-weight:600;color:#1B3F6E">'+m.mes+(m.resaltar?' ⚠️':'')+'</td>'+
            '<td style="padding:7px 12px;text-align:right;color:'+c(m.erp)+'">'+fmtMoneda(m.erp)+'</td>'+
            '<td style="padding:7px 12px;text-align:right;color:'+c(m.sistema)+'">'+fmtMoneda(m.sistema)+'</td>'+
            '<td style="padding:7px 12px;text-align:right;font-weight:600;color:'+c(m.dif)+'">'+fmtMoneda(m.dif)+'</td>';
        cuerpo.appendChild(tr);
    });
    const panel = document.getElementById('detalle-mes');
    panel.style.display = 'block';
    panel.scrollIntoView({behavior:'smooth', block:'nearest'});
}
</script>
@endisset
@endsection
