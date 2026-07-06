@php $esNuevo = is_null($usuario); @endphp

<div style="margin-bottom:14px">
    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Nombre completo</label>
    <input type="text" name="name" value="{{ old('name', $usuario->name ?? '') }}"
        style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
    <div>
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Correo</label>
        <input type="email" name="email" value="{{ old('email', $usuario->email ?? '') }}"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
    </div>
    <div>
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Sede (opcional)</label>
        <input type="text" name="sede" value="{{ old('sede', $usuario->sede ?? '') }}"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
    </div>
</div>

<div style="margin-bottom:14px">
    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Rol</label>
    <select name="rol" id="selRol"
        style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        <optgroup label="Acceso según módulos">
            <option value="admin" {{ old('rol', $usuario->rol ?? '') === 'admin' ? 'selected' : '' }}>Administrador (acceso total)</option>
            <option value="director"   {{ old('rol', $usuario->rol ?? '') === 'director' ? 'selected' : '' }}>Director</option>
            <option value="gerente"    {{ old('rol', $usuario->rol ?? '') === 'gerente' ? 'selected' : '' }}>Gerente</option>
            <option value="supervisor" {{ old('rol', $usuario->rol ?? '') === 'supervisor' ? 'selected' : '' }}>Supervisor</option>
            <option value="coordinador" {{ old('rol', $usuario->rol ?? '') === 'coordinador' ? 'selected' : '' }}>Coordinador</option>
        </optgroup>
    </select>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
    <div>
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">
            {{ $esNuevo ? 'Contraseña' : 'Nueva contraseña' }}
        </label>
        <input type="password" name="password" autocomplete="new-password"
            placeholder="{{ $esNuevo ? 'Mínimo 8 caracteres' : 'Dejar vacío para no cambiar' }}"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
    </div>
    <div>
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Confirmar contraseña</label>
        <input type="password" name="password_confirmation" autocomplete="new-password"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
    </div>
</div>

<div id="bloqueModulos">
    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:8px">Módulos que puede ver</label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        @foreach($modulos as $clave => $nombre)
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer">
            <input type="checkbox" name="modulos[]" value="{{ $clave }}"
                {{ in_array($clave, $marcados) ? 'checked' : '' }}>
            {{ $nombre }}
        </label>
        @endforeach
    </div>
    <p style="font-size:11px;color:#9CA3AF;margin-top:6px">
        Los administradores ven todos los módulos automáticamente; para ellos estas casillas no aplican.
    </p>
</div>

<script>
    (function () {
        const sel = document.getElementById('selRol');
        const bloque = document.getElementById('bloqueModulos');
        function refrescar() {
            const esAdmin = sel.value === 'admin';
            bloque.style.opacity = esAdmin ? '0.45' : '1';
            bloque.querySelectorAll('input[type=checkbox]').forEach(c => c.disabled = esAdmin);
        }
        sel.addEventListener('change', refrescar);
        refrescar();
    })();
</script>