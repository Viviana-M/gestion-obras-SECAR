@props([
    'title',            // Título de la pantalla (obligatorio)
    'icon' => null,     // Emoji opcional a la izquierda (respaldo si no se pasa iconSvg)
    'badge' => null,    // Pill opcional junto al título (ej. departamento)
])
{{-- Banner de título reutilizable. El slot por defecto es el subtítulo (admite <b>).
     Para un ícono nítido en blanco usa el slot <x-slot:iconSvg> con un <svg> (stroke=currentColor);
     si no, se usa el emoji del prop icon. --}}
<div class="page-banner">
    <div class="page-banner-main">
        @isset($iconSvg)
            <span class="page-banner-ico">{{ $iconSvg }}</span>
        @elseif($icon)
            <span class="page-banner-ico">{{ $icon }}</span>
        @endisset
        <div>
            <div class="page-banner-title">
                {{ $title }}
                @if($badge)<span class="page-banner-badge">{{ $badge }}</span>@endif
            </div>
            @if($slot->isNotEmpty())<div class="page-banner-sub">{{ $slot }}</div>@endif
        </div>
    </div>
    @isset($actions)<div class="page-banner-actions">{{ $actions }}</div>@endisset
</div>
