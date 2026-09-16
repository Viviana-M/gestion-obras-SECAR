@extends('layouts.app')

@section('title', 'Cruce cuenta 14 vs SECAR')

@section('content')
<x-page-banner title="Cruce cuenta 14 vs SECAR (neteo por UN)" icon="🔎">
    Saldo de la cuenta 14 ("Costos por aplicar") por obra, separando lo que está contra el propio
    tercero <b>SECAR</b> (reversiones que inflan el saldo) de lo que está contra <b>terceros reales</b>.
    <b>Saldo neto = SECAR + terceros</b> es el pendiente real después de netear SECAR.
    <x-slot:actions>
        <a href="{{ route('contable.cruce-secar.excel') }}" class="btn-banner">⬇ Exportar Excel</a>
    </x-slot:actions>
</x-page-banner>

@php
    $fmt = fn ($n) => '$'.number_format($n, 0, ',', '.');
    $col = fn ($n) => $n < 0 ? '#DC2626' : '#15803D';
@endphp

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:900px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Código</th>
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:right">Saldo SECAR</th>
                <th style="padding:10px 14px;text-align:right">Saldo terceros</th>
                <th style="padding:10px 14px;text-align:right">Saldo neto</th>
                <th style="padding:10px 14px;text-align:center">Marca</th>
            </tr>
        </thead>
        <tbody>
            @forelse($filas as $f)
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:9px 14px;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $f['codigo'] }}</td>
                <td style="padding:9px 14px;color:#374151">{{ $f['nombre'] ?: '—' }}</td>
                <td style="padding:9px 14px;text-align:center">
                    <span style="font-size:11px;color:{{ $f['estado'] === 'Inactiva' ? '#B45309' : ($f['estado'] === 'Activa' ? '#15803D' : '#9CA3AF') }}">{{ $f['estado'] }}</span>
                </td>
                <td style="padding:9px 14px;text-align:right;font-weight:600;color:{{ $col($f['saldo_secar']) }}">{{ $fmt($f['saldo_secar']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:{{ $col($f['saldo_terceros']) }}">{{ $fmt($f['saldo_terceros']) }}</td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:{{ $col($f['saldo_neto']) }}">{{ $fmt($f['saldo_neto']) }}</td>
                <td style="padding:9px 14px;text-align:center">
                    @if($f['marca'] === 'Pendiente real')
                    <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:10px;background:#FEF2F2;color:#DC2626">Pendiente real</span>
                    @else
                    <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:10px;background:#F0FDF4;color:#15803D">Se netea a ~$0</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="7" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay obras con saldo contra SECAR en la cuenta 14. 🎉</td></tr>
            @endforelse
        </tbody>
        @if(count($filas))
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td colspan="3" style="padding:10px 14px;text-align:right;color:#374151">TOTAL ({{ count($filas) }} obras)</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalSecar) }}">{{ $fmt($totalSecar) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalTerceros) }}">{{ $fmt($totalTerceros) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalNeto) }}">{{ $fmt($totalNeto) }}</td>
                <td></td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
@endsection
