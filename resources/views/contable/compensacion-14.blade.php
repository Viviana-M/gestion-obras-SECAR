@extends('layouts.app')

@section('title', 'Compensación entre cuentas 14')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
@endphp

<x-page-banner title="Compensación entre cuentas 14" icon="🔀">
    Obras NO cerradas cuyo <b>neto de la 1420 ≈ 0</b> pero descuadrado por dentro: una subcuenta en
    débito y otra en crédito que se compensan. Se cruzan las subcuentas 14 <b>entre sí</b> (sin tocar la 61)
    para dejarlas en 0 y que la OT se pueda cerrar.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

{{-- Filtros + descarga --}}
<div class="card" style="padding:14px 16px;margin-bottom:1rem;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <form method="GET" action="{{ route('contable.compensacion-14.index') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Corte (acumulado al mes)</label>
            <select name="periodo" onchange="const v=this.value; if(!v){this.form.mes.value='';this.form.anio.value='';} else {const [a,m]=v.split('-');this.form.mes.value=m;this.form.anio.value=a;} this.form.submit()"
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
                <option value="">Todo (a la fecha)</option>
                @foreach($periodos as $p)
                    <option value="{{ $p->anio }}-{{ $p->mes }}" {{ ($mes == $p->mes && $anio == $p->anio) ? 'selected' : '' }}>
                        Hasta {{ $nombresMes[$p->mes] ?? $p->mes }} {{ $p->anio }}
                    </option>
                @endforeach
            </select>
            <input type="hidden" name="mes" value="{{ $mes }}">
            <input type="hidden" name="anio" value="{{ $anio }}">
        </div>
        <div style="flex:1;min-width:180px">
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Buscar</label>
            <input id="buscar" type="text" placeholder="🔎 Cuenta o proyecto…" oninput="filtrar()" autocomplete="off"
                style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
        </div>
    </form>
    <form method="GET" action="{{ route('contable.compensacion-14.excel') }}" style="display:flex;gap:8px;align-items:flex-end;margin:0">
        <input type="hidden" name="mes" value="{{ $mes }}">
        <input type="hidden" name="anio" value="{{ $anio }}">
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">N° documento</label>
            <input type="number" name="documento" min="1" value="1" title="Número de documento del asiento para SIESA"
                style="width:110px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
        </div>
        <button type="submit"
            style="padding:9px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">⬇ Descargar plano (Excel)</button>
    </form>
</div>

{{-- Aplicar en el sistema: compensa las subcuentas 14 (origen compensacion_14), sin tocar la 61. --}}
@if(count($filas) > 0)
<div class="card" style="padding:14px 16px;margin-bottom:1rem;background:#F8FAFC;border:1px solid #E5E7EB">
    <div style="font-size:12px;color:#374151;margin-bottom:8px">
        <b>Aplicar en el sistema:</b> compensa las {{ number_format(count($filas),0,',','.') }} subcuentas 14 cruzadas
        (origen <code>compensacion_14</code>) para dejarlas en 0 <b>sin tocar la 61</b>. Idempotente: re-aplicar el mismo corte reemplaza sus movimientos.
    </div>
    <form method="POST" action="{{ route('contable.compensacion-14.aplicar') }}"
          onsubmit="return confirm('¿Aplicar la compensación en el sistema? Se cruzan las subcuentas 14 entre sí en el período destino indicado. Podrás deshacerlo desde «Planos aplicados».');"
          style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
        @csrf
        <input type="hidden" name="corte_mes" value="{{ $mes }}">
        <input type="hidden" name="corte_anio" value="{{ $anio }}">
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">N° documento</label>
            <input type="number" name="documento" min="1" value="1" style="width:100px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Período destino (mes)</label>
            <select name="destino_mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
                @foreach($nombresMes as $mnum => $mnom)
                <option value="{{ $mnum }}" {{ (int)($mes ?: date('n')) === $mnum ? 'selected' : '' }}>{{ $mnom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <input type="number" name="destino_anio" min="2000" value="{{ $anio ?: date('Y') }}" style="width:90px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
        </div>
        <button type="submit"
            style="padding:9px 16px;background:#15803D;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">✓ Aplicar en el sistema</button>
    </form>
</div>
@endif

{{-- KPIs --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:1rem">
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280">Subcuentas 14 a compensar</div>
        <div style="font-size:22px;font-weight:700;color:#1B3F6E">{{ number_format(count($filas), 0, ',', '.') }}</div>
    </div>
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280">Total a compensar (valor absoluto)</div>
        <div style="font-size:22px;font-weight:700;color:#1B3F6E">{{ $fmt($total) }}</div>
    </div>
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:640px">
        <thead>
            <tr style="background:#1B3F6E;color:white;text-align:left">
                <th style="padding:10px 14px">Cuenta</th>
                <th style="padding:10px 14px">Nombre cuenta</th>
                <th style="padding:10px 14px">Proyecto</th>
                <th style="padding:10px 14px;text-align:right">Saldo</th>
            </tr>
        </thead>
        <tbody id="tbody-rev">
            @forelse($filas as $f)
            <tr class="fila-rev" data-buscar="{{ strtolower($f['cuenta'].' '.$f['proyecto'].' '.$f['nombre']) }}" style="border-bottom:1px solid #F3F4F6">
                <td style="padding:8px 14px;font-family:monospace;color:#1B3F6E;font-weight:600">{{ $f['cuenta'] }}</td>
                <td style="padding:8px 14px;color:#374151">{{ $f['nombre'] ?: '—' }}</td>
                <td style="padding:8px 14px;font-family:monospace;color:#6B7280">{{ $f['proyecto'] }}</td>
                <td style="padding:8px 14px;text-align:right;font-weight:600;color:{{ $f['saldo'] > 0 ? '#1B3F6E' : '#B45309' }}">{{ $fmt($f['saldo']) }}</td>
            </tr>
            @empty
            <tr><td colspan="4" style="padding:1.75rem;text-align:center;color:#9CA3AF">No hay obras con cuentas 14 cruzadas (compensables) en este corte. ✅</td></tr>
            @endforelse
            <tr id="rev-sinresultados" style="display:none"><td colspan="4" style="padding:1.25rem;text-align:center;color:#9CA3AF">Sin resultados para la búsqueda.</td></tr>
        </tbody>
        @if(count($filas) > 0)
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB;font-weight:700">
                <td colspan="3" style="padding:9px 14px;color:#1B3F6E">Total a compensar (valor absoluto)</td>
                <td style="padding:9px 14px;text-align:right;color:#1B3F6E">{{ $fmt($total) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

<script>
function filtrar(){
    const q = (document.getElementById('buscar').value || '').toLowerCase().trim();
    let vis = 0;
    document.querySelectorAll('#tbody-rev .fila-rev').forEach(function(row){
        const match = !q || (row.dataset.buscar || '').includes(q);
        row.style.display = match ? '' : 'none';
        if (match) vis++;
    });
    const sin = document.getElementById('rev-sinresultados');
    if (sin) sin.style.display = (vis === 0 && q) ? 'table-row' : 'none';
}
</script>
@endsection
