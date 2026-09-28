@extends('layouts.app')

@section('title', 'Costo de MO por obra')

@section('content')
@php $fmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.'); @endphp

<x-page-banner title="Costo de mano de obra por obra" icon="📊">
    Total de mano de obra que se carga a cada obra destino del área <b>{{ ucfirst($departamento ?? '') }}</b> en
    <b>{{ $meses[$mes] ?? $mes }} {{ $anio }}</b>. Al desplegar cada obra se ve el desglose por tercero
    y cuenta. La suma de las obras iguala lo asignado de la bolsa.
</x-page-banner>

@if(session('error'))<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>@endif

<div class="card" style="padding:14px 16px;margin-bottom:1rem;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
    <a href="{{ route('operativo.distribucion', ['departamento'=>$departamento,'mes'=>$mes,'anio'=>$anio]) }}"
       style="padding:7px 14px;background:white;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;color:#1B3F6E;text-decoration:none">← Volver a Distribución</a>
    <a href="{{ route('operativo.mano-obra.resumen.excel', ['departamento'=>$departamento,'mes'=>$mes,'anio'=>$anio]) }}"
       style="padding:7px 14px;background:#1B3F6E;color:white;border-radius:8px;font-size:13px;text-decoration:none">⬇ Exportar Excel</a>
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:620px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:right">Total MO</th>
                <th style="padding:10px 14px;text-align:center">Detalle</th>
            </tr>
        </thead>
        <tbody>
            @forelse($resumen as $i => $o)
            <tr style="border-bottom:1px solid #E5E7EB;cursor:pointer" onclick="toggleDet({{ $i }})">
                <td style="padding:9px 14px">
                    <span style="font-family:monospace;font-weight:600;color:#1B3F6E">{{ $o['obra'] }}</span>
                    <div style="font-size:10px;color:#9CA3AF">{{ \Illuminate\Support\Str::limit($nombresObra[$o['obra']] ?? '', 40) }}</div>
                </td>
                <td style="padding:9px 14px;text-align:right;font-weight:600;color:#374151">{{ $fmt($o['total']) }}</td>
                <td style="padding:9px 14px;text-align:center;color:#6B7280">▾</td>
            </tr>
            <tr id="det-{{ $i }}" style="display:none;background:#F9FAFB">
                <td colspan="3" style="padding:0 14px 10px">
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                        <thead><tr style="color:#6B7280">
                            <th style="padding:6px 8px;text-align:left">Tercero</th>
                            <th style="padding:6px 8px;text-align:left">Cuenta 14</th>
                            <th style="padding:6px 8px;text-align:right">Monto</th>
                        </tr></thead>
                        <tbody>
                            @foreach($o['detalle'] as $d)
                            <tr style="border-top:1px solid #E5E7EB">
                                <td style="padding:5px 8px;color:#374151">{{ $d['nombre'] ?: $d['tercero'] }} <span style="color:#9CA3AF;font-family:monospace">{{ $d['tercero'] }}</span></td>
                                <td style="padding:5px 8px;font-family:monospace;color:#854D0E">{{ $d['cuenta'] }}</td>
                                <td style="padding:5px 8px;text-align:right;color:#374151">{{ $fmt($d['monto']) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
            @empty
            <tr><td colspan="3" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay asignaciones de mano de obra en este período.</td></tr>
            @endforelse
        </tbody>
        @if(count($resumen) > 0)
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td style="padding:10px 14px;text-align:right;color:#1B3F6E">TOTAL</td>
                <td style="padding:10px 14px;text-align:right;color:#374151">{{ $fmt($total) }}</td>
                <td></td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

<script>
function toggleDet(i){ const e=document.getElementById('det-'+i); if(e) e.style.display = e.style.display==='none'?'table-row':'none'; }
</script>
@endsection
