@extends('layouts.app')

@section('title', 'Crear usuario')

@section('content')
<h1 class="page-title">Crear usuario</h1>

@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">
    <ul style="margin:0;padding-left:18px">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

<div class="card" style="max-width:640px">
    <form method="POST" action="{{ route('admin.usuarios.store') }}">
        @csrf
        @include('admin.usuarios._form', ['modulos' => $modulos, 'marcados' => old('modulos', []), 'usuario' => null])
        <button type="submit"
            style="margin-top:1rem;padding:8px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
            Crear usuario
        </button>
        <a href="{{ route('admin.usuarios.index') }}" style="margin-left:8px;font-size:13px;color:#6B7280;text-decoration:none">Cancelar</a>
    </form>
</div>
@endsection