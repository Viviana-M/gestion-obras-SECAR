@extends('layouts.app')

@section('title', 'Homologaciones de cuentas')

@section('content')
<h1 class="page-title">Homologaciones — Plan de cuentas (14 ↔ 61)</h1>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#15803D">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">{{ session('error') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">{{ $errors->first() }}</div>
@endif

{{-- SUBIR EXCEL --}}
<div class="card" style="margin-bottom:1rem">
    <div style="font-size:14px;font-weight:600;color:#1B3F6E;margin-bottom:4px">Cargar plan de cuentas</div>
    <div style="font-size:12px;color:#9CA3AF;margin-bottom:12px">
        Lee la hoja "Plan de Cuentas" (bloque Nombre / Cuenta Inv Obra / Costo / Estructura). Si una cuenta 14 ya existe, se actualiza.
    </div>
    <form method="POST" action="{{ route('contable.homologaciones.importar') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        @csrf
        <input type="file" name="archivo" accept=".xlsx,.xls" required style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px">
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Subir y procesar</button>
    </form>
</div>

{{-- ACCIONES --}}
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:10px;flex-wrap:wrap">
    <div style="display:flex;gap:8px;align-items:center">
        <input id="buscador-h" oninput="filtrarH()" placeholder="Buscar cuenta o nombre…"
            style="padding:7px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;min-width:220px">
        <span id="contador-h" style="font-size:12px;color:#9CA3AF">{{ $homologaciones->count() }} cuentas</span>
    </div>
    <button onclick="abrirNueva()" style="padding:7px 16px;background:white;border:1px solid #1B3F6E;color:#1B3F6E;border-radius:8px;font-size:13px;cursor:pointer">+ Agregar homologación</button>
</div>

{{-- TABLA --}}
<div class="card" style="overflow-x:auto">
    <table id="tabla-h" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta 14 (inv. obra)</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta 61 (costo)</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Nombre</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Estructura</th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($homologaciones as $h)
            <tr class="fila-h" data-buscar="{{ strtolower($h->cuenta_14.' '.$h->cuenta_61.' '.$h->nombre) }}">
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $h->cuenta_14 }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;color:#374151">{{ $h->cuenta_61 }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#374151">{{ $h->nombre }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280">{{ $h->estructura }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:center;white-space:nowrap">
                    <button onclick='abrirEditar(@json($h))' style="font-size:11px;padding:3px 10px;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;color:#1B3F6E">Editar</button>
                    <form method="POST" action="{{ route('contable.homologaciones.eliminar', $h->id) }}" style="display:inline" onsubmit="return confirm('¿Eliminar la cuenta {{ $h->cuenta_14 }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:11px;padding:3px 10px;border:1px solid #FECACA;border-radius:6px;background:white;cursor:pointer;color:#DC2626">Eliminar</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center;padding:2rem;color:#9CA3AF">Aún no hay homologaciones. Sube el plan de cuentas o agrega una a mano.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- MODAL CREAR / EDITAR --}}
<div id="modal-h" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)cerrarH()">
    <div style="background:white;border-radius:12px;padding:20px;width:min(480px,95vw)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 id="modal-h-titulo" style="font-size:15px;font-weight:600;color:#1B3F6E">Nueva homologación</h3>
            <button onclick="cerrarH()" style="border:none;background:#F3F4F6;width:30px;height:30px;border-radius:8px;cursor:pointer;color:#6B7280">×</button>
        </div>
        <form id="modal-h-form" method="POST">
            @csrf
            <input type="hidden" name="_method" id="h-method" value="POST">
            <div style="display:grid;gap:10px">
                <div>
                    <label style="font-size:11px;color:#6B7280">Cuenta 14 (inv. obra) *</label>
                    <input name="cuenta_14" id="h-c14" required style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:monospace">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Cuenta 61 (costo) *</label>
                    <input name="cuenta_61" id="h-c61" required style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:monospace">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Nombre</label>
                    <input name="nombre" id="h-nombre" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Estructura</label>
                    <input name="estructura" id="h-estructura" placeholder="EQU-MAT-SUM, MOI…" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
            </div>
            <div style="margin-top:16px;text-align:right">
                <button type="button" onclick="cerrarH()" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#6B7280;font-size:13px;cursor:pointer">Cancelar</button>
                <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
const H_NUEVO = "{{ route('contable.homologaciones.guardar') }}";
const H_EDIT  = "{{ url('/contable/homologaciones') }}";

function abrirNueva() {
    document.getElementById('modal-h-titulo').textContent = 'Nueva homologación';
    document.getElementById('modal-h-form').action = H_NUEVO;
    document.getElementById('h-method').value = 'POST';
    ['h-c14','h-c61','h-nombre','h-estructura'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('h-c14').readOnly = false;
    document.getElementById('modal-h').style.display = 'flex';
}

function abrirEditar(h) {
    document.getElementById('modal-h-titulo').textContent = 'Editar ' + h.cuenta_14;
    document.getElementById('modal-h-form').action = H_EDIT + '/' + h.id;
    document.getElementById('h-method').value = 'PUT';
    document.getElementById('h-c14').value = h.cuenta_14 ?? '';
    document.getElementById('h-c14').readOnly = true;
    document.getElementById('h-c61').value = h.cuenta_61 ?? '';
    document.getElementById('h-nombre').value = h.nombre ?? '';
    document.getElementById('h-estructura').value = h.estructura ?? '';
    document.getElementById('modal-h').style.display = 'flex';
}

function cerrarH() { document.getElementById('modal-h').style.display = 'none'; }

function filtrarH() {
    const q = document.getElementById('buscador-h').value.toLowerCase().trim();
    let n = 0;
    document.querySelectorAll('#tabla-h tbody tr.fila-h').forEach(f => {
        const ok = f.dataset.buscar.includes(q);
        f.style.display = ok ? '' : 'none';
        if (ok) n++;
    });
    document.getElementById('contador-h').textContent = n + ' cuenta' + (n === 1 ? '' : 's');
}
</script>
@endsection