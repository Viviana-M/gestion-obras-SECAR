@extends('layouts.app')

@section('title', 'Otros costos · consulta')

@section('content')
@php
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $depLabel = ['mantenimiento' => 'Mantenimiento (MT)', 'instalaciones' => 'Instalaciones (IN)'];
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $periodo = ($meses[$mes] ?? $mes).' '.$anio;
@endphp

<x-page-banner title="Otros costos · {{ $periodo }}" icon="🏢" :badge="$depLabel[$dep] ?? $dep">
    Distribución de <b>áreas / bolsas</b>: valor a cargar en el mes por cuenta, con su tercero y observación.
    <x-slot:actions>
        <a href="{{ route('operativo.distribucion.consultas') }}" style="font-size:13px;padding:8px 16px;background:white;border:1px solid #E5E7EB;border-radius:8px;color:#6B7280;text-decoration:none">← Volver</a>
    </x-slot:actions>
</x-page-banner>

<div class="card" style="padding:0;overflow:hidden">
    @if(count($lineas) === 0)
        <div style="text-align:center;color:#9CA3AF;padding:2rem">No hay cuentas con monto a distribuir para este período.</div>
    @else
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:900px">
            <thead>
                <tr style="background:#1B3F6E;color:white;text-align:left">
                    <th style="padding:9px 12px">UN</th>
                    <th style="padding:9px 12px">Nombre UN</th>
                    <th style="padding:9px 12px">Cuenta</th>
                    <th style="padding:9px 12px">Nombre cuenta</th>
                    <th style="padding:9px 12px">Tercero</th>
                    <th style="padding:9px 12px;text-align:right">Saldo</th>
                    <th style="padding:9px 12px;text-align:right">A cargar en el mes</th>
                    <th style="padding:9px 12px">Observaciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lineas as $l)
                <tr style="border-bottom:1px solid #E5E7EB">
                    <td style="padding:8px 12px;font-family:monospace">{{ $l['un_codigo'] }}</td>
                    <td style="padding:8px 12px;color:#6B7280">{{ $l['un_nombre'] }}</td>
                    <td style="padding:8px 12px;font-family:monospace">{{ $l['cuenta_14'] }}</td>
                    <td style="padding:8px 12px;color:#6B7280">{{ $l['nombre'] }}</td>
                    <td style="padding:8px 12px;color:#6B7280">{{ $l['tercero'] }}</td>
                    <td style="padding:8px 12px;text-align:right;color:#854D0E">{{ $fmt($l['saldo']) }}</td>
                    <td style="padding:8px 12px;text-align:right;font-weight:600;color:#B45309">{{ $fmt($l['monto_distribuir']) }}</td>
                    <td style="padding:8px 12px;color:#374151">{{ $l['observaciones'] }}</td>
                </tr>
                @endforeach
                <tr style="background:#FFFBEB;font-weight:700;border-top:2px solid #FDE68A">
                    <td colspan="6" style="padding:9px 12px;text-align:right;color:#92400E">Total a cargar en el mes</td>
                    <td style="padding:9px 12px;text-align:right;color:#B45309">{{ $fmt($total) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
