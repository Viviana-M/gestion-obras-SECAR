@extends('layouts.app')

@section('title', 'Planos aplicados')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
@endphp

<x-page-banner title="Planos aplicados en la cuenta 14" icon="🧾">
    Movimientos de cuenta 14 que el sistema creó al <b>aplicar</b> un plano (reverso o distribución),
    porque el ERP los contabiliza pero ese mes no se recarga desde BIABLE. Aquí puedes <b>auditar</b>
    cada aplicación y <b>deshacerla</b>: al deshacer se borran sus movimientos y el saldo vuelve a como estaba.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

<form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Tipo</label>
        <select name="tipo" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Todos</option>
            <option value="reverso" {{ $tipo === 'reverso' ? 'selected' : '' }}>Reverso</option>
            <option value="distribucion" {{ $tipo === 'distribucion' ? 'selected' : '' }}>Distribución</option>
            <option value="mo_distribucion" {{ $tipo === 'mo_distribucion' ? 'selected' : '' }}>Distribución MO</option>
        </select>
    </div>
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Año destino</label>
        <select name="anio" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Todos</option>
            @foreach($anios as $y)
            <option value="{{ $y }}" {{ (int) $anio === (int) $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
    </div>
    @if($tipo || $anio)
    <a href="{{ route('contable.planos-aplicados.index') }}" style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:inline-flex;align-items:center">Limpiar</a>
    @endif
</form>

<div style="font-size:12px;color:#6B7280;margin-bottom:8px">
    <b>{{ $planos->count() }}</b> plano(s) aplicado(s) · débito total {{ $fmt($totalDebito) }} · crédito total {{ $fmt($totalCredito) }}.
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:900px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Tipo</th>
                <th style="padding:10px 14px;text-align:left">Referencia</th>
                <th style="padding:10px 14px;text-align:center">Período destino</th>
                <th style="padding:10px 14px;text-align:center">Doc.</th>
                <th style="padding:10px 14px;text-align:center">Líneas 14</th>
                <th style="padding:10px 14px;text-align:right">Débito</th>
                <th style="padding:10px 14px;text-align:right">Crédito</th>
                <th style="padding:10px 14px;text-align:left">Aplicado</th>
                <th style="padding:10px 14px;text-align:center">Acción</th>
            </tr>
        </thead>
        <tbody>
            @forelse($planos as $p)
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:9px 14px">
                    <span style="font-size:11px;font-weight:700;padding:2px 10px;border-radius:10px;background:{{ $p->tipo === 'reverso' ? '#FEF3C7' : '#EFF6FF' }};color:{{ $p->tipo === 'reverso' ? '#854D0E' : '#1B3F6E' }}">
                        {{ ['reverso'=>'Reverso','distribucion'=>'Distribución','mo_distribucion'=>'Distribución MO'][$p->tipo] ?? 'Distribución' }}
                    </span>
                </td>
                <td style="padding:9px 14px;color:#374151">{{ $p->referencia ?: '—' }}</td>
                <td style="padding:9px 14px;text-align:center;color:#374151">{{ ($meses[$p->mes] ?? $p->mes) }} {{ $p->anio }}</td>
                <td style="padding:9px 14px;text-align:center;font-family:monospace;color:#6B7280">{{ $p->numero_documento ?: '—' }}</td>
                <td style="padding:9px 14px;text-align:center;color:#374151">{{ $p->n_lineas }}</td>
                <td style="padding:9px 14px;text-align:right;color:#374151">{{ $fmt($p->total_debito) }}</td>
                <td style="padding:9px 14px;text-align:right;color:#374151">{{ $fmt($p->total_credito) }}</td>
                <td style="padding:9px 14px;color:#6B7280;font-size:11px">
                    {{ $p->created_at?->format('d/m/Y H:i') }}
                    @if($p->usuario)<div>{{ $p->usuario->name }}</div>@endif
                </td>
                <td style="padding:9px 14px;text-align:center">
                    <form method="POST" action="{{ route('contable.planos-aplicados.deshacer', $p->id) }}"
                          onsubmit="return confirm('¿Deshacer esta aplicación? Se eliminarán sus {{ $p->n_lineas }} movimientos de cuenta 14 y el saldo volverá a su estado anterior (Operaciones volverá a verlo).');"
                          style="margin:0">
                        @csrf
                        <button type="submit" style="padding:5px 12px;background:#DC2626;border:none;border-radius:8px;font-size:12px;color:white;cursor:pointer;white-space:nowrap">Deshacer</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="padding:1.5rem;text-align:center;color:#9CA3AF">Aún no hay planos aplicados en el sistema.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
