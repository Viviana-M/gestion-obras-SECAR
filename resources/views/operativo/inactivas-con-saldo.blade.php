@extends('layouts.app')

@section('title', 'Obras inactivas con saldo')

@section('content')
<x-page-banner title="Obras inactivas con saldo en cuenta 14" icon="⚠️">
    Obras marcadas como <b>inactivas</b> en el maestro que <b>todavía tienen saldo</b> en la cuenta 14.
    No se cerraron automáticamente: revísalas y aplica/traslada su saldo antes de cerrarlas.
    <x-slot:actions>
        <a href="{{ route('operativo.obras-inactivas.excel', ['mes' => $mes, 'anio' => $anio]) }}" class="btn-banner">⬇ Exportar Excel</a>
    </x-slot:actions>
</x-page-banner>

<form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Mes</label>
        <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            @foreach(['1'=>'Enero','2'=>'Febrero','3'=>'Marzo','4'=>'Abril','5'=>'Mayo','6'=>'Junio','7'=>'Julio','8'=>'Agosto','9'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'] as $k => $v)
            <option value="{{ $k }}" {{ (int) $mes === (int) $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Año</label>
        <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            @for($y = 2022; $y <= date('Y') + 1; $y++)
            <option value="{{ $y }}" {{ (int) $anio === $y ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
    </div>
    <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Ver</button>
</form>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:640px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Código</th>
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:left">Cliente</th>
                <th style="padding:10px 14px;text-align:right">Saldo cuenta 14</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lista as $o)
            <tr style="border-bottom:1px solid #E5E7EB;cursor:pointer" onclick="toggleDetalle(this, '{{ $o['codigo'] }}')">
                <td style="padding:10px 14px;font-family:monospace;font-weight:600;color:#1B3F6E">
                    <span class="chevron" style="display:inline-block;width:14px;color:#9CA3AF;transition:transform .15s">▸</span>{{ $o['codigo'] }}
                </td>
                <td style="padding:10px 14px">{{ $o['nombre'] ?: '—' }}</td>
                <td style="padding:10px 14px;color:#6B7280">{{ $o['cliente'] ?: '—' }}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:600;color:#B45309">${{ number_format($o['saldo_14'], 0, ',', '.') }}</td>
            </tr>
            <tr class="detalle-row" style="display:none;background:#F9FAFB">
                <td colspan="4" style="padding:0 14px 10px 34px">
                    <div class="detalle-cont" style="font-size:12px;color:#9CA3AF;padding:10px 0">Cargando…</div>
                </td>
            </tr>
            @empty
            <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay obras inactivas con saldo en cuenta 14 para este período. 🎉</td></tr>
            @endforelse
        </tbody>
        @if(count($lista))
        <tfoot>
            <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB">
                <td colspan="3" style="padding:10px 14px;text-align:right;font-weight:700;color:#374151">TOTAL</td>
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:#B45309">${{ number_format($total, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

<script>
(function () {
    const base = @json(route('operativo.obras-inactivas.detalle'));
    const mes = @json((int) $mes), anio = @json((int) $anio);
    const fmt = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const esc = (s) => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

    window.toggleDetalle = function (row, codigo) {
        const det = row.nextElementSibling;
        const chevron = row.querySelector('.chevron');
        const abierto = det.style.display !== 'none';
        if (abierto) {
            det.style.display = 'none';
            if (chevron) chevron.style.transform = '';
            return;
        }
        det.style.display = 'table-row';
        if (chevron) chevron.style.transform = 'rotate(90deg)';
        if (det.dataset.cargado) return; // bajo demanda: solo la primera vez

        const cont = det.querySelector('.detalle-cont');
        const url = base + '?codigo=' + encodeURIComponent(codigo) + '&mes=' + mes + '&anio=' + anio;
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                det.dataset.cargado = '1';
                const filas = data.detalle || [];
                if (!filas.length) { cont.innerHTML = '<div style="padding:8px 0;color:#9CA3AF">Sin detalle para este período.</div>'; return; }
                let html = '<table style="width:100%;border-collapse:collapse;font-size:12px">'
                    + '<thead><tr style="color:#6B7280;text-align:left">'
                    + '<th style="padding:5px 8px">Cuenta</th><th style="padding:5px 8px">Concepto</th>'
                    + '<th style="padding:5px 8px">Tercero</th><th style="padding:5px 8px">Documento</th>'
                    + '<th style="padding:5px 8px">Período</th><th style="padding:5px 8px;text-align:right">Débito</th>'
                    + '<th style="padding:5px 8px;text-align:right">Crédito</th><th style="padding:5px 8px;text-align:right">Saldo neto</th></tr></thead><tbody>';
                filas.forEach(d => {
                    html += '<tr style="border-top:1px solid #E5E7EB">'
                        + '<td style="padding:5px 8px;font-family:monospace;color:#854D0E">' + esc(d.cuenta) + '</td>'
                        + '<td style="padding:5px 8px;color:#374151">' + esc(d.concepto) + '</td>'
                        + '<td style="padding:5px 8px;color:#374151">' + esc(d.tercero) + '</td>'
                        + '<td style="padding:5px 8px;font-family:monospace;color:#6B7280">' + esc(d.documento) + '</td>'
                        + '<td style="padding:5px 8px;color:#6B7280">' + esc(d.periodo) + '</td>'
                        + '<td style="padding:5px 8px;text-align:right;color:#6B7280">' + fmt(d.debito) + '</td>'
                        + '<td style="padding:5px 8px;text-align:right;color:#6B7280">' + fmt(d.credito) + '</td>'
                        + '<td style="padding:5px 8px;text-align:right;font-weight:600;color:#B45309">' + fmt(d.saldo) + '</td></tr>';
                });
                html += '</tbody></table>';
                cont.innerHTML = html;
            })
            .catch(() => { cont.innerHTML = '<div style="padding:8px 0;color:#DC2626">No se pudo cargar el detalle.</div>'; });
    };
})();
</script>
@endsection
