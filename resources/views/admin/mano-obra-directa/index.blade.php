@extends('layouts.app')

@section('title', 'Mano de obra directa')

@section('content')
<x-page-banner title="Mano de obra directa" icon="🧰">
    Personal directo cuya mano de obra se reclasifica de la cuenta 14 a costo real. La <b>cédula</b> (o el nombre) cruza con el cierre y con la planilla PILA. La distribución por unidad de negocio la trae <b>Nómina</b> en el archivo; aquí no se asignan porcentajes.
    <x-slot:actions>
        <button type="button" onclick="document.getElementById('form-nueva').style.display='block'"
            style="font-size:13px;padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;cursor:pointer">+ Nueva persona</button>
    </x-slot:actions>
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">
    @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
</div>
@endif

{{-- Formulario nueva persona (oculto por defecto) --}}
<div id="form-nueva" style="display:{{ $errors->any() ? 'block' : 'none' }};background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px;margin-bottom:1rem">
    <form method="POST" action="{{ route('admin.mano-obra-directa.store') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Cédula</label>
            <input type="text" name="cedula" value="{{ old('cedula') }}" placeholder="1234567890" required
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div style="flex:1;min-width:220px">
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Nombre completo</label>
            <input type="text" name="nombre" value="{{ old('nombre') }}" placeholder="APELLIDOS NOMBRES" required
                style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;text-transform:uppercase">
        </div>
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Guardar</button>
        <button type="button" onclick="document.getElementById('form-nueva').style.display='none'"
            style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;cursor:pointer;height:36px">Cancelar</button>
    </form>
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:720px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Cédula</th>
                <th style="padding:10px 14px;text-align:left">Nombre</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($personas as $p)
            <tr style="border-bottom:1px solid #E5E7EB;{{ $p->activo ? '' : 'opacity:.5' }}">
                <td style="padding:10px 14px;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $p->cedula }}</td>
                <td style="padding:10px 14px">
                    <form method="POST" action="{{ route('admin.mano-obra-directa.update', $p->id) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        @csrf @method('PUT')
                        <input type="text" name="nombre" value="{{ $p->nombre }}" style="flex:1;min-width:240px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-transform:uppercase">
                        <button type="submit" style="font-size:11px;padding:5px 10px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;background:white;cursor:pointer">Guardar</button>
                    </form>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;color:{{ $p->activo ? '#15803D' : '#9CA3AF' }}">{{ $p->activo ? 'Activo' : 'Inactivo' }}</span>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <form method="POST" action="{{ route('admin.mano-obra-directa.toggle', $p->id) }}" style="display:inline">
                        @csrf @method('PUT')
                        <button type="submit" style="font-size:11px;padding:5px 12px;border:1px solid {{ $p->activo ? '#DC2626' : '#15803D' }};border-radius:6px;color:{{ $p->activo ? '#DC2626' : '#15803D' }};background:white;cursor:pointer">
                            {{ $p->activo ? 'Desactivar' : 'Activar' }}
                        </button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#9CA3AF">Aún no hay personal de mano de obra directa.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
