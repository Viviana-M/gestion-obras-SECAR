@extends('layouts.app')

@section('title', 'Conciliación y cierre de cuenta 14')

@section('content')
@php
    $fmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $col = fn ($n) => abs($n) < 0.5 ? '#6B7280' : ($n < 0 ? '#DC2626' : '#15803D');
    $t = $tot;
    $cuadra = $tieneErp && abs($t['dif14']) <= $tolCierre && abs($t['dif6']) <= $tolCierre;
@endphp

<x-page-banner title="Conciliación y cierre de cuenta 14 (y 6)" icon="⚖️">
    Cruza el saldo del <b>sistema</b> contra el <b>ERP</b> para la cuenta 14 (Costos por aplicar) y la
    cuenta 6 (Costos aplicados), por obra y con corte acumulado hasta el período elegido. Cuando la
    diferencia queda en cero, puedes <b>cerrar</b> el período: queda conciliado y <b>bloqueado</b>
    (no se recarga BIABLE ni se tocan planos de ese mes hasta reabrirlo).
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

{{-- Filtro de período + estado del cierre --}}
<div class="card" style="padding:14px 16px;margin-bottom:1rem;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Corte (acumulado hasta)</label>
            <select name="periodo" onchange="const v=this.value; if(v){const [a,m]=v.split('-');this.form.mes.value=m;this.form.anio.value=a;} this.form.submit()"
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
                @foreach($periodos as $p)
                <option value="{{ $p->anio }}-{{ $p->mes }}" {{ ($mes == $p->mes && $anio == $p->anio) ? 'selected' : '' }}>
                    Hasta {{ $meses[$p->mes] ?? $p->mes }} {{ $p->anio }}
                </option>
                @endforeach
            </select>
            <input type="hidden" name="mes" value="{{ $mes }}">
            <input type="hidden" name="anio" value="{{ $anio }}">
        </div>
    </form>
    @if($cerrado)
        <span style="font-size:12px;font-weight:700;padding:5px 12px;border-radius:10px;background:#FEF3C7;color:#854D0E">🔒 Período {{ $mes }}/{{ $anio }} CERRADO (conciliado)</span>
    @endif
    @if($erp)
        <a href="{{ route('contable.conciliacion-cierre.excel', ['mes'=>$mes,'anio'=>$anio]) }}"
           style="padding:7px 14px;background:#1B3F6E;color:white;border-radius:8px;font-size:13px;text-decoration:none;height:36px;display:inline-flex;align-items:center">⬇ Exportar Excel</a>
    @endif
</div>

{{-- Cargar ERP (dos auxiliares: 14 y 6) --}}
<div class="card" style="padding:14px 16px;margin-bottom:1rem">
    <div style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:4px">Cargar auxiliares del ERP</div>
    <div style="font-size:12px;color:#9CA3AF;margin-bottom:10px">
        Sube el auxiliar de la cuenta <b>1420</b> (obligatorio) y, si lo tienes, el de la <b>6130</b>. Cada uno con las
        columnas U.N., Débitos, Créditos, Neto, Fecha y Nit movto. (se ignoran "Gran total" y subtotales).
    </div>
    <form method="POST" action="{{ route('contable.conciliacion-cierre.erp') }}" enctype="multipart/form-data"
          style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Auxiliar cuenta 14 *</label>
            <input type="file" name="archivo_14" accept=".xlsx,.xls" required style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Auxiliar cuenta 6</label>
            <input type="file" name="archivo_6" accept=".xlsx,.xls" style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white">
        </div>
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Cruzar</button>
        @if($erp)
        <span style="font-size:11px;color:#6B7280">Cargado {{ $erp['generado'] }} · 14: {{ $erp['archivo_14'] }}@if($erp['archivo_6']) · 6: {{ $erp['archivo_6'] }}@endif</span>
        @endif
    </form>
    @if($erp)
    <form method="POST" action="{{ route('contable.conciliacion-cierre.limpiar') }}" style="margin:8px 0 0">@csrf
        <button type="submit" style="padding:5px 12px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:12px;color:#6B7280;cursor:pointer">Limpiar ERP</button>
    </form>
    @endif
</div>

{{-- Semáforo --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:1rem">
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:6px">Cuenta 14 (Costos por aplicar)</div>
        <div style="font-size:12px;color:#374151">Sistema: <b>{{ $fmt($t['sis14']) }}</b></div>
        <div style="font-size:12px;color:#374151">ERP: <b>{{ $tieneErp ? $fmt($t['erp14']) : '—' }}</b></div>
        <div style="font-size:15px;font-weight:800;color:{{ $col($t['dif14']) }}">Dif: {{ $tieneErp ? $fmt($t['dif14']) : '—' }}</div>
    </div>
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:6px">Cuenta 6 (Costos aplicados)</div>
        <div style="font-size:12px;color:#374151">Sistema: <b>{{ $fmt($t['sis6']) }}</b></div>
        <div style="font-size:12px;color:#374151">ERP: <b>{{ $tieneErp ? $fmt($t['erp6']) : '—' }}</b></div>
        <div style="font-size:15px;font-weight:800;color:{{ $col($t['dif6']) }}">Dif: {{ $tieneErp ? $fmt($t['dif6']) : '—' }}</div>
    </div>
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:6px">Utilidad (ingreso − costos)</div>
        <div style="font-size:12px;color:#374151">Sistema: <b>{{ $fmt($t['util_sis']) }}</b></div>
        <div style="font-size:12px;color:#374151">Con saldos ERP: <b>{{ $tieneErp ? $fmt($t['util_erp']) : '—' }}</b></div>
        <div style="font-size:15px;font-weight:800;color:{{ $col($t['dif_util']) }}">Dif: {{ $tieneErp ? $fmt($t['dif_util']) : '—' }}</div>
    </div>
    <div class="card" style="padding:14px 16px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center">
        @if(!$tieneErp)
            <div style="font-size:13px;color:#6B7280">Sube el ERP para conciliar</div>
        @elseif($cerrado)
            <div style="font-size:22px">🔒</div><div style="font-size:12px;color:#854D0E;font-weight:700">Período cerrado</div>
        @elseif($cuadra)
            <div style="font-size:22px">✅</div><div style="font-size:12px;color:#15803D;font-weight:700">Cuadra al centavo</div>
            <form method="POST" action="{{ route('contable.conciliacion-cierre.cerrar') }}" style="margin-top:8px"
                  onsubmit="return confirm('¿Cerrar el período {{ $mes }}/{{ $anio }}? Quedará conciliado y bloqueado (no se podrá recargar BIABLE ni tocar planos de ese mes sin reabrirlo).');">
                @csrf
                <input type="hidden" name="mes" value="{{ $mes }}">
                <input type="hidden" name="anio" value="{{ $anio }}">
                <button type="submit" style="padding:7px 16px;background:#15803D;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">🔒 Cerrar período {{ $mes }}/{{ $anio }}</button>
            </form>
        @else
            <div style="font-size:22px">❌</div><div style="font-size:12px;color:#DC2626;font-weight:700">No cuadra — no se puede cerrar</div>
        @endif
    </div>
</div>

{{-- Tabla por obra --}}
<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:1200px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:9px 12px;text-align:left">Código</th>
                <th style="padding:9px 12px;text-align:left">Obra</th>
                <th style="padding:9px 12px;text-align:right">Sist. 14</th>
                <th style="padding:9px 12px;text-align:right" title="BIABLE / Reverso / Distribución">Desglose 14</th>
                <th style="padding:9px 12px;text-align:right">ERP 14</th>
                <th style="padding:9px 12px;text-align:right">Dif 14</th>
                <th style="padding:9px 12px;text-align:right">Sist. 6</th>
                <th style="padding:9px 12px;text-align:right">ERP 6</th>
                <th style="padding:9px 12px;text-align:right">Dif 6</th>
                <th style="padding:9px 12px;text-align:right">Utilidad</th>
                <th style="padding:9px 12px;text-align:left">Estado / causa</th>
            </tr>
        </thead>
        <tbody>
            @forelse($filas as $f)
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:8px 12px;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $f['codigo'] }}</td>
                <td style="padding:8px 12px;color:#374151">{{ \Illuminate\Support\Str::limit($f['nombre'], 30) ?: '—' }}</td>
                <td style="padding:8px 12px;text-align:right;color:{{ $col($f['sis14']) }}">{{ $fmt($f['sis14']) }}</td>
                <td style="padding:8px 12px;text-align:right;font-size:10px;color:#6B7280">
                    B {{ $fmt($f['biable14']) }}
                    @if(abs($f['reverso14'])>0.5) · R {{ $fmt($f['reverso14']) }}@endif
                    @if(abs($f['distribucion14'])>0.5) · D {{ $fmt($f['distribucion14']) }}@endif
                </td>
                <td style="padding:8px 12px;text-align:right;color:{{ $col($f['erp14']) }}">{{ $tieneErp ? $fmt($f['erp14']) : '—' }}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:700;color:{{ $col($f['dif14']) }}">{{ $tieneErp ? $fmt($f['dif14']) : '—' }}</td>
                <td style="padding:8px 12px;text-align:right;color:{{ $col($f['sis6']) }}">{{ $fmt($f['sis6']) }}</td>
                <td style="padding:8px 12px;text-align:right;color:{{ $col($f['erp6']) }}">{{ $tieneErp ? $fmt($f['erp6']) : '—' }}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:700;color:{{ $col($f['dif6']) }}">{{ $tieneErp ? $fmt($f['dif6']) : '—' }}</td>
                <td style="padding:8px 12px;text-align:right;color:#374151" title="Sistema {{ $fmt($f['util_sis']) }} · ERP {{ $fmt($f['util_erp']) }}">{{ $fmt($f['util_sis']) }}</td>
                <td style="padding:8px 12px">
                    @if(!$tieneErp)
                        <span style="color:#9CA3AF">—</span>
                    @elseif($f['ok'])
                        <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#F0FDF4;color:#15803D">✅ OK</span>
                    @else
                        <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEF2F2;color:#DC2626">❌ {{ $f['causa'] }}</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="11" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay movimientos de cuenta 14/6 en este corte.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td colspan="2" style="padding:9px 12px;text-align:right;color:#374151">TOTAL</td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['sis14']) }}">{{ $fmt($t['sis14']) }}</td>
                <td></td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['erp14']) }}">{{ $tieneErp ? $fmt($t['erp14']) : '—' }}</td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['dif14']) }}">{{ $tieneErp ? $fmt($t['dif14']) : '—' }}</td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['sis6']) }}">{{ $fmt($t['sis6']) }}</td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['erp6']) }}">{{ $tieneErp ? $fmt($t['erp6']) : '—' }}</td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['dif6']) }}">{{ $tieneErp ? $fmt($t['dif6']) : '—' }}</td>
                <td style="padding:9px 12px;text-align:right;color:{{ $col($t['util_sis']) }}">{{ $fmt($t['util_sis']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Historial de cierres --}}
<div style="margin-top:1.5rem">
    <div style="font-size:14px;font-weight:700;color:#1B3F6E;margin-bottom:8px">Historial de cierres</div>
    <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:760px">
            <thead>
                <tr style="background:#F3F4F6;color:#374151">
                    <th style="padding:8px 12px;text-align:left">Período</th>
                    <th style="padding:8px 12px;text-align:right">Saldo 14</th>
                    <th style="padding:8px 12px;text-align:right">Saldo 6</th>
                    <th style="padding:8px 12px;text-align:right">Diferencia</th>
                    <th style="padding:8px 12px;text-align:left">Cerrado</th>
                    <th style="padding:8px 12px;text-align:center">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($historial as $h)
                <tr style="border-bottom:1px solid #E5E7EB">
                    <td style="padding:8px 12px;font-weight:600;color:#1B3F6E">{{ $meses[$h->mes] ?? $h->mes }} {{ $h->anio }}</td>
                    <td style="padding:8px 12px;text-align:right;color:#374151">{{ $fmt($h->saldo_sistema_14) }}</td>
                    <td style="padding:8px 12px;text-align:right;color:#374151">{{ $fmt($h->saldo_sistema_6) }}</td>
                    <td style="padding:8px 12px;text-align:right;color:{{ $col($h->diferencia) }}">{{ $fmt($h->diferencia) }}</td>
                    <td style="padding:8px 12px;color:#6B7280;font-size:11px">
                        {{ $h->cerrado_at?->format('d/m/Y H:i') }}@if($h->usuario) · {{ $h->usuario->name }}@endif
                    </td>
                    <td style="padding:8px 12px;text-align:center">
                        <form method="POST" action="{{ route('contable.conciliacion-cierre.reabrir', $h->id) }}"
                              onsubmit="return confirm('¿Reabrir el período {{ $h->mes }}/{{ $h->anio }}? Se podrá recargar BIABLE y tocar planos de ese mes otra vez.');" style="margin:0">
                            @csrf
                            <button type="submit" style="padding:5px 12px;background:white;border:1px solid #1B3F6E;border-radius:8px;font-size:12px;color:#1B3F6E;cursor:pointer">Reabrir</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" style="padding:1.25rem;text-align:center;color:#9CA3AF">Aún no hay períodos cerrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
