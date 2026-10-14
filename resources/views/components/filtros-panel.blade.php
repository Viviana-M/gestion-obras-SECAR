{{-- Panel de filtros reutilizable: tarjeta con encabezado (icono embudo + "Filtros").
     El slot contiene el form GET con campos que usan las clases .filtro-field /
     .filtro-label / .filtro-select / .filtro-input / .btn-filtrar. --}}
<div {{ $attributes->merge(['class' => 'card filtros-panel']) }}>
    <div class="filtros-head">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#1B3F6E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        <span class="filtros-head-title">{{ $titulo ?? 'Filtros' }}</span>
    </div>
    {{ $slot }}
</div>
