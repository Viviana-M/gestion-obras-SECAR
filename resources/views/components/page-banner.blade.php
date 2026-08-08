@props([
    'title',            // Título de la pantalla (obligatorio)
    'icon' => null,     // Emoji opcional a la izquierda
    'badge' => null,    // Pill opcional junto al título (ej. departamento)
])
{{-- Banner de título reutilizable. El slot por defecto es el subtítulo (admite <b>). --}}
<div class="page-banner">
    <div class="page-banner-main">
        @if($icon)<span class="page-banner-ico">{{ $icon }}</span>@endif
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
