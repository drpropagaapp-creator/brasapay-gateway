<script setup>
import { gsap } from 'gsap';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

/**
 * Painel da BrasaPay em funcionamento (dados fictícios): KPIs contam ao
 * aparecer, o gráfico se desenha e novas vendas entram ao vivo na lista,
 * somando no faturamento e disparando o aviso de venda aprovada.
 */
const root = ref(null);
const chartLine = ref(null);
const chartArea = ref(null);

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const int = new Intl.NumberFormat('pt-BR');

const NAV = [
    { label: 'Dashboard', d: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z', active: true },
    { label: 'Vendas', d: 'M6 6h15l-1.5 9h-12zM6 6 5 3H2M9 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM18 20.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2z' },
    { label: 'Produtos', d: 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8' },
    { label: 'Checkout', d: 'M2 6h20v12H2zM2 10h20' },
    { label: 'Afiliados', d: 'M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 20v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8' },
    { label: 'Área de membros', d: 'M2 4h20v13H2zM8 21h8M12 17v4M10 8.5v5l4-2.5z' },
    { label: 'Financeiro', d: 'M3 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 7l3-4h11M17 13.5h.01' },
];

const POOL = [
    { buyer: 'Mariana Prado', product: 'Método Escala Digital', amount: 497, method: 'PIX' },
    { buyer: 'Carlos Eduardo', product: 'Mentoria Turbo', amount: 197, method: 'PIX' },
    { buyer: 'Juliana Souza', product: 'Comunidade VIP', amount: 97, method: 'Cartão' },
    { buyer: 'Rafael Mendes', product: 'Curso Tráfego Pro', amount: 297, method: 'PIX' },
    { buyer: 'Beatriz Lima', product: 'E-book Receitas Fit', amount: 47.9, method: 'PIX' },
    { buyer: 'Lucas Ferreira', product: 'Método Escala Digital', amount: 497, method: 'Cartão' },
    { buyer: 'Ana Clara Rocha', product: 'Planner Digital', amount: 37, method: 'PIX' },
    { buyer: 'Pedro Henrique', product: 'Mentoria Turbo', amount: 197, method: 'PIX' },
];
const AGO = ['agora', 'há 1 min', 'há 3 min', 'há 6 min', 'há 9 min'];

let seq = 0;
let poolIdx = 0;
function nextSale() {
    const s = POOL[poolIdx % POOL.length];
    poolIdx += 1;
    seq += 1;
    return { ...s, id: seq, fresh: false };
}

const sales = ref(Array.from({ length: 5 }, () => nextSale()));
const toast = ref(null);

const target = { revenue: 18430.9, orders: 214, conversion: 68.2 };
const kpi = reactive({ revenue: 0, orders: 0, conversion: 0 });
const ticket = computed(() => (kpi.orders > 0 ? kpi.revenue / kpi.orders : 0));

// Gráfico: faturamento dos últimos 7 dias (milhares).
const SERIES = [8.2, 9.6, 8.9, 12.4, 11.1, 14.8, 18.4];
const VB = { w: 560, h: 180, px: 8, py: 16 };
const points = SERIES.map((v, i) => ({
    x: VB.px + (i * (VB.w - VB.px * 2)) / (SERIES.length - 1),
    y: VB.h - VB.py - ((v - 6) / 14) * (VB.h - VB.py * 2),
}));

function smoothPath(pts) {
    let d = `M${pts[0].x},${pts[0].y}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] || pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] || p2;
        const c1x = p1.x + (p2.x - p0.x) / 6;
        const c1y = p1.y + (p2.y - p0.y) / 6;
        const c2x = p2.x - (p3.x - p1.x) / 6;
        const c2y = p2.y - (p3.y - p1.y) / 6;
        d += ` C${c1x.toFixed(1)},${c1y.toFixed(1)} ${c2x.toFixed(1)},${c2y.toFixed(1)} ${p2.x},${p2.y}`;
    }
    return d;
}

const linePath = smoothPath(points);
const areaPath = `${linePath} L${points.at(-1).x},${VB.h} L${points[0].x},${VB.h} Z`;
const lastPoint = points.at(-1);

const days = computed(() => {
    const out = [];
    for (let i = 6; i >= 0; i--) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        out.push(i === 0 ? 'hoje' : d.toLocaleDateString('pt-BR', { weekday: 'short' }).replace('.', ''));
    }
    return out;
});

const todayLabel = new Date().toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).replace('.', '');

let timer = 0;
let io = null;
let started = false;
let toastTimer = 0;

function pushSale() {
    if (document.hidden) return;
    const s = { ...nextSale(), fresh: true };
    sales.value = [s, ...sales.value.slice(0, 4)];
    const row = sales.value[0];
    setTimeout(() => {
        row.fresh = false;
    }, 1400);
    target.revenue += s.amount;
    target.orders += 1;
    gsap.to(kpi, { revenue: target.revenue, orders: target.orders, duration: 0.9, ease: 'power2.out' });
    toast.value = s;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), 2200);
}

function startLive() {
    if (!started) {
        started = true;
        gsap.to(kpi, { ...target, duration: 1.8, ease: 'power3.out' });
        gsap.fromTo(chartLine.value, { strokeDashoffset: 1 }, { strokeDashoffset: 0, duration: 2, ease: 'power2.inOut' });
        gsap.fromTo(chartArea.value, { opacity: 0 }, { opacity: 1, duration: 1.4, delay: 0.6 });
    }
    clearInterval(timer);
    timer = setInterval(pushSale, 3400);
}

function stopLive() {
    clearInterval(timer);
}

onMounted(() => {
    io = new IntersectionObserver(([entry]) => (entry.isIntersecting ? startLive() : stopLive()), { threshold: 0.2 });
    io.observe(root.value);
});

onBeforeUnmount(() => {
    stopLive();
    clearTimeout(toastTimer);
    io?.disconnect();
});
</script>

<template>
    <div ref="root" class="db" aria-hidden="true">
        <div class="db-bar">
            <span class="db-dots"><i></i><i></i><i></i></span>
            <span class="db-url">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                brasapay.com.br/dashboard
            </span>
        </div>

        <div class="db-body">
            <aside class="db-side">
                <img src="/images/brasapay/logo-branca.png" alt="" class="db-logo" />
                <p class="db-side-label">MENU</p>
                <nav class="db-nav">
                    <span v-for="n in NAV" :key="n.label" class="db-nav-item" :class="{ 'is-active': n.active }">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path :d="n.d" /></svg>
                        {{ n.label }}
                    </span>
                </nav>
                <div class="db-side-card">
                    <span>Saldo disponível</span>
                    <strong>{{ brl.format(kpi.revenue * 0.62) }}</strong>
                    <em>Sacar via PIX</em>
                </div>
            </aside>

            <main class="db-main">
                <header class="db-head">
                    <div>
                        <h4>Olá, Marina</h4>
                        <p>Aqui está o resumo da sua operação hoje.</p>
                    </div>
                    <div class="db-head-actions">
                        <span class="db-chip">{{ todayLabel }}</span>
                        <span class="db-chip db-chip-green">Exportar</span>
                    </div>
                </header>

                <div class="db-kpis">
                    <div class="db-kpi">
                        <span>Faturamento hoje</span>
                        <strong>{{ brl.format(kpi.revenue) }}</strong>
                        <em class="up">+12,4% vs ontem</em>
                    </div>
                    <div class="db-kpi">
                        <span>Vendas aprovadas</span>
                        <strong>{{ int.format(Math.round(kpi.orders)) }}</strong>
                        <em class="up">+8,1% vs ontem</em>
                    </div>
                    <div class="db-kpi">
                        <span>Ticket médio</span>
                        <strong>{{ brl.format(ticket) }}</strong>
                        <em class="up">+3,6% vs ontem</em>
                    </div>
                    <div class="db-kpi">
                        <span>Conversão do checkout</span>
                        <strong>{{ kpi.conversion.toFixed(1).replace('.', ',') }}%</strong>
                        <em class="up">+2,2 p.p.</em>
                    </div>
                </div>

                <div class="db-grid">
                    <section class="db-card db-chart">
                        <div class="db-card-head">
                            <h5>Faturamento</h5>
                            <span class="db-chip">Últimos 7 dias</span>
                        </div>
                        <svg :viewBox="`0 0 ${VB.w} ${VB.h}`" class="db-svg" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="db-line" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0%" stop-color="#5cb536" />
                                    <stop offset="55%" stop-color="#0a986e" />
                                    <stop offset="100%" stop-color="#008894" />
                                </linearGradient>
                                <linearGradient id="db-area" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#0a986e" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#0a986e" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <line v-for="g in 4" :key="g" x1="0" :x2="VB.w" :y1="g * 40" :y2="g * 40" class="db-gridline" />
                            <path ref="chartArea" :d="areaPath" fill="url(#db-area)" />
                            <path ref="chartLine" :d="linePath" pathLength="1" class="db-line" stroke="url(#db-line)" />
                            <circle :cx="lastPoint.x" :cy="lastPoint.y" r="9" class="db-pulse" />
                            <circle :cx="lastPoint.x" :cy="lastPoint.y" r="4.5" class="db-dot" />
                        </svg>
                        <div class="db-days">
                            <span v-for="d in days" :key="d">{{ d }}</span>
                        </div>
                    </section>

                    <section class="db-card db-sales">
                        <div class="db-card-head">
                            <h5>Últimas vendas</h5>
                            <span class="db-live"><i></i> ao vivo</span>
                        </div>
                        <TransitionGroup tag="ul" name="db-row" class="db-list">
                            <li v-for="(s, i) in sales" :key="s.id" class="db-row" :class="{ 'is-fresh': s.fresh }">
                                <span class="db-avatar">{{ s.buyer.split(' ').map((p) => p[0]).slice(0, 2).join('') }}</span>
                                <span class="db-row-main">
                                    <strong>{{ s.buyer }}</strong>
                                    <span>{{ s.product }}</span>
                                </span>
                                <span class="db-row-side">
                                    <strong>{{ brl.format(s.amount) }}</strong>
                                    <span>{{ s.method }} · {{ AGO[i] }}</span>
                                </span>
                            </li>
                        </TransitionGroup>
                    </section>
                </div>
            </main>

            <Transition name="db-toast">
                <div v-if="toast" :key="toast.id" class="db-toast">
                    <img src="/images/brasapay/simbolo.png" alt="" />
                    <span>
                        <strong>Venda aprovada</strong>
                        <em>{{ brl.format(toast.amount) }} · {{ toast.method }}</em>
                    </span>
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.db {
    --p: #0b1c20;
    --p2: #0e2327;
    --ln: rgba(100, 175, 180, 0.14);
    --t: #eaf4f3;
    --m: #8aa3a3;
    position: relative;
    width: 100%;
    border-radius: 18px;
    overflow: hidden;
    background: linear-gradient(170deg, #0a181b, #061013);
    border: 1px solid rgba(100, 175, 180, 0.22);
    color: var(--t);
    font-size: 13px;
    text-align: left;
    box-shadow:
        0 -1px 0 0 rgba(10, 152, 110, 0.6),
        0 60px 140px -50px rgba(0, 0, 0, 0.95);
}
.db-bar {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.6rem 0.9rem;
    border-bottom: 1px solid var(--ln);
    background: rgba(6, 16, 19, 0.85);
}
.db-dots { display: flex; gap: 6px; }
.db-dots i { width: 10px; height: 10px; border-radius: 50%; background: rgba(140, 195, 200, 0.22); }
.db-url {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0 auto;
    padding: 0.3rem 1.1rem;
    border-radius: 999px;
    background: rgba(234, 244, 243, 0.05);
    color: var(--m);
    font-size: 0.75rem;
}
.db-url svg { width: 11px; height: 11px; }
.db-body {
    position: relative;
    display: grid;
    grid-template-columns: 196px minmax(0, 1fr);
    min-height: 470px;
}
.db-side {
    border-right: 1px solid var(--ln);
    padding: 1.1rem 0.8rem;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.db-logo { height: 26px; width: auto; align-self: flex-start; margin: 0 0 0.9rem 0.35rem; }
.db-side-label { margin: 0 0 0.2rem 0.5rem; font-size: 0.62rem; letter-spacing: 0.18em; color: var(--m); }
.db-nav { display: grid; gap: 0.15rem; }
.db-nav-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.6rem;
    border-radius: 10px;
    color: var(--m);
    font-size: 0.8rem;
}
.db-nav-item svg { width: 16px; height: 16px; flex: none; }
.db-nav-item.is-active {
    color: var(--t);
    background: linear-gradient(95deg, rgba(10, 152, 110, 0.28), rgba(0, 136, 148, 0.12));
    box-shadow: inset 2px 0 0 #0a986e;
}
.db-side-card {
    margin-top: auto;
    display: grid;
    gap: 0.15rem;
    padding: 0.8rem;
    border-radius: 12px;
    background: var(--p2);
    border: 1px solid var(--ln);
}
.db-side-card span { font-size: 0.66rem; color: var(--m); }
.db-side-card strong { font-size: 0.98rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.db-side-card em {
    margin-top: 0.4rem;
    font-style: normal;
    font-size: 0.7rem;
    font-weight: 600;
    text-align: center;
    padding: 0.35rem;
    border-radius: 8px;
    color: #f4fcfa;
    background: linear-gradient(95deg, #5cb536, #0a986e 60%, #008894);
}
.db-main { padding: 1.1rem 1.2rem 1.2rem; display: flex; flex-direction: column; gap: 0.9rem; min-width: 0; }
.db-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.db-head h4 { margin: 0; font-size: 1.12rem; font-weight: 600; }
.db-head p { margin: 0.15rem 0 0; font-size: 0.74rem; color: var(--m); }
.db-head-actions { display: flex; gap: 0.4rem; flex: none; }
.db-chip {
    font-size: 0.68rem;
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    border: 1px solid var(--ln);
    color: var(--m);
    white-space: nowrap;
}
.db-chip-green { color: #f4fcfa; border-color: transparent; background: linear-gradient(95deg, #0a986e, #008894); }
.db-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.6rem; }
.db-kpi {
    display: grid;
    gap: 0.2rem;
    padding: 0.8rem 0.85rem;
    border-radius: 14px;
    background: var(--p);
    border: 1px solid var(--ln);
}
.db-kpi span { font-size: 0.68rem; color: var(--m); }
.db-kpi strong { font-size: 1.1rem; font-weight: 600; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.db-kpi em { font-style: normal; font-size: 0.64rem; }
.db-kpi em.up { color: #6fd39a; }
.db-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); gap: 0.6rem; flex: 1; }
.db-card {
    padding: 0.85rem 0.9rem;
    border-radius: 14px;
    background: var(--p);
    border: 1px solid var(--ln);
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.db-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.6rem; }
.db-card-head h5 { margin: 0; font-size: 0.86rem; font-weight: 500; }
.db-svg { width: 100%; height: 170px; overflow: visible; }
.db-gridline { stroke: rgba(140, 195, 200, 0.08); stroke-width: 1; }
.db-line { fill: none; stroke-width: 3; stroke-linecap: round; stroke-dasharray: 1; stroke-dashoffset: 0; }
.db-dot { fill: #eaf4f3; stroke: #0a986e; stroke-width: 2.5; }
.db-pulse { fill: rgba(10, 152, 110, 0.35); transform-origin: center; transform-box: fill-box; animation: db-pulse 1.8s ease-out infinite; }
@keyframes db-pulse {
    from { transform: scale(0.6); opacity: 1; }
    to { transform: scale(2.2); opacity: 0; }
}
.db-days { display: flex; justify-content: space-between; margin-top: 0.4rem; font-size: 0.64rem; color: var(--m); text-transform: capitalize; }
.db-live { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.66rem; color: #6fd39a; }
.db-live i { width: 7px; height: 7px; border-radius: 50%; background: #2fbf71; animation: db-blink 1.2s ease-in-out infinite; }
@keyframes db-blink {
    50% { opacity: 0.25; }
}
.db-list { position: relative; list-style: none; margin: 0; padding: 0; display: grid; gap: 0.15rem; }
.db-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.45rem 0.4rem;
    border-radius: 10px;
    transition: background-color 1.2s ease;
}
.db-row.is-fresh { background: rgba(10, 152, 110, 0.18); transition: none; }
.db-avatar {
    flex: none;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-size: 0.62rem;
    font-weight: 600;
    background: linear-gradient(135deg, rgba(92, 181, 54, 0.35), rgba(0, 136, 148, 0.35));
}
.db-row-main, .db-row-side { display: grid; line-height: 1.25; min-width: 0; }
.db-row-main strong { font-size: 0.74rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-row-main span { font-size: 0.64rem; color: var(--m); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-row-side { margin-left: auto; text-align: right; flex: none; }
.db-row-side strong { font-size: 0.74rem; font-weight: 600; color: #7fe0b0; font-variant-numeric: tabular-nums; }
.db-row-side span { font-size: 0.62rem; color: var(--m); }
.db-row-enter-from { opacity: 0; transform: translateY(-16px); }
.db-row-enter-active { transition: opacity 0.5s ease, transform 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
.db-row-move { transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
.db-row-leave-active { display: none; }
.db-toast {
    position: absolute;
    top: 14px;
    right: 14px;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 0.9rem 0.6rem 0.6rem;
    border-radius: 14px;
    background: rgba(13, 31, 35, 0.95);
    border: 1px solid rgba(10, 152, 110, 0.55);
    box-shadow: 0 18px 40px -16px rgba(0, 0, 0, 0.8), 0 0 30px -8px rgba(10, 152, 110, 0.5);
    backdrop-filter: blur(10px);
    z-index: 3;
}
.db-toast img { width: 28px; height: 28px; object-fit: contain; }
.db-toast span { display: grid; line-height: 1.25; }
.db-toast strong { font-size: 0.78rem; font-weight: 600; }
.db-toast em { font-style: normal; font-size: 0.7rem; color: #7fe0b0; }
.db-toast-enter-from { opacity: 0; transform: translateY(-14px) scale(0.94); }
.db-toast-leave-to { opacity: 0; transform: translateX(20px); }
.db-toast-enter-active { transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
.db-toast-leave-active { transition: all 0.35s ease; }

@media (max-width: 860px) {
    .db-body { grid-template-columns: 1fr; min-height: 0; }
    .db-side { display: none; }
    .db-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .db-grid { grid-template-columns: 1fr; }
    .db-head-actions { display: none; }
}
</style>
