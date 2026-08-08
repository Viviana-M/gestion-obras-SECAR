@extends('layouts.app')

@section('title', 'Resumen de distribución')

@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $periodo = ($nombresMes[$mes] ?? '').' '.$anio;
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    // Participación = costo de la categoría / ingreso de la columna
    $pct = fn($p, $t) => ($t != 0) ? number_format($p / $t * 100, 1, ',', '.').'%' : '—';
    $colCat = ['MOI'=>'#DCFCE7','EQU-MAT-SUM'=>'#DCFCE7','MOE'=>'#DCFCE7','MOFIJAOPER'=>'#DCFCE7','OTROS COSTO'=>'#DCFCE7'];

    // Cuántas columnas ocupa la tabla (para el colspan del detalle)
    $numCols = 1 + (count($tabla) + 1) * 2;
@endphp

@section('content')

<x-page-banner title="Resumen de distribución · {{ $periodo }}" icon="📊" :badge="!empty($departamento) ? ucfirst($departamento) : null">
    Costo del mes por categoría y su participación sobre el ingreso.
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
    Costo total del mes por categoría = lo que ya estaba en la cuenta 6 del mes + lo que se aplica ahora de la cuenta 14. Ingreso = facturación del mes.
    <br><span style="color:#9CA3AF">La participación (PART.) es el peso de cada costo sobre el ingreso. Haz clic en un valor de costo para ver su detalle.</span>
</p>

<div class="card" style="overflow-x:auto">
    <table id="tabla-resumen" style="width:100%;border-collapse:collapse;font-size:12px;min-width:760px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="text-align:left;padding:8px 10px;font-size:11px">ÍTEM</th>
                @foreach($tabla as $tk => $t)
                    <th style="text-align:right;padding:8px 10px;font-size:11px">{{ mb_strtoupper($tipos[$tk] ?? $tk) }}</th>
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
                <td style="padding:6px 10px;background:{{ $colCat[$ck] ?? '#fff' }};font-weight:500">{{ $ck }}</td>
                @foreach($tabla as $tk => $t)
                    @php
                        $valor = $t['cat'][$ck];
                        $tieneDetalle = !empty($t['detalle'][$ck] ?? []);
                        $cellId = 'det-'.$tk.'-'.\Illuminate\Support\Str::slug($ck);
                    @endphp
                    <td style="padding:6px 10px;text-align:right;background:{{ $colCat[$ck] ?? '#fff' }};{{ $tieneDetalle ? 'cursor:pointer;' : '' }}"
                        @if($tieneDetalle) onclick="toggleDetalle('{{ $cellId }}')" title="Clic para ver el detalle" @endif>
                        {{ $fmt($valor) }}
                        @if($tieneDetalle)<span style="color:#6366F1;font-size:10px;margin-left:3px">▸</span>@endif
                    </td>
                    <td style="padding:6px 10px;text-align:right;color:#374151;font-weight:600">{{ $pct($valor, $t['ingreso']) }}</td>
                @endforeach
                <td style="padding:6px 10px;text-align:right;background:{{ $colCat[$ck] ?? '#fff' }}">{{ $fmt($todo['cat'][$ck]) }}</td>
                <td style="padding:6px 10px;text-align:right;color:#374151;font-weight:600">{{ $pct($todo['cat'][$ck], $todo['ingreso']) }}</td>
            </tr>

            {{-- Filas de DETALLE (una por cada celda con datos, ocultas por defecto) --}}
            @foreach($tabla as $tk => $t)
                @php
                    $lineas = $t['detalle'][$ck] ?? [];
                    $cellId = 'det-'.$tk.'-'.\Illuminate\Support\Str::slug($ck);
                @endphp
                @if(!empty($lineas))
                <tr id="{{ $cellId }}" style="display:none;background:#F9FAFB">
                    <td colspan="{{ $numCols }}" style="padding:0 10px 10px 30px">
                        <div style="font-size:11px;color:#6B7280;margin:6px 0 4px">
                            Detalle de <b>{{ $ck }}</b> · {{ mb_strtoupper($tipos[$tk] ?? $tk) }}
                            <span style="color:#9CA3AF">({{ count($lineas) }} {{ count($lineas)==1 ? 'línea' : 'líneas' }})</span>
                        </div>
                        <table style="width:100%;border-collapse:collapse;font-size:11px">
                            <tr style="color:#9CA3AF;text-align:left">
                                <td style="padding:3px 6px">Proyecto (OT)</td>
                                <td style="padding:3px 6px">Cuenta 14</td>
                                <td style="padding:3px 6px"></td>
                                <td style="padding:3px 6px">Cuenta 61</td>
                                <td style="padding:3px 6px">Origen</td>
                                <td style="padding:3px 6px;text-align:right">Valor</td>
                            </tr>
                            @foreach($lineas as $ln)
                            <tr style="border-top:1px solid #EEF0F2">
                                <td style="padding:4px 6px;font-weight:500;color:#1B3F6E">{{ $ln['proyecto'] }}</td>
                                <td style="padding:4px 6px;font-family:monospace;color:#6B7280">{{ $ln['cuenta_14'] }}</td>
                                <td style="padding:4px 6px;text-align:center;color:#D1D5DB">→</td>
                                <td style="padding:4px 6px;font-family:monospace;color:#1B3F6E">{{ $ln['cuenta_61'] }}</td>
                                <td style="padding:4px 6px">
                                    @if($ln['origen'] === 'aplic')
                                        <span style="font-size:10px;background:#DCFCE7;color:#15803D;padding:1px 7px;border-radius:6px">Aplicado ahora</span>
                                    @elseif($ln['origen'] === 'bolsa')
                                        <span style="font-size:10px;background:#FFFBEB;color:#B45309;padding:1px 7px;border-radius:6px">Desde bolsa</span>
                                    @else
                                        <span style="font-size:10px;background:#EFF6FF;color:#1B3F6E;padding:1px 7px;border-radius:6px">Ya en cuenta 6</span>
                                    @endif
                                </td>
                                <td style="padding:4px 6px;text-align:right;font-weight:500">{{ $fmt($ln['monto']) }}</td>
                            </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                @endif
            @endforeach
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
function toggleDetalle(id){
    const fila = document.getElementById(id);
    if(fila){ fila.style.display = (fila.style.display === 'none' || !fila.style.display) ? 'table-row' : 'none'; }
}
</script>

@endsection