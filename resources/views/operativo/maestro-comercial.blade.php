@extends('layouts.app')

@section('title', 'Maestro de proyectos')

@section('content')
<h1 class="page-title">Maestro de proyectos (carga comercial)</h1>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#15803D">
    {{ session('success') }}
</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">
    {{ $errors->first() }}
</div>
@endif

{{-- SUBIR EXCEL --}}
<div class="card" style="margin-bottom:1rem">
    <div style="font-size:14px;font-weight:600;color:#1B3F6E;margin-bottom:4px">Cargar Excel del maestro</div>
    <div style="font-size:12px;color:#9CA3AF;margin-bottom:12px">
        Lee las hojas Mantenimiento e Instalaciones. Si una OT ya existe, se actualiza; no toca tus datos financieros.
    </div>
    <form method="POST" action="{{ route('operativo.maestro.importar') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        @csrf
        <input type="file" name="archivo" accept=".xlsx,.xls" required
            style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px">
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
            Subir y procesar
        </button>
    </form>
</div>

{{-- ACCIONES --}}
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:10px;flex-wrap:wrap">
    <span style="font-size:12px;color:#9CA3AF">{{ $fichas->count() }} proyectos en el maestro</span>
    <button onclick="abrirNuevo()" style="padding:7px 16px;background:white;border:1px solid #1B3F6E;color:#1B3F6E;border-radius:8px;font-size:13px;cursor:pointer">
        + Agregar proyecto
    </button>
</div>

{{-- TABLA --}}
<div class="card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">OT</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Área</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cliente / Obra</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Resp. obra</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">MC ofertado</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Utilidad ofertada</th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($fichas as $f)
            <tr>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;font-weight:600;color:#374151">{{ $f->codigo_proyecto }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280">{{ $f->area }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#374151">
                    {{ $f->cliente }}
                    <div style="font-size:10px;color:#9CA3AF">{{ \Illuminate\Support\Str::limit($f->nombre_obra, 50) }}</div>
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#374151">{{ $f->responsable_obra }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:600;color:#1B3F6E">
                    {{ $f->margen_ofertado !== null ? $f->margen_ofertado . '%' : '—' }}
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#6B7280">
                    {{ $f->utilidad_ofertada !== null ? '$' . number_format($f->utilidad_ofertada, 0, ',', '.') : '—' }}
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:center;white-space:nowrap">
                    <button onclick='abrirEditar(@json($f))' style="font-size:11px;padding:3px 10px;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;color:#1B3F6E">Editar</button>
                    <form method="POST" action="{{ route('operativo.maestro.eliminar', $f->id) }}" style="display:inline" onsubmit="return confirm('¿Eliminar {{ $f->codigo_proyecto }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:11px;padding:3px 10px;border:1px solid #FECACA;border-radius:6px;background:white;cursor:pointer;color:#DC2626">Eliminar</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:#9CA3AF">Aún no hay proyectos. Sube el Excel o agrega uno a mano.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- MODAL CREAR / EDITAR --}}
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)cerrar()">
    <div style="background:white;border-radius:12px;padding:20px;width:min(560px,95vw);max-height:90vh;overflow:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 id="modal-titulo" style="font-size:15px;font-weight:600;color:#1B3F6E">Nuevo proyecto</h3>
            <button onclick="cerrar()" style="border:none;background:#F3F4F6;width:30px;height:30px;border-radius:8px;cursor:pointer;color:#6B7280">×</button>
        </div>
        <form id="modal-form" method="POST">
            @csrf
            <input type="hidden" name="_method" id="form-method" value="POST">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div style="grid-column:1/3">
                    <label style="font-size:11px;color:#6B7280">Orden de trabajo (OT) *</label>
                    <input name="codigo_proyecto" id="f-cod" required style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Área</label>
                    <select name="area" id="f-area" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                        <option value="">—</option>
                        <option>Mantenimiento</option>
                        <option>Instalaciones</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Cliente</label>
                    <input name="cliente" id="f-cli" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div style="grid-column:1/3">
                    <label style="font-size:11px;color:#6B7280">Obra</label>
                    <input name="nombre_obra" id="f-obra" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">MC ofertado (%)</label>
                    <input name="margen_ofertado" id="f-mc" type="number" step="0.01" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Utilidad ofertada ($)</label>
                    <input name="utilidad_ofertada" id="f-util" type="number" step="0.01" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Valor contratado ($)</label>
                    <input name="valor_contratado" id="f-valor" type="number" step="0.01" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Costo estimado ($)</label>
                    <input name="costo_estimado" id="f-costo" type="number" step="0.01" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Responsable obra</label>
                    <input name="responsable_obra" id="f-ro" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:11px;color:#6B7280">Responsable comercial</label>
                    <input name="responsable_comercial" id="f-rc" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
            </div>
            <div style="margin-top:16px;text-align:right">
                <button type="button" onclick="cerrar()" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#6B7280;font-size:13px;cursor:pointer">Cancelar</button>
                <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
const URL_NUEVO = "{{ route('operativo.maestro.guardar') }}";
const URL_EDIT  = "{{ url('/operativo/maestro-comercial') }}";

function abrirNuevo() {
    document.getElementById('modal-titulo').textContent = 'Nuevo proyecto';
    document.getElementById('modal-form').action = URL_NUEVO;
    document.getElementById('form-method').value = 'POST';
    ['f-cod','f-area','f-cli','f-obra','f-mc','f-util','f-valor','f-costo','f-ro','f-rc'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('f-cod').readOnly = false;
    document.getElementById('modal').style.display = 'flex';
}

function abrirEditar(f) {
    document.getElementById('modal-titulo').textContent = 'Editar ' + f.codigo_proyecto;
    document.getElementById('modal-form').action = URL_EDIT + '/' + f.id;
    document.getElementById('form-method').value = 'PUT';
    document.getElementById('f-cod').value   = f.codigo_proyecto ?? '';
    document.getElementById('f-cod').readOnly = true;
    document.getElementById('f-area').value  = f.area ?? '';
    document.getElementById('f-cli').value   = f.cliente ?? '';
    document.getElementById('f-obra').value  = f.nombre_obra ?? '';
    document.getElementById('f-mc').value    = f.margen_ofertado ?? '';
    document.getElementById('f-util').value  = f.utilidad_ofertada ?? '';
    document.getElementById('f-valor').value = f.valor_contratado ?? '';
    document.getElementById('f-costo').value = f.costo_estimado ?? '';
    document.getElementById('f-ro').value    = f.responsable_obra ?? '';
    document.getElementById('f-rc').value    = f.responsable_comercial ?? '';
    document.getElementById('modal').style.display = 'flex';
}

function cerrar() { document.getElementById('modal').style.display = 'none'; }
</script>
@endsection