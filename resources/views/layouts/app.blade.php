<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Gestión Financiera de Proyectos')</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #F3F4F6; color: #3D3D3D; }
        .navbar { background: #1B3F6E; color: white; padding: 0 1.5rem; height: 56px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .navbar-brand { display: flex; align-items: center; gap: 10px; }
        .navbar-brand span { font-size: 15px; font-weight: 600; color: white; }
        .navbar-user { display: flex; align-items: center; gap: 12px; font-size: 13px; color: rgba(255,255,255,0.85); }
        .navbar-rol { font-size: 11px; padding: 3px 10px; border-radius: 12px; background: rgba(255,255,255,0.15); color: white; }
        .btn-logout { font-size: 12px; padding: 5px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.3); background: transparent; color: white; cursor: pointer; }
        .btn-logout:hover { background: rgba(255,255,255,0.1); }
        .layout { display: flex; min-height: calc(100vh - 56px); }
        .sidebar { width: 220px; background: #FFFFFF; border-right: 1px solid #E5E7EB; padding: 1rem 0; flex-shrink: 0; }
        .sidebar-section { font-size: 10px; font-weight: 600; color: #9CA3AF; letter-spacing: 0.8px; text-transform: uppercase; padding: 8px 1.25rem 4px; margin-top: 8px; }
        .sidebar a { display: flex; align-items: center; gap: 10px; padding: 9px 1.25rem; font-size: 13px; color: #6B7280; text-decoration: none; border-left: 3px solid transparent; transition: all .15s; }
        .sidebar a:hover, .sidebar a.active { background: #D6E4F7; color: #1B3F6E; border-left-color: #1B3F6E; }
        .sidebar a.active { font-weight: 500; }
        .content { flex: 1; padding: 1.5rem; overflow-x: hidden; }
        .page-title { font-size: 18px; font-weight: 600; color: #1B3F6E; margin-bottom: 1.25rem; }
        .card { background: #FFFFFF; border: 1px solid #E5E7EB; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
        .card h3 { font-size: 14px; font-weight: 600; color: #3D3D3D; margin-bottom: 10px; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">
        <img src="/images/logo-secar.JPG" alt="Secar Ingenieros"
             style="height:38px;width:auto;object-fit:contain;border-radius:4px;">
        <span>Gestión Financiera de Proyectos</span>
    </div>
    <div class="navbar-user">
        <span>{{ auth()->user()->name }}</span>
        <span class="navbar-rol">{{ ucfirst(auth()->user()->rol) }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-logout">Salir</button>
        </form>
    </div>
</nav>

<div class="layout">
    <aside class="sidebar">
        @php $rol = auth()->user()->rol; @endphp

        @if(in_array($rol, ['admin', 'financiero']))
    <div class="sidebar-section">Financiero</div>
    <a href="/financiero/dashboard" class="{{ request()->is('financiero/dashboard') ? 'active' : '' }}">Proyectos activos</a>
    <a href="/financiero/historicos" class="{{ request()->is('financiero/historicos') ? 'active' : '' }}">Históricos</a>
    <a href="/financiero/estados-financieros" class="{{ request()->is('financiero/estados-financieros') ? 'active' : '' }}">Estados financieros</a>
    @endif

        @if(in_array($rol, ['admin', 'operativo']))
            <div class="sidebar-section">Operativo</div>
            <a href="/operativo/dashboard" class="{{ request()->is('operativo/dashboard') ? 'active' : '' }}">Proyectos</a>
            <a href="/operativo/forecast" class="{{ request()->is('operativo/forecast') ? 'active' : '' }}">Forecast costos</a>
        @endif

        @if(in_array($rol, ['admin', 'comercial']))
            <div class="sidebar-section">Comercial</div>
            <a href="/comercial/cotizaciones" class="{{ request()->is('comercial/cotizaciones') ? 'active' : '' }}">Cotizaciones</a>
        @endif

        @if(in_array($rol, ['admin', 'contable']))
            <div class="sidebar-section">Contable</div>
            <a href="/contable/dashboard" class="{{ request()->is('contable/dashboard') ? 'active' : '' }}">Archivo plano</a>
            <a href="/contable/carga" class="{{ request()->is('contable/carga') ? 'active' : '' }}">Cierre de mes</a>
            <a href="/contable/cierre-obras" class="{{ request()->is('contable/cierre-obras') ? 'active' : '' }}">Cierre de obras</a>
            @endif
    </aside>

    <main class="content">
        @yield('content')
    </main>
</div>

</body>
</html>