@php
    $esSeccion  = $n['len'] == 1;
    $esGrupo    = $n['len'] == 2;
    $esHoja     = $n['len'] == 8;
    $tieneHijos = $n['len'] < 8;
    $padLeft    = 14 + $n['nivel'] * 22;

    $v   = $n['monto'];
    $neg = $v < 0;
    $valorTxt = ($neg ? '-' : '') . '$' . number_format(abs($v), 0, ',', '.');

    $clase = 'er-linea nodo';
    if ($esSeccion)      $clase .= ' er-sec';
    elseif ($esGrupo)    $clase .= ' er-g2';
    elseif ($esHoja)     $clase .= ' er-leaf';
    if ($tieneHijos)     $clase .= ' er-click';
@endphp
<div class="{{ $clase }}"
     data-code="{{ $n['code'] }}"
     data-len="{{ $n['len'] }}"
     style="padding-left:{{ $padLeft }}px;{{ $n['len'] > 2 ? 'display:none;' : '' }}"
     @if($tieneHijos) onclick="toggleNodo('{{ $n['code'] }}')" @endif>
    <span class="er-chev">{{ $tieneHijos ? '▸' : '' }}</span>
    <span class="er-label"><span class="er-code">{{ $n['code'] }}</span>@if($n['nombre']) · {{ $n['nombre'] }}@endif</span>
    <span class="er-dots"></span>
    <span class="er-valor {{ $neg ? 'neg' : '' }}">{{ $valorTxt }}</span>
</div>