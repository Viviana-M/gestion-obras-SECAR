@props([
    'titulo'  => 'El archivo plano debe traer estas columnas:',
    'columnas' => [],   // cada item: string, o ['nombre'=>, 'pos'=>, 'nota'=>, 'opcional'=>bool]
    'numerar' => true,  // false = mapeo por nombre (el orden no importa)
])
{{-- Bloque unificado que indica los campos requeridos del archivo plano de un módulo. --}}
<div style="margin-top:12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px">
    <div style="font-size:12px;font-weight:700;color:#1B3F6E;margin-bottom:8px">📄 {{ $titulo }}</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach($columnas as $i => $col)
            @php
                $c   = is_array($col) ? $col : ['nombre' => $col];
                $pos = $c['pos'] ?? ($numerar ? ($i + 1) : '•');
                $opc = $c['opcional'] ?? false;
                $nota = $c['nota'] ?? null;
            @endphp
            <span @if($nota) title="{{ $nota }}" @endif
                style="display:inline-flex;align-items:center;gap:6px;font-size:11px;background:white;border:1px solid {{ $opc ? '#E5E7EB' : '#C7D2FE' }};border-radius:6px;padding:3px 8px;color:{{ $opc ? '#9CA3AF' : '#374151' }}">
                <span style="font-weight:700;color:#6366F1;min-width:10px;text-align:center">{{ $pos }}</span>
                <span>{{ $c['nombre'] }}</span>
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
