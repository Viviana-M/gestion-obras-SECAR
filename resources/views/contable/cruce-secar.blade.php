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
    // Formato colombiano con 2 decimales: $ 56.721.189,22
    $fmt = fn ($n) => '$ '.number_format((float) $n, 2, ',', '.');
    $col = fn ($n) => abs($n) < 0.005 ? '#6B7280' : ($n < 0 ? '#DC2626' : '#15803D');
@endphp

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:1040px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Código</th>
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:right">Débito</th>
                <th style="padding:10px 14px;text-align:right">Crédito</th>
                <th style="padding:10px 14px;text-align:right">Saldo</th>
                <th style="padding:10px 14px;text-align:right;background:#16335c;color:#B9C7DE;font-weight:500">Saldo SECAR</th>
                <th style="padding:10px 14px;text-align:right;background:#16335c;color:#B9C7DE;font-weight:500">Saldo terceros</th>
                <th style="padding:10px 14px;text-align:right;background:#16335c;color:#B9C7DE;font-weight:500">Saldo neto</th>
                <th style="padding:10px 14px;text-align:center;background:#16335c;color:#B9C7DE;font-weight:500">Marca</th>
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
                {{-- Columnas principales: la propia cuenta 14 --}}
                <td style="padding:9px 14px;text-align:right;color:#374151">{{ $fmt($f['debito']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:#374151">{{ $fmt($f['credito']) }}</td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:{{ $col($f['saldo']) }}">{{ $fmt($f['saldo']) }}</td>
                {{-- Columnas secundarias: cruce con SECAR --}}
                <td style="padding:9px 14px;text-align:right;color:{{ $col($f['saldo_secar']) }};background:#F8FAFC">{{ $fmt($f['saldo_secar']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:{{ $col($f['saldo_terceros']) }};background:#F8FAFC">{{ $fmt($f['saldo_terceros']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:{{ $col($f['saldo_neto']) }};background:#F8FAFC">{{ $fmt($f['saldo_neto']) }}</td>
                <td style="padding:9px 14px;text-align:center;background:#F8FAFC">
                    @if($f['marca'] === 'Pendiente real')
                    <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:10px;background:#FEF2F2;color:#DC2626">Pendiente real</span>
                    @else
                    <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:10px;background:#F0FDF4;color:#15803D">Se netea a ~$0</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="10" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay obras con saldo en la cuenta 14. 🎉</td></tr>
            @endforelse
        </tbody>
        @if(count($filas))
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td colspan="3" style="padding:10px 14px;text-align:right;color:#374151">TOTAL ({{ count($filas) }} obras)</td>
                <td style="padding:10px 14px;text-align:right;color:#374151">{{ $fmt($totalDebito) }}</td>
                <td style="padding:10px 14px;text-align:right;color:#374151">{{ $fmt($totalCredito) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalSaldo) }}">{{ $fmt($totalSaldo) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalSecar) }};background:#F8FAFC">{{ $fmt($totalSecar) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalTerceros) }};background:#F8FAFC">{{ $fmt($totalTerceros) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $col($totalNeto) }};background:#F8FAFC">{{ $fmt($totalNeto) }}</td>
                <td style="background:#F8FAFC"></td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
<p style="font-size:11px;color:#9CA3AF;margin-top:8px"><b>Débito / Crédito / Saldo</b> son de la propia cuenta 14 de la obra. Las columnas de fondo gris (SECAR / terceros / neto) son el cruce con el tercero SECAR.</p>
@endsection
