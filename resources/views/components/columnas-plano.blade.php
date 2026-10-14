@props([
    'titulo'  => 'El archivo plano debe traer estas columnas:',
    'columnas' => [],   // item: string, o ['nombre'=>, 'pos'=>, 'nota'=>, 'opcional'=>, 'obligatoria'=>, 'nueva'=>]
    'numerar' => true,  // false = mapeo por nombre (el orden no importa)
])
{{-- Bloque unificado que indica los campos requeridos del archivo plano de un módulo. --}}
<div style="margin-top:12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px">
    <div style="font-size:12px;font-weight:700;color:#1B3F6E;margin-bottom:8px">📄 {{ $titulo }}</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach($columnas as $i => $col)
            @php
                $c    = is_array($col) ? $col : ['nombre' => $col];
                $pos  = $c['pos'] ?? ($numerar ? ($i + 1) : '•');
                $opc  = $c['opcional'] ?? false;
                $oblig = $c['obligatoria'] ?? false;
                $nueva = $c['nueva'] ?? false;
                $nota = $c['nota'] ?? null;
                // Prioridad visual: nueva (verde) > obligatoria (azul fuerte) > opcional (gris) > normal.
                $borde = $nueva ? '#BEE3C9' : ($oblig ? '#C7D2FE' : ($opc ? '#E5E7EB' : '#C7D2FE'));
                $fondo = $nueva ? '#E7F6EC' : 'white';
                $texto = $opc && ! $nueva && ! $oblig ? '#9CA3AF' : '#374151';
            @endphp
            <span @if($nota) title="{{ $nota }}" @endif
                style="display:inline-flex;align-items:center;gap:6px;font-size:11px;background:{{ $fondo }};border:1px solid {{ $borde }};border-radius:6px;padding:3px 8px;color:{{ $texto }}">
                <span style="font-weight:700;color:{{ $oblig ? '#DC2626' : '#6366F1' }};min-width:10px;text-align:center">{{ $pos }}</span>
                <span>{{ $c['nombre'] }}</span>
                @if($nueva)<span style="background:#16A34A;color:white;border-radius:10px;font-size:8px;font-weight:800;padding:1px 5px;letter-spacing:.03em">NUEVO</span>@endif
                @if($oblig && ! $nueva)<span style="color:#DC2626;font-weight:700" title="Obligatoria">*</span>@endif
                @if($opc)<span style="color:#9CA3AF;font-style:italic">(opcional)</span>@endif
                @if($nota)<span style="color:#9CA3AF">ⓘ</span>@endif
            </span>
        @endforeach
    </div>
    @isset($slot)
        @if(trim($slot))
            <div style="font-size:11px;color:#6B7280;margin-top:8px;line-height:1.5">{{ $slot }}</div>
        @endif
    @endisset
</div>
