@extends('layouts.app')

@section('title', 'Administración de usuarios')

@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h1 class="page-title" style="margin-bottom:0">Administración de usuarios</h1>
    <a href="{{ route('admin.usuarios.create') }}"
        style="padding:8px 18px;background:#1B3F6E;color:white;text-decoration:none;border-radius:8px;font-size:13px">
        + Crear usuario
    </a>
</div>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">
    {{ session('success') }}
</div>
@endif

@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">
    {{ $errors->first() }}
</div>
@endif

<div class="card">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Nombre</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Correo</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Rol</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Módulos</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Estado</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($usuarios as $u)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:8px 10px">{{ $u->name }}</td>
                <td style="padding:8px 10px;color:#6B7280">{{ $u->email }}</td>
                <td style="padding:8px 10px">
                    @if($u->rol === 'admin')
                        <span style="background:#EFF6FF;color:#1D4ED8;font-size:10px;padding:2px 8px;border-radius:10px">Administrador</span>
                    @else
                        <span style="background:#F3F4F6;color:#6B7280;font-size:10px;padding:2px 8px;border-radius:10px">{{ ucfirst($u->rol) }}</span>
                    @endif
                </td>
                <td style="padding:8px 10px;color:#6B7280;font-size:11px">
                    @if($u->esAdmin())
                        Todos
                    @else
                        @php
                            $suyos = $u->modulos_permitidos ?? $u->modulosLegado();
                            $nombres = array_map(fn($c) => $modulos[$c] ?? $c, $suyos);
                        @endphp
                        {{ count($nombres) ? implode(', ', $nombres) : '—' }}
                    @endif
                </td>
                <td style="padding:8px 10px">
                    @if($u->activo)
                        <span style="background:#F0FDF4;color:#15803D;font-size:10px;padding:2px 8px;border-radius:10px">Activo</span>
                    @else
                        <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:2px 8px;border-radius:10px">Inactivo</span>
                    @endif
                </td>
                <td style="padding:8px 10px">
                    <div style="display:flex;gap:6px;align-items:center">
                        <a href="{{ route('admin.usuarios.edit', $u->id) }}"
                            style="font-size:11px;padding:3px 10px;color:#1B3F6E;border:1px solid #C7D7EC;border-radius:6px;background:white;text-decoration:none">
                            Editar
                        </a>
                        @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.usuarios.estado', $u->id) }}"
                            onsubmit="return confirm('¿{{ $u->activo ? 'Desactivar' : 'Activar' }} a {{ $u->name }}?')">
                            @csrf
                            <button type="submit"
                                style="font-size:11px;padding:3px 10px;border-radius:6px;cursor:pointer;background:white;border:1px solid {{ $u->activo ? '#FECACA' : '#BBF7D0' }};color:{{ $u->activo ? '#DC2626' : '#15803D' }}">
                                {{ $u->activo ? 'Desactivar' : 'Activar' }}
                            </button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection