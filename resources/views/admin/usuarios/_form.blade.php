@php
    $esNuevo = is_null($usuario);
    $niveles = $niveles ?? [];
    $nivelDe = function ($clave) use ($niveles) {
        return old("permisos.$clave", $niveles[$clave] ?? 'no');
    };
    $depClaves = \App\Models\User::DEPARTAMENTOS;
    $modulosReales = array_filter($modulos, fn($k) => !in_array($k, $depClaves), ARRAY_FILTER_USE_KEY);
    $modulosDepto  = array_filter($modulos, fn($k) => in_array($k, $depClaves), ARRAY_FILTER_USE_KEY);
@endphp

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
    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Cargo</label>
    <select name="rol" id="selRol"
        style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        @php
            $cargos = [
                'admin'                   => 'Administrador (acceso total)',
                'gerente'                 => 'Gerente',
                'dir_operaciones'         => 'Dir. Operaciones',
                'dir_instalaciones'       => 'Dir. Instalaciones',
                'dir_mantenimiento'       => 'Dir. Mantenimiento',
                'director_comercial'      => 'Director Comercial',
                'director_compras'        => 'Director Compras',
                'dir_admin_auditoria'     => 'Directora Administrativa & Auditoría Interna',
                'coordinadora_mtto'       => 'Coordinadora Mantenimiento',
                'supervisor_mtto'         => 'Supervisor Mtto',
                'ing_instalaciones'       => 'Ing. Instalaciones',
                'comercial'               => 'Comercial',
                'comercial_mtto'          => 'Comercial Mtto',
                'contadora'               => 'Contadora',
                'aux_comercial'           => 'Aux. Comercial',
                'aux_costos'              => 'Aux. Costos',
                'aux_admin_instalaciones' => 'Auxiliar Administrativo de Instalaciones',
                'consulta_obras'          => 'Solo consulta — Obras',
            ];
            $rolActual = old('rol', $usuario->rol ?? '');
        @endphp
        @foreach($cargos as $valor => $etiqueta)
            <option value="{{ $valor }}" {{ $rolActual === $valor ? 'selected' : '' }}>{{ $etiqueta }}</option>
        @endforeach
    </select>
    <p style="font-size:11px;color:#9CA3AF;margin-top:6px">
        El cargo es una etiqueta descriptiva. El acceso real lo definen los permisos de abajo,
        salvo para Administrador, que ve y edita todo.
    </p>
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

<div id="bloqueModulos" style="margin-bottom:8px">
    <p id="notaConsultaObras" style="display:none;font-size:12px;color:#5B3FA8;background:#F1EDFB;border:1px solid #D8CBF0;border-radius:8px;padding:9px 11px;margin-bottom:10px">
        <b>Solo consulta — Obras:</b> este usuario solo verá la pantalla <b>Consulta de Obras</b> (de solo
        lectura). No necesitas marcar módulos; únicamente elige su <b>departamento</b> más abajo.
    </p>

    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:8px">Permisos por módulo</label>

    <div id="gridModulos" style="border:1px solid #E5E7EB;border-radius:10px;overflow:hidden">
        <div style="display:grid;grid-template-columns:1fr 90px 90px 90px;background:#F3F4F6;padding:8px 12px;font-size:11px;color:#6B7280;font-weight:600">
            <span>Módulo</span>
            <span style="text-align:center">Sin acceso</span>
            <span style="text-align:center">Ver</span>
            <span style="text-align:center">Editar</span>
        </div>

        @foreach($modulosReales as $clave => $nombre)
        @php $n = $nivelDe($clave); @endphp
        <div style="display:grid;grid-template-columns:1fr 90px 90px 90px;padding:9px 12px;border-top:1px solid #F3F4F6;align-items:center">
            <span style="font-size:13px;color:#374151">{{ $nombre }}</span>
            <span style="text-align:center"><input type="radio" name="permisos[{{ $clave }}]" value="no"     {{ $n === 'no' ? 'checked' : '' }}></span>
            <span style="text-align:center"><input type="radio" name="permisos[{{ $clave }}]" value="ver"    {{ $n === 'ver' ? 'checked' : '' }}></span>
            <span style="text-align:center"><input type="radio" name="permisos[{{ $clave }}]" value="editar" {{ $n === 'editar' ? 'checked' : '' }}></span>
        </div>
        @endforeach
    </div>

    @if(count($modulosDepto) > 0)
    <div id="bloqueDepartamentos">
    <label style="font-size:12px;color:#6B7280;display:block;margin:14px 0 8px">Departamentos (qué obras ve en Distribución)</label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        @foreach($modulosDepto as $clave => $nombre)
        @php $pert = $nivelDe($clave) !== 'no'; @endphp
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer">
            <input type="hidden" name="permisos[{{ $clave }}]" value="no">
            <input type="checkbox" onchange="this.previousElementSibling.value = this.checked ? 'ver' : 'no'"
                   {{ $pert ? 'checked' : '' }}>
            {{ $nombre }}
        </label>
        @endforeach
    </div>
    </div>
    @endif

    <p style="font-size:11px;color:#9CA3AF;margin-top:8px">
        <b>Ver</b>: entra y consulta, pero no puede guardar cambios.
        <b>Editar</b>: acceso completo.
        El Administrador ve y edita todo automáticamente; para él estos permisos no aplican.
    </p>
</div>

<script>
    (function () {
        const sel     = document.getElementById('selRol');
        const bloque  = document.getElementById('bloqueModulos');
        const grid    = document.getElementById('gridModulos');        // módulos reales (no deptos)
        const deptos  = document.getElementById('bloqueDepartamentos'); // departamentos
        const nota    = document.getElementById('notaConsultaObras');
        function refrescar() {
            const esAdmin    = sel.value === 'admin';
            const esConsulta = sel.value === 'consulta_obras';
            // Admin: no aplican permisos. Consulta: solo importa el departamento (módulos ocultos).
            bloque.style.opacity = esAdmin ? '0.45' : '1';
            bloque.querySelectorAll('input').forEach(c => c.disabled = esAdmin);
            if (nota) nota.style.display = esConsulta ? 'block' : 'none';
            if (grid) {
                grid.style.display = esConsulta ? 'none' : '';
                // Las radios de módulo no deben enviarse para consulta (el backend fuerza operación:ver).
                grid.querySelectorAll('input').forEach(c => c.disabled = esAdmin || esConsulta);
            }
            if (deptos) deptos.querySelectorAll('input').forEach(c => c.disabled = esAdmin);
        }
        sel.addEventListener('change', refrescar);
        refrescar();
    })();
</script>