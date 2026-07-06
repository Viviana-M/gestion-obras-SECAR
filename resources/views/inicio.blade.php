@extends('layouts.app')

@section('title', 'Tablero gerencial')

@section('content')

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <h1 class="page-title" style="margin-bottom:2px">Tablero gerencial</h1>
        <span style="font-size:12px;color:#9CA3AF">
            Comparativo Ene–{{ $nombreMesCorte }} · {{ $anio1 }} vs {{ $anio2 }}
            <span style="background:#EFF6FF;color:#1D4ED8;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:6px">
                Mismo período ambos años
            </span>
        </span>
    </div>
    <form method="GET" action="{{ route('dashboard') }}" style="display:flex;gap:8px;align-items:center">
        <select name="anio1" style="padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            @foreach($aniosDisponibles as $a)
                <option value="{{ $a }}" {{ $a == $anio1 ? 'selected' : '' }}>{{ $a }}</option>
            @endforeach
        </select>
        <span style="color:#9CA3AF;font-size:12px;font-weight:500">VS</span>
        <select name="anio2" style="padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            @foreach($aniosDisponibles as $a)
                <option value="{{ $a }}" {{ $a == $anio2 ? 'selected' : '' }}>{{ $a }}</option>
            @endforeach
        </select>
        <button type="submit" style="padding:6px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;font-weight:500">
            Comparar
        </button>
    </form>
</div>

{{-- KPI CARDS --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1rem">

    <div style="background:white;border-radius:10px;padding:14px 16px;border:1px solid #E5E7EB;border-left:4px solid #1B3F6E">
        <div style="font-size:10px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Ingresos {{ $anio2 }}</div>
        <div style="font-size:19px;font-weight:700;color:#1B3F6E">${{ number_format($totalIngreso2/1000000, 1) }}M</div>
        <div style="font-size:11px;margin-top:4px;color:{{ $totalIngreso2 >= $totalIngreso1 ? '#16A34A' : '#DC2626' }}">
            {{ $totalIngreso2 >= $totalIngreso1 ? '▲' : '▼' }}
            {{ $totalIngreso1 > 0 ? number_format(abs(($totalIngreso2 - $totalIngreso1) / $totalIngreso1 * 100), 1) . '%' : '—' }}
            <span style="color:#9CA3AF">vs {{ $anio1 }} (${{ number_format($totalIngreso1/1000000, 1) }}M)</span>
        </div>
    </div>

    <div style="background:white;border-radius:10px;padding:14px 16px;border:1px solid #E5E7EB;border-left:4px solid #DC2626">
        <div style="font-size:10px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Costos aplicados {{ $anio2 }}</div>
        <div style="font-size:19px;font-weight:700;color:#DC2626">${{ number_format($totalCosto2/1000000, 1) }}M</div>
        <div style="font-size:11px;margin-top:4px;color:{{ $totalCosto2 <= $totalCosto1 ? '#16A34A' : '#DC2626' }}">
            {{ $totalCosto2 <= $totalCosto1 ? '▼' : '▲' }}
            {{ $totalCosto1 > 0 ? number_format(abs(($totalCosto2 - $totalCosto1) / $totalCosto1 * 100), 1) . '%' : '—' }}
            <span style="color:#9CA3AF">vs {{ $anio1 }} (${{ number_format($totalCosto1/1000000, 1) }}M)</span>
        </div>
    </div>

    <div style="background:white;border-radius:10px;padding:14px 16px;border:1px solid #E5E7EB;border-left:4px solid #D97706">
        <div style="font-size:10px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Costos por aplicar {{ $anio2 }}</div>
        <div style="font-size:19px;font-weight:700;color:#D97706">${{ number_format($totalPorAplicar2/1000000, 1) }}M</div>
        <div style="font-size:11px;margin-top:4px;color:{{ $totalPorAplicar2 <= $totalPorAplicar1 ? '#16A34A' : '#DC2626' }}">
            {{ $totalPorAplicar2 <= $totalPorAplicar1 ? '▼' : '▲' }}
            {{ $totalPorAplicar1 > 0 ? number_format(abs(($totalPorAplicar2 - $totalPorAplicar1) / $totalPorAplicar1 * 100), 1) . '%' : '—' }}
            <span style="color:#9CA3AF">vs {{ $anio1 }} (${{ number_format($totalPorAplicar1/1000000, 1) }}M)</span>
        </div>
    </div>

    <div style="background:{{ $margen2 >= 15 ? '#F0FDF4' : ($margen2 >= 5 ? '#FFFBEB' : '#FEF2F2') }};border-radius:10px;padding:14px 16px;border:1px solid #E5E7EB;border-left:4px solid {{ $margen2 >= 15 ? '#16A34A' : ($margen2 >= 5 ? '#D97706' : '#DC2626') }}">
        <div style="font-size:10px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Margen bruto {{ $anio2 }}</div>
        <div style="font-size:19px;font-weight:700;color:{{ $margen2 >= 15 ? '#16A34A' : ($margen2 >= 5 ? '#D97706' : '#DC2626') }}">{{ $margen2 }}%</div>
        <div style="font-size:11px;margin-top:4px;color:{{ $margen2 >= $margen1 ? '#16A34A' : '#DC2626' }}">
            {{ $margen2 >= $margen1 ? '▲' : '▼' }}
            {{ number_format(abs($margen2 - $margen1), 1) }}pp
            <span style="color:#9CA3AF">vs {{ $anio1 }} ({{ $margen1 }}%)</span>
        </div>
    </div>
</div>

{{-- GRÁFICO PRINCIPAL APILADO --}}
<div style="background:white;border-radius:10px;padding:16px;border:1px solid #E5E7EB;margin-bottom:1rem">
    <div style="margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#1B3F6E">Composición financiera mensual — barras apiladas</div>
        <div style="font-size:11px;color:#9CA3AF">Haz clic en un mes para ver su detalle · puntos verdes = margen positivo, rojos = negativo</div>
    </div>
    <canvas id="grafico-principal" height="80"></canvas>
</div>

{{-- GRÁFICOS INFERIORES --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">

    {{-- Costos por aplicar --}}
    <div style="background:white;border-radius:10px;padding:16px;border:1px solid #E5E7EB">
        <div style="font-size:13px;font-weight:600;color:#1B3F6E;margin-bottom:2px">Costos por aplicar</div>
        <div style="font-size:10px;color:#9CA3AF;margin-bottom:8px">Evolución mensual</div>
        <div style="display:flex;gap:6px;margin-bottom:10px">
            <button onclick="toggleSerie('aplChart', 0)" id="btn-apl-0"
                style="padding:3px 10px;font-size:10px;border-radius:6px;border:2px solid #FCD34D;background:#FEF9C3;color:#92400E;cursor:pointer">
                {{ $anio1 }}
            </button>
            <button onclick="toggleSerie('aplChart', 1)" id="btn-apl-1"
                style="padding:3px 10px;font-size:10px;border-radius:6px;border:2px solid #D97706;background:#D97706;color:white;cursor:pointer">
                {{ $anio2 }}
            </button>
        </div>
        <canvas id="grafico-por-aplicar" height="150"></canvas>
    </div>

    {{-- Margen --}}
    <div style="background:white;border-radius:10px;padding:16px;border:1px solid #E5E7EB">
        <div style="font-size:13px;font-weight:600;color:#1B3F6E;margin-bottom:2px">% Margen mensual</div>
        <div style="font-size:10px;color:#9CA3AF;margin-bottom:8px">Mes a mes independiente · verde positivo, rojo negativo</div>
        <div style="display:flex;gap:6px;margin-bottom:10px">
            <button onclick="toggleSerie('marChart', 0)" id="btn-mar-0"
                style="padding:3px 10px;font-size:10px;border-radius:6px;border:2px solid #94A3B8;background:#F1F5F9;color:#475569;cursor:pointer">
                {{ $anio1 }}
            </button>
            <button onclick="toggleSerie('marChart', 1)" id="btn-mar-1"
                style="padding:3px 10px;font-size:10px;border-radius:6px;border:2px solid #1B3F6E;background:#1B3F6E;color:white;cursor:pointer">
                {{ $anio2 }}
            </button>
        </div>
        <canvas id="grafico-margen" height="150"></canvas>
    </div>
</div>

{{-- MODAL DETALLE DEL MES --}}
<div id="modal-grafico" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;align-items:center;justify-content:center;padding:24px" onclick="cerrarGrafico(event)">
    <div style="background:white;border-radius:12px;padding:20px;width:min(560px,95vw);max-height:90vh;overflow:auto" onclick="event.stopPropagation()">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <div id="mes-titulo" style="font-size:15px;font-weight:600;color:#1B3F6E">Detalle del mes</div>
            <button onclick="cerrarGrafico()" style="border:none;background:#F3F4F6;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:16px;color:#6B7280">✕</button>
        </div>
        <div id="mes-contenido"></div>
    </div>
</div>

{{-- PANEL DE PROYECTOS EN RIESGO --}}
<div style="background:white;border-radius:10px;padding:16px;border:1px solid #E5E7EB;margin-top:1rem">
    <div style="margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#1B3F6E">Proyectos en riesgo · {{ $anio2 }}</div>
        <div style="font-size:11px;color:#9CA3AF">Dónde mirar este año — obras cerradas excluidas</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

        {{-- Con costo sin ingreso --}}
        <div style="border:1px solid #FDE68A;border-radius:10px;overflow:hidden">
            <div style="background:#FEF9C3;padding:10px 14px;border-bottom:1px solid #FDE68A">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span style="font-size:12px;font-weight:600;color:#854D0E">Con costo, sin ingreso</span>
                    <span style="font-size:11px;color:#854D0E">{{ $sinIngresoCount }} obras</span>
                </div>
                <div style="font-size:11px;color:#9CA3AF;margin-top:2px">
                    Pendiente de facturar: <strong style="color:#B45309">${{ number_format($sinIngresoTotal, 0, ',', '.') }}</strong>
                </div>
            </div>
            <div style="max-height:280px;overflow:auto">
                @forelse($sinIngreso as $p)
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #F9FAFB;font-size:12px">
                    <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <span style="font-family:monospace;font-weight:600;color:#374151">{{ $p['codigo'] }}</span>
                        <span style="color:#9CA3AF"> · {{ \Illuminate\Support\Str::limit($p['nombre'], 28) }}</span>
                    </div>
                    <span style="font-weight:600;color:#B45309;white-space:nowrap">${{ number_format($p['costo_total'], 0, ',', '.') }}</span>
                </div>
                @empty
                <div style="padding:1.5rem;text-align:center;color:#9CA3AF;font-size:12px">Sin obras en esta condición 🎉</div>
                @endforelse
            </div>
            @if($sinIngresoCount > 8)
            <div style="padding:6px 14px;font-size:10px;color:#9CA3AF;text-align:center;background:#FFFBEB">
                Mostrando las 8 de mayor monto · ver todas en Estado de resultados
            </div>
            @endif
        </div>

        {{-- Margen negativo --}}
        <div style="border:1px solid #FECACA;border-radius:10px;overflow:hidden">
            <div style="background:#FEF2F2;padding:10px 14px;border-bottom:1px solid #FECACA">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span style="font-size:12px;font-weight:600;color:#DC2626">Margen negativo</span>
                    <span style="font-size:11px;color:#DC2626">{{ $margenNegCount }} obras</span>
                </div>
                <div style="font-size:11px;color:#9CA3AF;margin-top:2px">
                    Facturaron pero están perdiendo
                </div>
            </div>
            <div style="max-height:280px;overflow:auto">
                @forelse($margenNegativo as $p)
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #F9FAFB;font-size:12px">
                    <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <span style="font-family:monospace;font-weight:600;color:#374151">{{ $p['codigo'] }}</span>
                        <span style="color:#9CA3AF"> · {{ \Illuminate\Support\Str::limit($p['nombre'], 24) }}</span>
                    </div>
                    <div style="text-align:right;white-space:nowrap">
                        <span style="font-weight:700;color:#DC2626">{{ $p['margen'] }}%</span>
                        <span style="color:#9CA3AF;font-size:10px"> · ${{ number_format($p['utilidad'], 0, ',', '.') }}</span>
                    </div>
                </div>
                @empty
                <div style="padding:1.5rem;text-align:center;color:#9CA3AF;font-size:12px">Ninguna obra con margen negativo 🎉</div>
                @endforelse
            </div>
            @if($margenNegCount > 8)
            <div style="padding:6px 14px;font-size:10px;color:#9CA3AF;text-align:center;background:#FEF2F2">
                Mostrando las 8 peores · ver todas en Estado de resultados
            </div>
            @endif
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
const meses = @json($meses);
// Gráficos usan datos sin corte
const datos1 = @json(array_values($datosGrafico1));
const datos2 = @json(array_values($datosGrafico2));
const anio1  = '{{ $anio1 }}';
const anio2  = '{{ $anio2 }}';

const ing1 = datos1.map(d => d.ingreso);
const ing2 = datos2.map(d => d.ingreso);
const cos1 = datos1.map(d => d.costo);
const cos2 = datos2.map(d => d.costo);
const apl1 = datos1.map(d => d.por_aplicar);
const apl2 = datos2.map(d => d.por_aplicar);

// Margen mes a mes independiente (no acumulado)
const mar1 = ing1.map((v,i) => v > 0 ? +((v - cos1[i] - apl1[i]) / v * 100).toFixed(1) : null);
const mar2 = ing2.map((v,i) => v > 0 ? +((v - cos2[i] - apl2[i]) / v * 100).toFixed(1) : null);

// Color del punto según el signo del margen
const VERDE = '#16A34A', ROJO = '#DC2626', GRIS = '#9CA3AF';
const colorPunto = v => (v === null || v === undefined) ? GRIS : (v >= 0 ? VERDE : ROJO);
const puntos1 = mar1.map(colorPunto);
const puntos2 = mar2.map(colorPunto);

const fmtM   = v => '$' + (v/1000000).toFixed(1) + 'M';
const fmtCOP = v => '$' + Math.round(v).toLocaleString('es-CO');

Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#9CA3AF';

// GRÁFICO PRINCIPAL — APILADO
const mainChart = new Chart(document.getElementById('grafico-principal'), {
    data: {
        labels: meses,
        datasets: [
            { type: 'bar', label: 'Ingreso ' + anio1,      data: ing1, backgroundColor: 'rgba(147,197,253,0.5)', stack: 'a1', borderRadius: 3 },
            { type: 'bar', label: 'Costo apl. ' + anio1,   data: cos1, backgroundColor: 'rgba(252,165,165,0.4)', stack: 'a1', borderRadius: 3 },
            { type: 'bar', label: 'Por aplicar ' + anio1,  data: apl1, backgroundColor: 'rgba(253,230,138,0.4)', stack: 'a1', borderRadius: 3 },
            { type: 'bar', label: 'Ingreso ' + anio2,      data: ing2, backgroundColor: 'rgba(27,63,110,0.85)',  stack: 'a2', borderRadius: 3 },
            { type: 'bar', label: 'Costo apl. ' + anio2,   data: cos2, backgroundColor: 'rgba(220,38,38,0.8)',  stack: 'a2', borderRadius: 3 },
            { type: 'bar', label: 'Por aplicar ' + anio2,  data: apl2, backgroundColor: 'rgba(217,119,6,0.8)',  stack: 'a2', borderRadius: 3 },
            { type: 'line', label: '% MC ' + anio1, data: mar1, borderColor: '#94A3B8', backgroundColor: 'transparent',
              tension: 0.4, pointRadius: 3, pointBackgroundColor: puntos1, pointBorderColor: puntos1, borderWidth: 2, yAxisID: 'y2', borderDash: [4,3] },
            { type: 'line', label: '% MC ' + anio2, data: mar2, borderColor: '#10B981', backgroundColor: 'transparent',
              tension: 0.4, pointRadius: 5, pointBackgroundColor: puntos2, pointBorderColor: puntos2, borderWidth: 2.5, yAxisID: 'y2' },
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        onClick: (e, elements) => { if (elements.length > 0) abrirMes(elements[0].index); },
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.dataset.yAxisID === 'y2'
                        ? ctx.dataset.label + ': ' + (ctx.raw ?? 'N/A') + '%'
                        : ctx.dataset.label + ': ' + fmtCOP(ctx.raw)
                }
            }
        },
        scales: {
            y:  { stacked: false, ticks: { callback: v => fmtM(v) }, grid: { color: '#F9FAFB' } },
            y2: { position: 'right', ticks: { callback: v => v + '%' }, grid: { drawOnChartArea: false } }
        }
    }
});

// ── Detalle del mes al hacer clic en una barra ──
function filaDato(label, valor, color) {
    return `<div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px solid #F3F4F6;font-size:13px">
        <span style="color:#6B7280">${label}</span>
        <span style="font-weight:600;color:${color || '#374151'}">${valor}</span>
    </div>`;
}

function tarjetaAnio(anio, ing, cos, apl, colorTitulo) {
    const util = ing - cos - apl;
    const pct  = ing > 0 ? util / ing * 100 : null;
    const mar  = pct === null ? '—' : pct.toFixed(1) + '%';
    const utilColor = util >= 0 ? VERDE : ROJO;
    const marColor  = pct === null ? GRIS : (pct >= 0 ? VERDE : ROJO);
    return `<div style="flex:1;background:#F9FAFB;border-radius:10px;padding:12px 14px">
        <div style="font-size:12px;font-weight:600;color:${colorTitulo};margin-bottom:6px">${anio}</div>
        ${filaDato('Ingreso', fmtCOP(ing), '#1B3F6E')}
        ${filaDato('Costo aplicado', fmtCOP(cos), '#DC2626')}
        ${filaDato('Por aplicar', fmtCOP(apl), '#D97706')}
        ${filaDato('Utilidad', fmtCOP(util), utilColor)}
        ${filaDato('Margen', mar, marColor)}
    </div>`;
}

function abrirMes(i) {
    document.getElementById('mes-titulo').textContent = 'Detalle de ' + meses[i];
    document.getElementById('mes-contenido').innerHTML =
        `<div style="display:flex;gap:10px">
            ${tarjetaAnio(anio1, ing1[i], cos1[i], apl1[i], '#94A3B8')}
            ${tarjetaAnio(anio2, ing2[i], cos2[i], apl2[i], '#1B3F6E')}
        </div>`;
    document.getElementById('modal-grafico').style.display = 'flex';
}

function cerrarGrafico(e) {
    if (e && e.target && e.target.id !== 'modal-grafico') return;
    document.getElementById('modal-grafico').style.display = 'none';
}

// COSTOS POR APLICAR
const aplChart = new Chart(document.getElementById('grafico-por-aplicar'), {
    type: 'line',
    data: {
        labels: meses,
        datasets: [
            { label: anio1, data: apl1, borderColor: '#FCD34D', backgroundColor: 'rgba(252,211,77,0.15)', tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2 },
            { label: anio2, data: apl2, borderColor: '#D97706', backgroundColor: 'rgba(217,119,6,0.15)',  tension: 0.4, fill: true, pointRadius: 3, borderWidth: 2 },
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + fmtCOP(ctx.raw) } }
        },
        scales: { y: { ticks: { callback: v => fmtM(v) }, grid: { color: '#F9FAFB' } } }
    }
});

// MARGEN MES A MES — puntos verde/rojo según signo
const marChart = new Chart(document.getElementById('grafico-margen'), {
    type: 'line',
    data: {
        labels: meses,
        datasets: [
            { label: anio1, data: mar1, borderColor: '#94A3B8', backgroundColor: 'rgba(148,163,184,0.1)', tension: 0.4, fill: true,
              pointRadius: 4, pointBackgroundColor: puntos1, pointBorderColor: puntos1, borderWidth: 2 },
            { label: anio2, data: mar2, borderColor: '#1B3F6E', backgroundColor: 'rgba(27,63,110,0.1)',   tension: 0.4, fill: true,
              pointRadius: 4, pointBackgroundColor: puntos2, pointBorderColor: puntos2, borderWidth: 2 },
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.raw ?? 'N/A') + '%' } }
        },
        scales: { y: { ticks: { callback: v => v + '%' }, grid: { color: '#F9FAFB' } } }
    }
});

// Toggle series con botones
const charts   = { aplChart, marChart };
const btnPref  = { aplChart: 'btn-apl-', marChart: 'btn-mar-' };
const actBg    = { aplChart: ['#FEF9C3','#D97706'],   marChart: ['#F1F5F9','#1B3F6E'] };
const actBord  = { aplChart: ['#FCD34D','#D97706'],   marChart: ['#94A3B8','#1B3F6E'] };
const actTxt   = { aplChart: ['#92400E','white'],      marChart: ['#475569','white'] };

function toggleSerie(chartName, idx) {
    const chart = charts[chartName];
    const meta  = chart.getDatasetMeta(idx);
    meta.hidden = !meta.hidden;
    chart.update();

    const btn = document.getElementById(btnPref[chartName] + idx);
    if (meta.hidden) {
        btn.style.background   = 'white';
        btn.style.color        = '#9CA3AF';
        btn.style.borderColor  = '#E5E7EB';
    } else {
        btn.style.background   = actBg[chartName][idx];
        btn.style.color        = actTxt[chartName][idx];
        btn.style.borderColor  = actBord[chartName][idx];
    }
}
</script>
@endsection