@extends('layouts.app')

@section('title', 'Autorizaciones de distribución')

@section('content')
@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
@endphp

<h1 class="page-title">Autorizaciones de distribución</h1>
<p style="color:#6B7280;font-size:13px;margin:-6px 0 16px">
    Proyectos sin ingreso en el mes que solicitan permiso para recibir costos. Aprueba o rechaza cada solicitud.
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('info'))
<div style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1D4ED8;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('info') }}</div>
@endif

<div class="card" style="padding:16px;margin-bottom:1.5rem">
    <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:12px">
        Pendientes <span style="font-size:12px;color:#6B7280;font-weight:500">({{ $pendientes->count() }})</span>
    </h2>

    @forelse($pendientes as $a)
    <div style="border:1px solid #FDE68A;background:#FFFBEB;border-radius:8px;padding:12px 14px;margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
            <span style="font-weight:700;color:#1B3F6E">{{ $a->codigo_proyecto }}</span>
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEF9C3;color:#854D0E">{{ $nombresMes[$a->mes] ?? $a->mes }} {{ $a->anio }}</span>
            <span style="font-size:12px;color:#6B7280">
                Solicitó: {{ $a->solicitante->name ?? '—' }}
                @if($a->solicitado_at) · {{ $a->solicitado_at->format('d/m/Y H:i') }} @endif
            </span>
        </div>
        <div style="font-size:12.5px;color:#374151;margin-bottom:10px"><b>Motivo:</b> {{ $a->motivo }}</div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:220px">
                <label style="font-size:10px;color:#6B7280;display:block;margin-bottom:3px">Comentario de gerencia (opcional)</label>
                <input type="text" form="aprobar-{{ $a->id }}" name="comentario_gerencia" placeholder="Ej: aprobado por cierre contractual..."
                    style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px"
                    oninput="document.getElementById('rech-coment-{{ $a->id }}').value=this.value">
            </div>
            <form id="aprobar-{{ $a->id }}" method="POST" action="{{ route('operativo.autorizaciones.aprobar', $a->id) }}">
                @csrf
                <button type="submit" style="padding:8px 16px;background:#15803D;color:white;border:none;border-radius:6px;font-size:12px;cursor:pointer">Aprobar</button>
            </form>
            <form method="POST" action="{{ route('operativo.autorizaciones.rechazar', $a->id) }}"
                onsubmit="return confirm('¿Rechazar la solicitud de {{ $a->codigo_proyecto }}? El proyecto seguirá bloqueado.')">
                @csrf
                <input type="hidden" id="rech-coment-{{ $a->id }}" name="comentario_gerencia" value="">
                <button type="submit" style="padding:8px 16px;background:white;border:1px solid #DC2626;color:#DC2626;border-radius:6px;font-size:12px;cursor:pointer">Rechazar</button>
            </form>
        </div>
    </div>
    @empty
    <div style="color:#6B7280;font-size:13px;padding:8px 0">No hay solicitudes pendientes. 🎉</div>
    @endforelse
</div>

<div class="card" style="padding:16px">
    <h2 style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:12px">Resueltas recientes</h2>
    @if($resueltas->isEmpty())
        <div style="color:#6B7280;font-size:13px">Aún no hay autorizaciones resueltas.</div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <tr style="color:#9CA3AF;text-align:left">
            <th style="padding:6px 8px">Proyecto</th>
            <th style="padding:6px 8px">Período</th>
            <th style="padding:6px 8px">Estado</th>
            <th style="padding:6px 8px">Resolvió</th>
            <th style="padding:6px 8px">Fecha</th>
            <th style="padding:6px 8px">Comentario</th>
        </tr>
        @foreach($resueltas as $a)
        <tr style="border-top:1px solid #F3F4F6">
            <td style="padding:6px 8px;font-weight:600;color:#1B3F6E">{{ $a->codigo_proyecto }}</td>
            <td style="padding:6px 8px;color:#6B7280">{{ $nombresMes[$a->mes] ?? $a->mes }} {{ $a->anio }}</td>
            <td style="padding:6px 8px">
                @if($a->estado === 'aprobada')
                    <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#DCFCE7;color:#15803D">Aprobada</span>
                @else
                    <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEE2E2;color:#B91C1C">Rechazada</span>
                @endif
            </td>
            <td style="padding:6px 8px;color:#374151">{{ $a->resolvedor->name ?? '—' }}</td>
            <td style="padding:6px 8px;color:#6B7280">{{ $a->resuelto_at?->format('d/m/Y H:i') ?? '—' }}</td>
            <td style="padding:6px 8px;color:#6B7280">{{ $a->comentario_gerencia ?: '—' }}</td>
        </tr>
        @endforeach
    </table>
    @endif
</div>
@endsection
