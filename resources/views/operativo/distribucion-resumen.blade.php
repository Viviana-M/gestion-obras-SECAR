@extends('layouts.app')

@section('title', 'Resumen de distribución')

@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $periodo = ($nombresMes[$mes] ?? '').' '.$anio;
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $pct = fn($p, $t) => ($t != 0) ? number_format($p / $t * 100, 1, ',', '.').'%' : '—';

    // Colores por categoría (como tu Excel)
    $colCat = ['MOI'=>'#DCFCE7','EQU-MAT-SUM'=>'#DCFCE7','MOE'=>'#DCFCE7','MOFIJAOPER'=>'#DCFCE7','OTROS COSTO'=>'#DCFCE7'];
@endphp

@section('content')

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:10px">
    <h1 class="page-title" style="margin-bottom:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        Resumen de distribución · {{ $periodo }}
        @if(!empty($departamento))
        <span style="font-size:12px;font-weight:600;padding:3px 12px;border-radius:10px;background:#EEF2FF;color:#4338CA">
            {{ ucfirst($departamento) }}
        </span>
        @endif
    </h1>
    <div style="display:flex;gap:8px">
        <a href="{{ route('operativo.distribucion') }}" style="padding:8px 16px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none">← Volver</a>
        <form method="POST" action="{{ route('operativo.distribucion.resumen') }}" style="display:inline">
            @csrf
            <input type="hidden" name="mes" value="{{ $mes }}">
            <input type="hidden" name="anio" value="{{ $anio }}">
            <input type="hidden" name="departamento" value="{{ $departamento ?? '' }}">
            <input type="hidden" name="descargar" value="1">
            <button type="submit" style="padding:8px 16px;background:white;border:1px solid #15803D;border-radius:8px;font-size:13px;color:#15803D;cursor:pointer">⬇ Descargar Excel</button>
        </form>
    </div>
</div>

<p style="font-size:12px;color:#6B7280;margin-bottom:1rem">
    Costo total del mes por categoría = lo que ya estaba en la cuenta 6 del mes + lo que se aplica ahora de la cuenta 14. Ingreso = facturación del mes.
</p>

<div class="card" style="overflow-x:auto">
    <table id="tabla-resumen" style="width:100%;border-collapse:collapse;font-size:12px;min-width:760px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="text-align:left;padding:8px 10px;font-size:11px">ÍTEM</th>
                @foreach($tabla as $tk => $t)
                    <th style="text-align:right;padding:8px 10px;font-size:11px">{{ mb_strtoupper($tipos[$tk]) }}</th>
                    <th style="text-align:right;padding:8px 10px;font-size:11px">PART.</th>
                @endforeach
                <th style="text-align:right;padding:8px 10px;font-size:11px">TOTAL</th>
                <th style="text-align:right;padding:8px 10px;font-size:11px">PART.</th>
            </tr>
        </thead>
        <tbody>
            {{-- INGRESO --}}
            <tr style="background:#D6E4F7;font-weight:600">
                <td style="padding:7px 10px">INGRESO</td>
                @foreach($tabla as $tk => $t)
                    <td style="padding:7px 10px;text-align:right">{{ $fmt($t['ingreso']) }}</td>
                    <td style="padding:7px 10px;text-align:right;color:#6B7280"></td>
                @endforeach
                <td style="padding:7px 10px;text-align:right">{{ $fmt($todo['ingreso']) }}</td>
                <td style="padding:7px 10px;text-align:right;color:#6B7280"></td>
            </tr>

            {{-- CATEGORÍAS --}}
            @foreach($categorias as $ck => $cl)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:6px 10px;background:{{ $colCat[$ck] ?? '#fff' }}">{{ $ck }}</td>
                @foreach($tabla as $tk => $t)
                    <td style="padding:6px 10px;text-align:right;background:{{ $colCat[$ck] ?? '#fff' }}">{{ $fmt($t['cat'][$ck]) }}</td>
                    <td style="padding:6px 10px;text-align:right;color:#374151;font-weight:600">{{ $pct($t['cat'][$ck], $t['ingreso']) }}</td>
                @endforeach
                <td style="padding:6px 10px;text-align:right;background:{{ $colCat[$ck] ?? '#fff' }}">{{ $fmt($todo['cat'][$ck]) }}</td>
                <td style="padding:6px 10px;text-align:right;color:#374151;font-weight:600">{{ $pct($todo['cat'][$ck], $todo['ingreso']) }}</td>
            </tr>
            @endforeach

            {{-- TOTAL COSTO --}}
            <tr style="background:#BBF7D0;font-weight:700">
                <td style="padding:7px 10px">TOTAL COSTO</td>
                @foreach($tabla as $tk => $t)
                    <td style="padding:7px 10px;text-align:right">{{ $fmt($t['costo_total']) }}</td>
                    <td style="padding:7px 10px;text-align:right">{{ $pct($t['costo_total'], $t['ingreso']) }}</td>
                @endforeach
                <td style="padding:7px 10px;text-align:right">{{ $fmt($todo['costo_total']) }}</td>
                <td style="padding:7px 10px;text-align:right">{{ $pct($todo['costo_total'], $todo['ingreso']) }}</td>
            </tr>

            {{-- MC ($) --}}
            <tr style="font-weight:600">
                <td style="padding:7px 10px">MC ($)</td>
                @foreach($tabla as $tk => $t)
                    <td style="padding:7px 10px;text-align:right;color:{{ $t['mc_pesos'] >= 0 ? '#15803D' : '#DC2626' }}">{{ $fmt($t['mc_pesos']) }}</td>
                    <td style="padding:7px 10px;text-align:right"></td>
                @endforeach
                <td style="padding:7px 10px;text-align:right;color:{{ $todo['mc_pesos'] >= 0 ? '#15803D' : '#DC2626' }}">{{ $fmt($todo['mc_pesos']) }}</td>
                <td style="padding:7px 10px;text-align:right"></td>
            </tr>

            {{-- MC % --}}
            <tr style="background:#F3F4F6;font-weight:700">
                <td style="padding:7px 10px">MC %</td>
                @foreach($tabla as $tk => $t)
                    <td style="padding:7px 10px;text-align:right;color:{{ ($t['mc_pct'] ?? 0) >= 0 ? '#15803D' : '#DC2626' }}">{{ $t['mc_pct'] === null ? '—' : $t['mc_pct'].'%' }}</td>
                    <td style="padding:7px 10px;text-align:right"></td>
                @endforeach
                <td style="padding:7px 10px;text-align:right;color:{{ ($todo['mc_pct'] ?? 0) >= 0 ? '#15803D' : '#DC2626' }}">{{ $todo['mc_pct'] === null ? '—' : $todo['mc_pct'].'%' }}</td>
                <td style="padding:7px 10px;text-align:right"></td>
            </tr>
        </tbody>
    </table>
</div>

<script>
const PERIODO = @json($periodo);

function descargarResumen(){
    const tabla = document.getElementById('tabla-resumen');
    const filas = [];
    tabla.querySelectorAll('tr').forEach(tr => {
        const celdas = [];
        tr.querySelectorAll('th,td').forEach(td => {
            celdas.push(td.innerText.trim());
        });
        filas.push(celdas);
    });

    const esc = v => {
        v = String(v == null ? '' : v);
        if(/[";\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
        return v;
    };
    const csv = filas.map(f => f.map(esc).join(';')).join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'resumen_distribucion_' + String(PERIODO || '').replace(/\s+/g, '_') + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

@if($descargar)
document.addEventListener('DOMContentLoaded', descargarResumen);
@endif
</script>
@endsection