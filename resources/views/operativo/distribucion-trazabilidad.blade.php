@extends('layouts.app')

@section('title', 'Trazabilidad del plano')

@php
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $periodo = ($meses[$distribucion->mes] ?? $distribucion->mes).' '.$distribucion->anio;
    $evLabel = [
        'guardado'  => ['#F3F4F6','#6B7280','Guardado'],
        'enviado'   => ['#EFF6FF','#1B3F6E','Enviado a contabilidad'],
        'reabierto' => ['#FEF9C3','#854D0E','Reabierto por contabilidad'],
        'reenviado' => ['#EFF6FF','#1B3F6E','Reenviado a contabilidad'],
        'bloqueado' => ['#F3F4F6','#6B7280','Bloqueado por contabilidad'],
    ];
@endphp

@section('content')
<x-page-banner title="Trazabilidad · {{ $periodo }} · plano #{{ $distribucion->id }}" icon="🧭">
    Historial de todas las modificaciones de este plano; cada versión guarda la foto de sus números.
    <x-slot:actions>
        <a href="{{ route('operativo.distribucion.consultas') }}" style="font-size:13px;padding:8px 16px;background:white;border:1px solid #E5E7EB;border-radius:8px;color:#6B7280;text-decoration:none">← Volver</a>
    </x-slot:actions>
</x-page-banner>

<div class="card" style="padding:0;overflow:hidden">
    @if($versiones->count() == 0)
        <div style="text-align:center;color:#9CA3AF;padding:2rem">Este plano aún no tiene versiones registradas.</div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Fecha y hora</th>
                <th style="padding:10px 14px;text-align:left">Evento</th>
                <th style="padding:10px 14px;text-align:left">Usuario</th>
                <th style="padding:10px 14px;text-align:right">MC % (total)</th>
                <th style="padding:10px 14px;text-align:center">Resumen congelado</th>
            </tr>
        </thead>
        <tbody>
            @foreach($versiones as $v)
            @php
                $ev = $evLabel[$v->evento] ?? ['#F3F4F6','#6B7280',ucfirst($v->evento)];
                $mcTotal = $v->snapshot['todo']['mc_pct'] ?? null;
            @endphp
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:10px 14px;color:#6B7280">{{ $v->created_at?->format('d/m/Y H:i') }}</td>
                <td style="padding:10px 14px">
                    <span style="font-size:11px;padding:3px 10px;border-radius:8px;background:{{ $ev[0] }};color:{{ $ev[1] }};font-weight:500">{{ $ev[2] }}</span>
                </td>
                <td style="padding:10px 14px;color:#374151">{{ $v->user_nombre ?? '—' }}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:600;color:{{ ($mcTotal ?? 0) >= 0 ? '#15803D' : '#DC2626' }}">{{ $mcTotal === null ? '—' : $mcTotal.'%' }}</td>
                <td style="padding:10px 14px;text-align:center;white-space:nowrap">
                    <a href="{{ route('operativo.distribucion.version', $v->id) }}" target="_blank"
                       style="font-size:12px;padding:6px 10px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;text-decoration:none">Ver</a>
                    <a href="{{ route('operativo.distribucion.version', $v->id) }}?formato=excel"
                       style="font-size:12px;padding:6px 10px;border:1px solid #15803D;border-radius:6px;color:#15803D;text-decoration:none;margin-left:4px">Excel</a>
                    <a href="{{ route('operativo.distribucion.version', $v->id) }}?formato=pdf"
                       style="font-size:12px;padding:6px 10px;border:1px solid #DC2626;border-radius:6px;color:#DC2626;text-decoration:none;margin-left:4px">PDF</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection