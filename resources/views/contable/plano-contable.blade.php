@extends('layouts.app')

@section('title', 'Plano contable')

@section('content')
@php
    $nombresMes = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $mesNombre  = mb_strtoupper($nombresMes[$mes - 1] ?? '');
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
@endphp

<h1 class="page-title">Plano contable — Versiones enviadas</h1>

<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('contable.plano-contable') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Mes</label>
            <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach($nombresMes as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Filtrar</button>
    </form>
</div>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ $errors->first() }}</div>
@endif

@if($data->count() == 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay versiones enviadas para este período.</div>
@endif

@foreach($data as $v)
@php
    $depInfo = [
        'mantenimiento' => ['MT', 'Mantenimiento', '#EFF6FF', '#1B3F6E', '30020105'],
        'instalaciones' => ['IN', 'Instalaciones', '#F0FDF4', '#15803D', '30010103'],
    ];
    $di = $depInfo[$v['departamento'] ?? ''] ?? ['', '', '#F3F4F6', '#6B7280', '?'];
    $obsSugerida = 'CIERRE DE COSTOS OBRAS ' . mb_strtoupper($v['departamento'] ?? '') . ' MES DE ' . $mesNombre . ' ' . $anio;
@endphp
<div class="card" style="padding:0;overflow:hidden;margin-bottom:10px">
    <div onclick="toggleV('{{ $v['id'] }}')" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;cursor:pointer;flex-wrap:wrap">
        <div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                @if($di[1])
                    <span style="font-size:11px;font-weight:700;background:{{ $di[2] }};color:{{ $di[3] }};padding:3px 10px;border-radius:8px">{{ mb_strtoupper($di[1]) }}</span>
                @endif
                <span style="font-weight:600;color:#1B3F6E">Versión {{ $di[0] ? $di[0].'-' : '' }}v{{ $v['version'] }}</span>
                @if($v['habilitada'])
                    <span style="font-size:11px;background:#FEF9C3;color:#854D0E;padding:2px 8px;border-radius:8px">Habilitada para edición</span>
                @else
                    <span style="font-size:11px;background:#EFF6FF;color:#1B3F6E;padding:2px 8px;border-radius:8px">Enviado · bloqueado</span>
                @endif
            </div>
            <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Enviado {{ $v['enviado_at']?->format('d/m/Y H:i') }} · por {{ $v['enviado_por'] }}</div>
        </div>
        <div style="text-align:right">
            <div style="font-size:13px;font-weight:600;color:#1B3F6E">{{ $fmt($v['total']) }}</div>
            <div style="font-size:11px;color:#6B7280">{{ $v['obras'] }} obra(s) cerrada(s)</div>
        </div>
    </div>

    <div id="v-{{ $v['id'] }}" style="display:none;border-top:1px solid #F3F4F6;padding:12px 16px">

        {{-- ══════ GENERAR EL PLANO PARA SIESA ══════ --}}
        @if($v['lineas']->count() > 0)
        <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px;margin-bottom:12px">
            <div style="font-size:12px;font-weight:600;color:#1B3F6E;margin-bottom:8px">Generar plano para SIESA</div>

            <form method="GET" action="{{ route('contable.plano-contable.descargar', $v['id']) }}"
                  style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">

                <div style="flex:0 0 150px">
                    <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">N.° de documento *</label>
                    <input type="number" name="documento" min="1" required placeholder="Ej: 385"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>

                <div style="flex:1;min-width:260px">
                    <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Observación del documento</label>
                    <input type="text" name="observacion" maxlength="255"
                        placeholder="{{ $obsSugerida }}"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12.5px">
                </div>

                <button type="submit"
                    style="padding:8px 18px;background:#16A34A;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">
                    ⬇ Descargar plano
                </button>
            </form>

            <div style="font-size:11px;color:#9CA3AF;margin-top:8px;line-height:1.5">
                Tipo de documento <b>CCC</b> · Centro de costos <b>{{ $di[4] }}</b> ({{ $di[1] ?: '?' }}) ·
                Fecha: último día de {{ ucfirst(mb_strtolower($mesNombre)) }} {{ $anio }}.<br>
                El <b>tercero de cada línea es el proveedor real</b>, resuelto en FIFO contra las compras de la cuenta 14.
                Los costos de mano de obra y prestaciones no tienen proveedor: esos quedan a nombre de Secar.
                Si la observación se deja vacía, se usa la sugerida.
            </div>
        </div>
        @endif

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-bottom:12px">
            <form method="POST" action="{{ route('contable.plano-contable.habilitar', $v['id']) }}">
                @csrf
                <button type="submit" style="padding:8px 16px;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;cursor:pointer;background:white;color:#1B3F6E">
                    {{ $v['habilitada'] ? 'Bloquear edición' : 'Habilitar edición a operaciones' }}
                </button>
            </form>
        </div>

        @if($v['lineas']->count() == 0)
            <div style="text-align:center;color:#9CA3AF;padding:1rem">Esta versión no tiene obras cerradas con costos aplicados.</div>
        @else
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="background:#1B3F6E;color:white">
                    <th style="padding:8px 10px;text-align:left">Proyecto</th>
                    <th style="padding:8px 10px;text-align:left">Cuenta 14</th>
                    <th style="padding:8px 10px;text-align:left">Cuenta 61</th>
                    <th style="padding:8px 10px;text-align:left">Concepto</th>
                    <th style="padding:8px 10px;text-align:right">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach($v['lineas'] as $l)
                <tr style="border-bottom:1px solid #E5E7EB">
                    <td style="padding:6px 10px;font-weight:500;color:#1B3F6E">{{ $l->codigo_proyecto }}</td>
                    <td style="padding:6px 10px;font-family:monospace;color:#854D0E">{{ $l->cuenta_14 }}</td>
                    <td style="padding:6px 10px;font-family:monospace;color:{{ $l->cuenta_61 === 'SIN HOMOLOGAR' ? '#DC2626' : '#15803D' }}">{{ $l->cuenta_61 }}</td>
                    <td style="padding:6px 10px;color:#6B7280">{{ Str::limit($l->nombre, 40) }}{{ $l->es_provision ? ' (provisión)' : '' }}</td>
                    <td style="padding:6px 10px;text-align:right;font-weight:600">{{ $fmt($l->monto_aplicar) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endforeach

<script>
function toggleV(id){ const e=document.getElementById('v-'+id); if(e) e.style.display = e.style.display==='none'?'block':'none'; }
</script>
@endsection