<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Gestión Financiera de Proyectos')</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #F3F4F6; color: #3D3D3D; }
        .navbar { background: #1B3F6E; color: white; padding: 0 1.5rem; height: 56px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .navbar-left { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { display: flex; align-items: center; gap: 10px; }
        .navbar-brand span { font-size: 15px; font-weight: 600; color: white; }
        .menu-toggle { background: transparent; border: none; color: white; cursor: pointer; width: 34px; height: 34px; border-radius: 6px; display: flex; align-items: center; justify-content: center; }
        .menu-toggle:hover { background: rgba(255,255,255,0.12); }
        .menu-toggle svg { width: 22px; height: 22px; }
        .navbar-user { display: flex; align-items: center; gap: 12px; font-size: 13px; color: rgba(255,255,255,0.85); }
        .navbar-rol { font-size: 11px; padding: 3px 10px; border-radius: 12px; background: rgba(255,255,255,0.15); color: white; }
        .btn-logout { font-size: 12px; padding: 5px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.3); background: transparent; color: white; cursor: pointer; }
        .btn-logout:hover { background: rgba(255,255,255,0.1); }

        .layout { display: flex; min-height: calc(100vh - 56px); }
        .sidebar { width: 220px; background: #FFFFFF; border-right: 1px solid #E5E7EB; padding: 0.75rem 0; flex-shrink: 0; transition: width .2s ease; overflow: hidden; }
        .layout.collapsed .sidebar { width: 58px; }

        .module-head { width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 1.25rem; background: transparent; border: none; cursor: pointer; font-size: 13px; font-weight: 600; color: #374151; text-align: left; }
        .module-head:hover { background: #F3F4F6; }
        .module-ico { width: 20px; height: 20px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #6B7280; position: relative; }
        .module-ico svg { width: 20px; height: 20px; }
        .module-name { flex: 1; white-space: nowrap; }
        .chevron { width: 14px; height: 14px; flex-shrink: 0; color: #9CA3AF; transition: transform .2s ease; }
        .module.open .chevron { transform: rotate(90deg); }

        .submenu { max-height: 0; overflow: hidden; transition: max-height .2s ease; }
        .module.open .submenu { max-height: 500px; }
        .submenu a { display: flex; align-items: center; gap: 6px; padding: 8px 1.25rem 8px 3.4rem; font-size: 12.5px; color: #6B7280; text-decoration: none; border-left: 3px solid transparent; white-space: nowrap; }
        .submenu a:hover { background: #F3F4F6; color: #1B3F6E; }
        .submenu a.active { background: #D6E4F7; color: #1B3F6E; border-left-color: #1B3F6E; font-weight: 500; }

        .pill { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 8px; background: #FEF9C3; color: #854D0E; line-height: 1.5; }
        .dot-alerta { position: absolute; top: -2px; right: -2px; width: 8px; height: 8px; border-radius: 50%; background: #D97706; border: 1.5px solid #FFFFFF; }

        .layout.collapsed .module-head { justify-content: center; padding: 11px 0; }
        .layout.collapsed .module-name,
        .layout.collapsed .chevron,
        .layout.collapsed .submenu { display: none; }
        .layout.collapsed .module.active-mod .module-ico { color: #1B3F6E; }

        .content { flex: 1; padding: 1.5rem; overflow-x: hidden; min-width: 0; }
        .page-title { font-size: 18px; font-weight: 600; color: #1B3F6E; margin-bottom: 1.25rem; }
        .card { background: #FFFFFF; border: 1px solid #E5E7EB; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
        .card h3 { font-size: 14px; font-weight: 600; color: #3D3D3D; margin-bottom: 10px; }
    </style>
</head>
<body>

@php
    $usuario   = auth()->user();
    $colapsado = $usuario->menu_colapsado;

    // Las pantallas de Administración que viven bajo /contable/ no deben marcar Contabilidad.
    $adminEnContable = request()->is('contable/homologaciones')
                    || request()->is('contable/cierre-obras')
                    || request()->is('contable/reclasificaciones*');

    $finActive = request()->is('dashboard') || request()->is('financiero/*');
    $opActive  = request()->is('operativo/*');
    $comActive = request()->is('comercial/*');
    $conActive = request()->is('contable/*') && !$adminEnContable;
    $admActive = request()->is('admin/*') || $adminEnContable;

    // Reclasificaciones pendientes (solo se consulta para admin, que es quien las ve).
    $reclasPendientes = 0;
    if ($usuario->esAdmin()) {
        $reclasPendientes = \App\Models\Homologacion::sinFiltro()
            ->where('requiere_reclasificacion', true)
            ->whereNull('reclasificado_at')
            ->count();
    }
@endphp

<nav class="navbar">
    <div class="navbar-left">
        <button type="button" class="menu-toggle" id="menuToggle" aria-label="Plegar menú">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
        </button>
        <div class="navbar-brand">
            <img src="/images/logo-secar.JPG" alt="Secar Ingenieros" style="height:38px;width:auto;object-fit:contain;border-radius:4px;">
            <span>Gestión Financiera de Proyectos</span>
        </div>
    </div>
    <div class="navbar-user">
        <span>{{ $usuario->name }}</span>
        <span class="navbar-rol">{{ ucfirst($usuario->rol) }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-logout">Salir</button>
        </form>
    </div>
</nav>

<div class="layout {{ $colapsado ? 'collapsed' : '' }}">
    <aside class="sidebar">

        @if($usuario->puedeVerModulo('gestion_financiera'))
        <div class="module {{ $finActive ? 'open active-mod' : '' }}">
            <button type="button" class="module-head">
                <span class="module-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/></svg></span>
                <span class="module-name">Gestión financiera</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <div class="submenu">
                <a href="/dashboard" class="{{ request()->is('dashboard') ? 'active' : '' }}">Tablero gerencial</a>
                <a href="/financiero/dashboard" class="{{ request()->is('financiero/dashboard') ? 'active' : '' }}">Proyectos en curso</a>
                <a href="/financiero/historicos" class="{{ request()->is('financiero/historicos') ? 'active' : '' }}">Obras cerradas</a>
                <a href="/financiero/estados-financieros" class="{{ request()->is('financiero/estados-financieros') ? 'active' : '' }}">Estados financieros</a>
            </div>
        </div>
        @endif

        @if($usuario->puedeVerModulo('operacion'))
        <div class="module {{ $opActive ? 'open active-mod' : '' }}">
            <button type="button" class="module-head">
                <span class="module-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="16" x2="20" y2="16"/><circle cx="9" cy="8" r="2"/><circle cx="15" cy="16" r="2"/></svg></span>
                <span class="module-name">Operación</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <div class="submenu">
                <a href="/operativo/distribucion" class="{{ request()->is('operativo/distribucion') && !request()->is('operativo/distribucion/consultas') ? 'active' : '' }}">Distribución de costos</a>
                <a href="/operativo/distribucion/consultas" class="{{ request()->is('operativo/distribucion/consultas') ? 'active' : '' }}">Mis distribuciones</a>
                <a href="{{ route('operativo.maestro.index') }}" class="{{ request()->is('operativo/maestro-comercial') ? 'active' : '' }}">Maestro de proyectos</a>
                <a href="{{ route('operativo.obras-revision.index') }}" class="{{ request()->is('operativo/obras-revision') ? 'active' : '' }}">Obras en revisión</a>
            </div>
        </div>
        @endif

        @if($usuario->esGerencia())
        <div class="module {{ request()->is('operativo/autorizaciones') ? 'open active-mod' : '' }}">
            <button type="button" class="module-head">
                <span class="module-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9c1.66 0 3.22.45 4.56 1.24"/></svg></span>
                <span class="module-name">Autorizaciones</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <div class="submenu">
                <a href="{{ route('operativo.autorizaciones.index') }}" class="{{ request()->is('operativo/autorizaciones') ? 'active' : '' }}">Distribución sin ingreso</a>
            </div>
        </div>
        @endif

        @if($usuario->puedeVerModulo('comercial'))
        <div class="module {{ $comActive ? 'open active-mod' : '' }}">
            <button type="button" class="module-head">
                <span class="module-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></svg></span>
                <span class="module-name">Comercial</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <div class="submenu">
                <a href="/comercial/cotizaciones" class="{{ request()->is('comercial/cotizaciones') ? 'active' : '' }}">Cotizaciones y ofertas</a>
            </div>
        </div>
        @endif

        @if($usuario->puedeVerModulo('contabilidad'))
        <div class="module {{ $conActive ? 'open active-mod' : '' }}">
            <button type="button" class="module-head">
                <span class="module-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="15" x2="13" y2="15"/></svg></span>
                <span class="module-name">Contabilidad</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <div class="submenu">
                <a href="/contable/plano-contable" class="{{ request()->is('contable/plano-contable') ? 'active' : '' }}">Plano contable</a>
                <a href="/contable/carga" class="{{ request()->is('contable/carga') ? 'active' : '' }}">Cierre de mes</a>
                <a href="{{ route('contable.autoliquidacion.index') }}" class="{{ request()->is('contable/autoliquidacion') ? 'active' : '' }}">Autoliquidación (PILA)</a>
            </div>
        </div>
        @endif

        @if($usuario->esAdmin())
        <div class="module {{ $admActive ? 'open active-mod' : '' }}">
            <button type="button" class="module-head">
                <span class="module-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    @if($reclasPendientes > 0)<span class="dot-alerta"></span>@endif
                </span>
                <span class="module-name">Administración</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <div class="submenu">
                <a href="{{ route('admin.usuarios.index') }}" class="{{ request()->is('admin/usuarios*') ? 'active' : '' }}">Usuarios</a>
                <a href="{{ route('admin.un-bolsas.index') }}">Unidades de negocio</a>
                <a href="{{ route('admin.terceros-mano-obra.index') }}">Terceros mano de obra</a>
                <a href="{{ route('admin.mano-obra-directa.index') }}" class="{{ request()->is('admin/mano-obra-directa') ? 'active' : '' }}">Mano de obra directa</a>
                <a href="/contable/homologaciones" class="{{ request()->is('contable/homologaciones') ? 'active' : '' }}">Homologación cuentas</a>
                <a href="/contable/reclasificaciones" class="{{ request()->is('contable/reclasificaciones*') ? 'active' : '' }}">
                    Reclasificaciones
                    @if($reclasPendientes > 0)<span class="pill">{{ $reclasPendientes }}</span>@endif
                </a>
                <a href="/contable/cierre-obras" class="{{ request()->is('contable/cierre-obras') ? 'active' : '' }}">Cierre de obras</a>
            </div>
        </div>
        @endif

    </aside>

    <main class="content">
        @yield('content')
    </main>
</div>

<script>
    const layout = document.querySelector('.layout');
    const toggleBtn = document.getElementById('menuToggle');
    const token = document.querySelector('meta[name="csrf-token"]').content;

    function guardarEstado(colapsado) {
        fetch('/preferencias/menu', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            body: JSON.stringify({ colapsado: colapsado })
        }).catch(() => {});
    }

    toggleBtn.addEventListener('click', () => {
        const col = layout.classList.toggle('collapsed');
        guardarEstado(col);
    });

    document.querySelectorAll('.module-head').forEach(head => {
        head.addEventListener('click', () => {
            const mod = head.closest('.module');
            if (layout.classList.contains('collapsed')) {
                layout.classList.remove('collapsed');
                document.querySelectorAll('.module').forEach(m => m.classList.remove('open'));
                mod.classList.add('open');
                guardarEstado(false);
            } else {
                mod.classList.toggle('open');
            }
        });
    });

    if (!layout.classList.contains('collapsed') && !document.querySelector('.module.open')) {
        const first = document.querySelector('.module');
        if (first) first.classList.add('open');
    }
</script>

</body>
</html>