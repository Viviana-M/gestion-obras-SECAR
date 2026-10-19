@extends('layouts.app')

@section('title', 'Resumen de distribución')

@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $periodo = ($nombresMes[$mes] ?? '').' '.$anio;
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
@endphp

@section('content')

<x-page-banner title="Resumen de distribución · {{ $periodo }}" icon="📊" :badge="!empty($departamento) ? ucfirst($departamento) : null">
    Ingreso, costos distribuidos y rentabilidad por tipo de proyecto.
    <x-slot:actions>
        <a href="{{ route('operativo.distribucion') }}" style="padding:8px 16px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none">← Volver</a>
        <form method="POST" action="{{ route('operativo.distribucion.resumen') }}" style="display:inline">
            @csrf
            <input type="hidden" name="mes" value="{{ $mes }}">
            <input type="hidden" name="anio" value="{{ $anio }}">
            <input type="hidden" name="departamento" value="{{ $departamento ?? '' }}">
            <input type="hidden" name="descargar" value="1">
            {{-- Mismo payload que produjo esta pantalla: aplicar + asignaciones de bolsa.
                 Garantiza que el Excel traiga EXACTAMENTE los mismos totales. --}}
            @foreach(($aplicar ?? []) as $cod => $cuentas)
                @foreach((array) $cuentas as $c14 => $m)
                    <input type="hidden" name="aplicar[{{ $cod }}][{{ $c14 }}]" value="{{ $m }}">
                @endforeach
            @endforeach
            @foreach(($asignBolsa ?? []) as $cod => $porBolsa)
                @foreach((array) $porBolsa as $bolsa => $m)
                    <input type="hidden" name="asignacion_bolsa[{{ $cod }}][{{ $loop->parent->index }}_{{ $loop->index }}][bolsa]" value="{{ $bolsa }}">
                    <input type="hidden" name="asignacion_bolsa[{{ $cod }}][{{ $loop->parent->index }}_{{ $loop->index }}][monto]" value="{{ $m }}">
                @endforeach
            @endforeach
            <button type="submit" style="padding:8px 16px;background:white;border:1px solid #15803D;border-radius:8px;font-size:13px;color:#15803D;cursor:pointer">⬇ Descargar Excel</button>
        </form>
    </x-slot:actions>
</x-page-banner>

<p style="font-size:12px;color:#6B7280;margin-bottom:1rem">
    Por tipo de proyecto: <b>Ingreso</b> = facturación del mes · <b>Costos</b> = todo lo distribuido al costo
    (inventario + bolsa + mano de obra + provisión) · <b>Rentabilidad %</b> = margen de contribución.
</p>

<div class="card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:520px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="text-align:left;padding:10px 12px;font-size:11px">TIPO</th>
                <th style="text-align:right;padding:10px 12px;font-size:11px">INGRESO</th>
                <th style="text-align:right;padding:10px 12px;font-size:11px">COSTOS</th>
                <th style="text-align:right;padding:10px 12px;font-size:11px">RENTABILIDAD %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($tabla as $tk => $t)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:9px 12px;font-weight:500">{{ $tipos[$tk] ?? $tk }}</td>
                <td style="padding:9px 12px;text-align:right">{{ $fmt($t['ingreso']) }}</td>
                <td style="padding:9px 12px;text-align:right">{{ $fmt($t['costo_total']) }}</td>
                <td style="padding:9px 12px;text-align:right;font-weight:600;color:{{ ($t['mc_pct'] ?? 0) >= 0 ? '#15803D' : '#DC2626' }}">{{ $t['mc_pct'] === null ? '—' : $t['mc_pct'].'%' }}</td>
            </tr>
            @endforeach
            {{-- TOTAL --}}
            <tr style="background:#BBF7D0;font-weight:700;border-top:2px solid #86EFAC">
                <td style="padding:10px 12px">Total</td>
                <td style="padding:10px 12px;text-align:right">{{ $fmt($todo['ingreso']) }}</td>
                <td style="padding:10px 12px;text-align:right">{{ $fmt($todo['costo_total']) }}</td>
                <td style="padding:10px 12px;text-align:right;color:{{ ($todo['mc_pct'] ?? 0) >= 0 ? '#15803D' : '#DC2626' }}">{{ $todo['mc_pct'] === null ? '—' : $todo['mc_pct'].'%' }}</td>
            </tr>
        </tbody>
    </table>
</div>

@endsection