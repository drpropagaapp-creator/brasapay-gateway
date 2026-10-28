<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import {
    Contact, Search, LayoutGrid, Columns3, X, Copy, MessageCircle, ArrowUpRight, Users, Wallet, Repeat, Gem, UserCheck, Plus,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa CRM — clientes montados a partir dos próprios pedidos do seller.
 * Assinatura visual: a trilha do cliente (Lead → Primeira compra → Recorrente → Alto valor, com o desvio
 * "Em risco") e, em cada contato, a trilha de pontos com o histórico de pedidos.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    segments: { type: Object, required: true },
    stages: { type: Object, required: true },
    summary: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    contacts: { type: Object, default: null },
    pipeline: { type: Object, default: null },
});

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: Number(v) >= 10000 ? 0 : 2 }).format(Number(v) || 0);
const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1).replace('.', ',')}%`);
function ago(iso) {
    if (!iso) return 'sem compra paga';
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
    if (days <= 0) return 'comprou hoje';
    if (days === 1) return 'comprou ontem';
    if (days < 30) return `comprou há ${days} dias`;
    const months = Math.floor(days / 30);
    return months < 12 ? `comprou há ${months} ${months === 1 ? 'mês' : 'meses'}` : `comprou há ${Math.floor(months / 12)}+ ano(s)`;
}

/* Estágios: a trilha principal e o desvio. */
const MAIN = ['lead', 'novo', 'recorrente', 'alto_valor'];
const STAGE_STYLE = {
    lead: { chip: 'bg-[var(--sky-100)] text-[var(--sky-600)]', dot: 'bg-[var(--sky-400)]', bar: 'bg-[var(--sky-300)]' },
    novo: { chip: 'bg-[var(--lime-100)] text-[var(--lime-700)]', dot: 'bg-[var(--lime-400)]', bar: 'bg-[var(--lime-200)]' },
    recorrente: { chip: 'bg-[var(--lime-300)] text-[var(--ink-900)]', dot: 'bg-[var(--lime-500)]', bar: 'bg-[var(--lime-300)]' },
    alto_valor: { chip: 'bg-[var(--ink-900)] text-[var(--lime-300)] ring-1 ring-[var(--lime-300)]/50', dot: 'bg-[var(--ink-900)]', bar: 'bg-[var(--ink-800)]' },
    em_risco: { chip: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200', dot: 'bg-amber-500', bar: 'bg-amber-300' },
};
const maxStage = computed(() => Math.max(1, ...Object.values(props.summary.stages).map((s) => s.count)));

/* Pontos da trilha de compras. */
const DOT = {
    completed: 'bg-[var(--lime-400)] ring-1 ring-[var(--lime-600)]/40',
    pending: 'bg-transparent ring-[1.5px] ring-amber-500',
    refunded: 'bg-red-500', disputed: 'bg-red-600', refund_pending: 'bg-red-300',
    cancelled: 'bg-[var(--ink-200)]', failed: 'bg-[var(--ink-300)]',
};
const STATUS = { completed: 'Pago', pending: 'Pendente', refunded: 'Reembolsado', disputed: 'Em MED', cancelled: 'Cancelado', failed: 'Falhou', refund_pending: 'Reembolso em andamento' };

/* Contatos sem e-mail real (integrações). */
function title(c) {
    return c.placeholder ? 'Sem e-mail' : c.email;
}
function initials(c) {
    if (c.placeholder?.startsWith('Telegram')) return 'TG';
    if (c.placeholder === 'PixGo') return 'PX';
    const local = (c.email || '?').split('@')[0].replace(/[^a-zA-Z]/g, ' ').trim().split(/\s+/);
    return ((local[0]?.[0] || '?') + (local[1]?.[0] || local[0]?.[1] || '')).toUpperCase();
}

/* Filtros. */
const q = ref(props.filters.q || '');
function go(patch) {
    router.get('/crm', {
        view: props.filters.view, product: props.filters.product || undefined, q: q.value || undefined,
        stage: props.filters.stage || undefined, segment: props.filters.segment !== 'all' ? props.filters.segment : undefined,
        ...patch,
    }, { preserveScroll: true, preserveState: true });
}
function pickStage(s) {
    go({ stage: props.filters.stage === s ? undefined : s, segment: undefined, page: undefined, view: 'lista' });
}
const LISTS = ['recent', 'unpaid', 'abandoned', 'past_due'];

/* Painel lateral. */
const open = ref(null);
const detail = ref(null);
const loading = ref(false);
const tagDraft = ref('');
const saving = ref(false);
const saved = ref(false);
async function show(c) {
    open.value = c;
    detail.value = null;
    loading.value = true;
    try {
        detail.value = (await window.axios.get(`/crm/contato/${c.key}`, { headers: { Accept: 'application/json' } })).data.contact;
    } finally {
        loading.value = false;
    }
}
function close() {
    open.value = null;
}
function addTag() {
    const t = tagDraft.value.trim().slice(0, 30);
    if (t && !detail.value.tags.includes(t) && detail.value.tags.length < 10) detail.value.tags.push(t);
    tagDraft.value = '';
}
async function saveMeta() {
    saving.value = true;
    try {
        const r = await window.axios.put(`/crm/contato/${detail.value.key}`, { tags: detail.value.tags, notes: detail.value.notes }, { headers: { Accept: 'application/json' } });
        if (open.value) open.value.tags = r.data.tags;
        saved.value = true;
        setTimeout(() => (saved.value = false), 2000);
    } finally {
        saving.value = false;
    }
}
const whatsapp = computed(() => {
    const d = (detail.value?.phone || '').replace(/\D/g, '');
    return d.length >= 10 ? `https://wa.me/${d.length <= 11 ? '55' + d : d}` : null;
});
watch(open, (v) => document.body.classList.toggle('overflow-hidden', !!v));
function copyEmail() {
    navigator.clipboard?.writeText(open.value.email);
}
const onKey = (e) => e.key === 'Escape' && close();
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <div class="space-y-6">
        <!-- Cabeçalho -->
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight text-[var(--text-primary)]"><Contact class="h-6 w-6" /> Clientes</h1>
                <p class="mt-1 max-w-xl text-sm text-[var(--text-secondary)]">Quem comprou ou tentou comprar seus produtos, em que ponto da jornada está e quanto já pagou.</p>
            </div>
            <div class="inline-flex rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] p-1" role="tablist" aria-label="Visualização">
                <button v-for="v in [['lista', 'Lista', LayoutGrid], ['funil', 'Funil', Columns3]]" :key="v[0]" type="button" role="tab" :aria-selected="filters.view === v[0]"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full px-4 text-[13px] transition"
                    :class="filters.view === v[0] ? 'bg-[var(--text-primary)] text-[var(--text-inverse)]' : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
                    @click="go({ view: v[0], page: undefined })">
                    <component :is="v[2]" class="h-3.5 w-3.5" /> {{ v[1] }}
                </button>
            </div>
        </div>

        <!-- Indicadores -->
        <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <div v-for="k in [
                ['Contatos', num(summary.contacts), 'pagaram ou geraram pagamento', Users],
                ['Compradores', num(summary.buyers), `${num(summary.contacts - summary.buyers)} ainda sem compra paga`, UserCheck],
                ['Receita confirmada', brl(summary.revenue), 'soma do que os clientes pagaram', Wallet],
                ['Valor por cliente', brl(summary.avg_ltv), 'média paga por comprador', Gem],
                ['Recompra', pct(summary.repeat_rate), 'compradores com 2+ compras', Repeat],
            ]" :key="k[0]" class="pn-glass flex flex-col gap-3 p-5">
                <div class="flex items-center justify-between text-[13px] text-[var(--text-secondary)]">
                    {{ k[0] }}
                    <span class="flex h-8 w-8 items-center justify-center rounded-full border border-[var(--border-strong)]"><component :is="k[3]" class="h-4 w-4" /></span>
                </div>
                <p class="text-2xl font-medium tabular-nums tracking-tight text-[var(--text-primary)]">{{ k[1] }}</p>
                <p class="text-xs text-[var(--text-tertiary)]">{{ k[2] }}</p>
            </div>
        </section>

        <!-- Trilha do cliente -->
        <section class="pn-glass p-5" aria-label="Trilha do cliente">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-medium text-[var(--text-primary)]">Trilha do cliente</h2>
                <p class="text-xs text-[var(--text-tertiary)]">Clique num estágio para ver só aquelas pessoas.</p>
            </div>
            <div class="grid gap-3 lg:grid-cols-[1fr_auto_220px]">
                <ol class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <li v-for="(s, i) in MAIN" :key="s" class="relative">
                        <button type="button" class="group w-full rounded-[22px] border p-4 text-left transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                            :class="filters.stage === s ? 'border-[var(--ink-900)] bg-[var(--glass-bg-strong)]' : 'border-transparent bg-[var(--bg-muted)]/60 hover:bg-[var(--glass-bg-strong)]'"
                            :aria-pressed="filters.stage === s" @click="pickStage(s)">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium" :class="STAGE_STYLE[s].chip">{{ stages[s] }}</span>
                            <p class="mt-3 text-2xl font-medium tabular-nums text-[var(--text-primary)]">{{ num(summary.stages[s].count) }}</p>
                            <p class="text-xs text-[var(--text-tertiary)]">{{ s === 'lead' ? 'ainda sem pagamento' : brl(summary.stages[s].revenue) + ' pagos' }}</p>
                            <span class="mt-3 block h-1.5 overflow-hidden rounded-full bg-[var(--bg-muted)]">
                                <span class="block h-full rounded-full transition-all" :class="STAGE_STYLE[s].bar" :style="{ width: `${Math.max(4, (summary.stages[s].count / maxStage) * 100)}%` }" />
                            </span>
                        </button>
                        <span v-if="i < MAIN.length - 1" class="pointer-events-none absolute -right-2.5 top-1/2 z-10 hidden h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full bg-[var(--bg-canvas,#fff)] text-[var(--text-tertiary)] sm:flex" aria-hidden="true">›</span>
                    </li>
                </ol>
                <div class="hidden w-px self-stretch border-l border-dashed border-[var(--border-strong)] lg:block" aria-hidden="true" />
                <button type="button" class="rounded-[22px] border p-4 text-left transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                    :class="filters.stage === 'em_risco' ? 'border-amber-500 bg-amber-50 dark:bg-amber-950/30' : 'border-dashed border-amber-300 hover:bg-amber-50/60 dark:border-amber-800 dark:hover:bg-amber-950/20'"
                    :aria-pressed="filters.stage === 'em_risco'" @click="pickStage('em_risco')">
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium" :class="STAGE_STYLE.em_risco.chip">{{ stages.em_risco }}</span>
                    <p class="mt-3 text-2xl font-medium tabular-nums text-[var(--text-primary)]">{{ num(summary.stages.em_risco.count) }}</p>
                    <p class="text-xs text-[var(--text-tertiary)]">sem comprar há 90+ dias · {{ brl(summary.stages.em_risco.revenue) }} já pagos</p>
                </button>
            </div>
        </section>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-2">
            <form class="relative w-full sm:w-72" @submit.prevent="go({ page: undefined })">
                <Search class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-tertiary)]" />
                <input v-model="q" type="search" placeholder="Buscar por e-mail" aria-label="Buscar por e-mail"
                    class="h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] pl-10 pr-4 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)]" />
            </form>
            <select aria-label="Produto" class="h-10 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)]"
                :value="filters.product || ''" @change="go({ product: $event.target.value || undefined, page: undefined })">
                <option value="">Todos os produtos</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <template v-if="filters.view === 'lista'">
                <span class="mx-1 hidden h-5 w-px bg-[var(--border-strong)] sm:block" aria-hidden="true" />
                <button v-for="key in LISTS" :key="key" type="button" class="pn-chip h-9 text-[12.5px]" :class="filters.segment === key && !filters.stage ? 'pn-chip--active' : ''"
                    @click="go({ segment: filters.segment === key ? undefined : key, stage: undefined, page: undefined })">{{ segments[key] }}</button>
                <button v-if="filters.stage || filters.segment !== 'all' || filters.q" type="button" class="text-xs text-[var(--text-secondary)] underline" @click="q = ''; go({ stage: undefined, segment: undefined, q: undefined, page: undefined })">Limpar filtros</button>
            </template>
        </div>

        <!-- Lista -->
        <template v-if="filters.view === 'lista' && contacts">
            <p class="text-xs text-[var(--text-tertiary)]">
                {{ num(contacts.total) }} contato(s)<template v-if="filters.stage"> em <b class="font-medium text-[var(--text-secondary)]">{{ stages[filters.stage] }}</b></template><template v-else-if="filters.segment !== 'all'"> em <b class="font-medium text-[var(--text-secondary)]">{{ segments[filters.segment] }}</b></template>
            </p>
            <div v-if="!contacts.data.length" class="pn-glass flex flex-col items-center gap-2 p-10 text-center">
                <Users class="h-8 w-8 text-[var(--text-tertiary)]" />
                <p class="text-sm text-[var(--text-primary)]">Ninguém aqui ainda.</p>
                <p class="text-xs text-[var(--text-tertiary)]">Troque o estágio ou o filtro acima para ver outros clientes.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button v-for="c in contacts.data" :key="c.email" type="button"
                    class="pn-glass group flex flex-col gap-4 p-5 text-left transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)] motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                    @click="show(c)">
                    <div class="flex items-start gap-3">
                        <span class="pn-avatar pn-avatar--md shrink-0" :class="c.placeholder ? '!bg-[var(--ink-100)] !text-[var(--ink-600)]' : ''">{{ initials(c) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-[var(--text-primary)]">{{ title(c) }}</p>
                            <p class="truncate text-xs text-[var(--text-tertiary)]">{{ c.placeholder || c.phone || 'sem telefone' }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-medium" :class="STAGE_STYLE[c.stage]?.chip">{{ stages[c.stage] || 'Lead' }}</span>
                    </div>
                    <div class="flex items-end justify-between gap-2">
                        <div>
                            <p class="text-lg font-medium tabular-nums text-[var(--text-primary)]">{{ brl(c.total_paid) }}</p>
                            <p class="text-xs text-[var(--text-tertiary)]">{{ c.paid_orders }} {{ c.paid_orders === 1 ? 'compra paga' : 'compras pagas' }} · {{ ago(c.last_paid_at) }}</p>
                        </div>
                        <ArrowUpRight class="h-4 w-4 text-[var(--text-tertiary)] opacity-0 transition group-hover:opacity-100" />
                    </div>
                    <div class="flex items-center gap-[5px]" :aria-label="`Últimos ${c.trail.length} pedidos`">
                        <span v-for="(o, i) in c.trail" :key="i" class="h-2 w-2 rounded-full" :class="DOT[o.s] || DOT.failed" :title="`${STATUS[o.s] || o.s} · ${brl(o.v)} · ${o.d}`" />
                        <span v-if="c.pending_orders" class="ml-auto text-[11px] text-amber-700 dark:text-amber-300">{{ c.pending_orders }} pendente(s)</span>
                        <span v-if="c.refunds" class="text-[11px] text-red-600" :class="c.pending_orders ? '' : 'ml-auto'">{{ c.refunds }} reemb./MED</span>
                    </div>
                    <div v-if="c.tags.length" class="flex flex-wrap gap-1">
                        <span v-for="t in c.tags" :key="t" class="rounded-full border border-[var(--border-strong)] px-2 py-0.5 text-[11px] text-[var(--text-secondary)]">{{ t }}</span>
                    </div>
                </button>
            </div>
            <nav v-if="contacts.total > contacts.data.length" class="flex flex-wrap justify-center gap-1" aria-label="Páginas">
                <Link v-for="(l, i) in contacts.links" :key="i" :href="l.url || ''" preserve-scroll preserve-state
                    class="rounded-full px-3.5 py-1.5 text-sm"
                    :class="[l.active ? 'bg-[var(--text-primary)] text-[var(--text-inverse)]' : 'text-[var(--text-secondary)] hover:bg-[var(--glass-bg)]', !l.url ? 'pointer-events-none opacity-40' : '']" v-html="l.label" />
            </nav>
        </template>

        <!-- Funil -->
        <div v-if="filters.view === 'funil' && pipeline" class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
            <div class="grid min-w-[960px] grid-cols-5 gap-3">
                <section v-for="s in [...MAIN, 'em_risco']" :key="s" class="flex flex-col rounded-[28px] p-3"
                    :class="s === 'em_risco' ? 'border border-dashed border-amber-300 dark:border-amber-800' : 'bg-[var(--bg-muted)]/50'">
                    <header class="mb-3 px-2 pt-1">
                        <div class="flex items-center justify-between">
                            <span class="rounded-full px-2.5 py-0.5 text-[11px] font-medium" :class="STAGE_STYLE[s].chip">{{ stages[s] }}</span>
                            <span class="text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ num(summary.stages[s].count) }}</span>
                        </div>
                        <p class="mt-1 text-[11px] text-[var(--text-tertiary)]">{{ s === 'lead' ? 'ainda sem pagamento' : brl(summary.stages[s].revenue) + ' pagos' }}</p>
                    </header>
                    <div class="flex flex-1 flex-col gap-2">
                        <button v-for="c in pipeline[s]" :key="c.email" type="button"
                            class="flex flex-col gap-2 rounded-[20px] border border-[var(--border-strong)] bg-[var(--glass-bg-strong)] p-3 text-left transition hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" @click="show(c)">
                            <div class="flex items-center gap-2">
                                <span class="pn-avatar pn-avatar--sm" :class="c.placeholder ? '!bg-[var(--ink-100)] !text-[var(--ink-600)]' : ''">{{ initials(c) }}</span>
                                <p class="min-w-0 flex-1 truncate text-[13px] text-[var(--text-primary)]">{{ c.placeholder || c.email }}</p>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium tabular-nums">{{ brl(c.total_paid) }}</span>
                                <span class="flex gap-[3px]"><span v-for="(o, i) in c.trail.slice(-8)" :key="i" class="h-1.5 w-1.5 rounded-full" :class="DOT[o.s] || DOT.failed" /></span>
                            </div>
                        </button>
                        <p v-if="!pipeline[s].length" class="px-2 py-6 text-center text-xs text-[var(--text-tertiary)]">Ninguém neste estágio.</p>
                        <button v-if="summary.stages[s].count > pipeline[s].length" type="button" class="mt-1 rounded-full py-2 text-xs text-[var(--text-secondary)] hover:bg-[var(--glass-bg)]" @click="pickStage(s)">
                            Ver todos os {{ num(summary.stages[s].count) }}
                        </button>
                    </div>
                </section>
            </div>
        </div>

        <!-- Painel lateral do contato -->
        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-50 flex justify-end bg-black/30 backdrop-blur-[2px]" @click.self="close">
                <aside class="flex h-full w-full max-w-md flex-col overflow-y-auto bg-[var(--bg-canvas,#fff)] shadow-2xl motion-safe:animate-[pn-drawer_260ms_cubic-bezier(.16,1,.3,1)] dark:bg-zinc-900"
                    role="dialog" aria-modal="true" :aria-label="`Contato ${title(open)}`">
                    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-[var(--border-strong)] bg-inherit px-5 py-3">
                        <span class="rounded-full px-2.5 py-0.5 text-[11px] font-medium" :class="STAGE_STYLE[open.stage]?.chip">{{ stages[open.stage] || 'Lead' }}</span>
                        <div class="flex items-center gap-1">
                            <Link :href="`/crm/contato/${open.key}`" class="rounded-full px-3 py-1.5 text-xs text-[var(--text-secondary)] hover:bg-[var(--bg-muted)]">Página completa</Link>
                            <button type="button" class="rounded-full p-2 hover:bg-[var(--bg-muted)]" aria-label="Fechar" @click="close"><X class="h-4 w-4" /></button>
                        </div>
                    </div>

                    <div class="space-y-6 p-5">
                        <div class="flex items-center gap-3">
                            <span class="pn-avatar pn-avatar--lg" :class="open.placeholder ? '!bg-[var(--ink-100)] !text-[var(--ink-600)]' : ''">{{ initials(open) }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-base font-medium text-[var(--text-primary)]">{{ detail?.name || title(open) }}</p>
                                <p class="truncate text-xs text-[var(--text-tertiary)]">{{ open.placeholder ? `Comprou por ${open.placeholder}, sem e-mail informado` : open.email }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="!open.placeholder" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="copyEmail"><Copy class="h-3.5 w-3.5" /> Copiar e-mail</button>
                            <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto"><MessageCircle class="h-3.5 w-3.5" /> WhatsApp</a>
                        </div>

                        <p v-if="loading" class="text-sm text-[var(--text-tertiary)]">Carregando histórico…</p>
                        <template v-else-if="detail">
                            <dl class="grid grid-cols-2 gap-2">
                                <div v-for="m in [['Total pago', brl(detail.total_paid)], ['Compras pagas', detail.paid_orders], ['Ticket médio', brl(detail.avg_ticket)], ['Cliente desde', detail.first_seen_at ? new Date(detail.first_seen_at).toLocaleDateString('pt-BR') : '—']]" :key="m[0]"
                                    class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                                    <dt class="text-[11px] text-[var(--text-tertiary)]">{{ m[0] }}</dt>
                                    <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ m[1] }}</dd>
                                </div>
                            </dl>
                            <p v-if="detail.recovery_opted_out" class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Descadastrado dos e-mails de recuperação.</p>

                            <div v-if="detail.products?.length">
                                <h3 class="mb-2 text-xs font-medium text-[var(--text-secondary)]">Produtos comprados</h3>
                                <div class="flex flex-wrap gap-1.5"><span v-for="p in detail.products" :key="p" class="rounded-full bg-[var(--lime-100)] px-2.5 py-1 text-xs text-[var(--lime-700)]">{{ p }}</span></div>
                            </div>
                            <div v-if="detail.subscriptions?.length">
                                <h3 class="mb-2 text-xs font-medium text-[var(--text-secondary)]">Assinaturas</h3>
                                <p v-for="(s, i) in detail.subscriptions" :key="i" class="text-sm">{{ s.product }} · <span :class="s.status === 'past_due' ? 'text-amber-700' : 'text-[var(--text-secondary)]'">{{ s.status }}</span><template v-if="s.current_period_end"> · até {{ new Date(s.current_period_end).toLocaleDateString('pt-BR') }}</template></p>
                            </div>

                            <div>
                                <h3 class="mb-3 text-xs font-medium text-[var(--text-secondary)]">Histórico</h3>
                                <ol class="relative ml-1 space-y-4 border-l border-[var(--border-strong)] pl-5">
                                    <li v-for="o in detail.orders" :key="o.id" class="relative">
                                        <span class="absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full" :class="DOT[o.status] || DOT.failed" />
                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="text-sm text-[var(--text-primary)]">{{ o.product || 'Produto removido' }}</p>
                                            <p class="text-sm font-medium tabular-nums">{{ brl(o.amount) }}</p>
                                        </div>
                                        <p class="text-[11px] text-[var(--text-tertiary)]">{{ STATUS[o.status] || o.status }} · {{ o.payment_method || '—' }} · {{ new Date(o.created_at).toLocaleString('pt-BR') }}<template v-if="o.utm_source"> · via {{ o.utm_source }}</template></p>
                                    </li>
                                </ol>
                            </div>

                            <div class="space-y-3 rounded-2xl border border-[var(--border-strong)] p-4">
                                <h3 class="text-xs font-medium text-[var(--text-secondary)]">Tags e notas</h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span v-for="(t, i) in detail.tags" :key="t" class="inline-flex items-center gap-1 rounded-full bg-[var(--bg-muted)] px-2.5 py-1 text-xs">
                                        {{ t }} <button type="button" :aria-label="`Remover ${t}`" @click="detail.tags.splice(i, 1)"><X class="h-3 w-3" /></button>
                                    </span>
                                    <form v-if="detail.tags.length < 10" class="inline-flex items-center gap-1" @submit.prevent="addTag">
                                        <input v-model="tagDraft" maxlength="30" placeholder="Nova tag" class="h-7 w-28 rounded-full border border-[var(--border-strong)] bg-transparent px-3 text-xs" />
                                        <button type="submit" class="rounded-full p-1 hover:bg-[var(--bg-muted)]" aria-label="Adicionar tag"><Plus class="h-3.5 w-3.5" /></button>
                                    </form>
                                </div>
                                <textarea v-model="detail.notes" rows="3" maxlength="5000" placeholder="Anotações sobre este cliente (só sua equipe vê)" class="w-full rounded-2xl border border-[var(--border-strong)] bg-transparent px-3 py-2 text-sm" />
                                <div class="flex items-center gap-3">
                                    <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="saving" @click="saveMeta">Salvar</button>
                                    <span v-if="saved" class="text-xs text-[var(--text-tertiary)]">Salvo.</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </aside>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
@keyframes pn-drawer {
    from { transform: translateX(24px); opacity: 0; }
    to { transform: none; opacity: 1; }
}
</style>
