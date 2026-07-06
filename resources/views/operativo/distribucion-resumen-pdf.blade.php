<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #3D3D3D; }
        h1 { font-size: 14px; color: #1B3F6E; margin: 0 0 4px; }
        .sub { font-size: 9px; color: #6B7280; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #E5E7EB; padding: 5px 7px; }
        th { background: #1B3F6E; color: white; font-size: 9px; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        td { text-align: right; }
        .ingreso td { background: #D6E4F7; font-weight: bold; }
        .cat td { background: #F0FDF4; }
        .totalcosto td { background: #BBF7D0; font-weight: bold; }
        .mc td { font-weight: bold; }
        .mcpct td { background: #F3F4F6; font-weight: bold; }
    </style>
</head>
<body>
    @php
        $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
        $pct = fn($p, $t) => ($t != 0) ? number_format($p / $t * 100, 1, ',', '.').'%' : '—';
    @endphp

    <h1>Resumen de distribución · {{ $periodo }}</h1>
    <div class="sub">Plano #{{ $version->distribucion_id }} · versión #{{ $version->id }} · {{ $version->created_at?->format('d/m/Y H:i') }} · {{ $version->user_nombre }}</div>

    <table>
        <thead>
            <tr>
                <th>ÍTEM</th>
                @foreach($tabla as $tk => $t)
                    <th>{{ mb_strtoupper($tipos[$tk] ?? $tk) }}</th>
                    <th>PART.</th>
                @endforeach
                <th>TOTAL</th>
                <th>PART.</th>
            </tr>
        </thead>
        <tbody>
            <tr class="ingreso">
                <td>INGRESO</td>
                @foreach($tabla as $tk => $t)
                    <td>{{ $fmt($t['ingreso']) }}</td><td></td>
                @endforeach
                <td>{{ $fmt($todo['ingreso']) }}</td><td></td>
            </tr>
            @foreach($categorias as $ck => $cl)
            <tr class="cat">
                <td>{{ $ck }}</td>
                @foreach($tabla as $tk => $t)
                    <td>{{ $fmt($t['cat'][$ck] ?? 0) }}</td>
                    <td>{{ $pct($t['cat'][$ck] ?? 0, $t['costo_total']) }}</td>
                @endforeach
                <td>{{ $fmt($todo['cat'][$ck] ?? 0) }}</td>
                <td>{{ $pct($todo['cat'][$ck] ?? 0, $todo['costo_total']) }}</td>
            </tr>
            @endforeach
            <tr class="totalcosto">
                <td>TOTAL COSTO</td>
                @foreach($tabla as $tk => $t)
                    <td>{{ $fmt($t['costo_total']) }}</td>
                    <td>{{ $t['costo_total'] != 0 ? '100%' : '—' }}</td>
                @endforeach
                <td>{{ $fmt($todo['costo_total']) }}</td><td>100%</td>
            </tr>
            <tr class="mc">
                <td>MC ($)</td>
                @foreach($tabla as $tk => $t)
                    <td>{{ $fmt($t['mc_pesos']) }}</td><td></td>
                @endforeach
                <td>{{ $fmt($todo['mc_pesos']) }}</td><td></td>
            </tr>
            <tr class="mcpct">
                <td>MC %</td>
                @foreach($tabla as $tk => $t)
                    <td>{{ $t['mc_pct'] === null ? '—' : $t['mc_pct'].'%' }}</td><td></td>
                @endforeach
                <td>{{ $todo['mc_pct'] === null ? '—' : $todo['mc_pct'].'%' }}</td><td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>