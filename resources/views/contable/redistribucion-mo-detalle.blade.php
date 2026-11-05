@extends('layouts.app')

@section('title', 'Terceros del plano MO apoyo')

@section('content')
@php
    $fmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
@endphp

<x-page-banner title="Terceros del plano — MO apoyo administrativo y operativo (14 → 61)" icon="👥">
    Estos son <b>exactamente</b> los movimientos que contendrá el plano de reclasificación del período
    <b>{{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</b>: por cada tercero, la cuenta 14 se acredita y la 61 se
    debita en su misma unidad de negocio. Es solo para <b>validar antes de descargar</b> el Excel.
</x-page-banner>

<div style="margin-bottom:12px">
    <a href="{{ route('contable.autoliquidacion.index', ['mes'=>$mes,'anio'=>$anio]) }}"
       style="font-size:13px;color:#2563a8;text-decoration:none">← Volver a Planilla PILA</a>
</div>

<div style="font-size:12px;color:#6B7280;margin-bottom:8px">
    <b>{{ count($movimientos) }}</b> movimiento(s) · total <b>{{ $fmt($total) }}</b>.
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:760px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Tercero</th>
                <th style="padding:10px 14px;text-align:left">Unidad de negocio</th>
                <th style="padding:10px 14px;text-align:left">Cuenta origen (14)</th>
                <th style="padding:10px 14px;text-align:left">Cuenta destino (61)</th>
                <th style="padding:10px 14px;text-align:right">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse($movimientos as $m)
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:9px 14px;color:#374151">{{ $m['tercero_credito'] ?: '—' }}</td>
                <td style="padding:9px 14px;color:#374151">
                    <span style="font-family:monospace;color:#1B3F6E">{{ $m['un'] ?: '—' }}</span>
                    @if(!empty($nombresUn[$m['un']]))<div style="font-size:10px;color:#9CA3AF">{{ \Illuminate\Support\Str::limit($nombresUn[$m['un']], 40) }}</div>@endif
                </td>
                <td style="padding:9px 14px;font-family:monospace;color:#B45309">{{ $m['cuenta_credito'] }}</td>
                <td style="padding:9px 14px;font-family:monospace;color:#15803D">{{ $m['cuenta_debito'] }}</td>
                <td style="padding:9px 14px;text-align:right;font-weight:600;color:#374151">{{ $fmt($m['monto']) }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay mano de obra de estas personas para reclasificar en este período.</td></tr>
            @endforelse
        </tbody>
        @if(count($movimientos) > 0)
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td colspan="4" style="padding:10px 14px;text-align:right;color:#1B3F6E">TOTAL</td>
                <td style="padding:10px 14px;text-align:right;color:#374151">{{ $fmt($total) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
@endsection
