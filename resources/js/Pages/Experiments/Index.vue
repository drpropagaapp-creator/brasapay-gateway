<script setup>
import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import PnMeter from '@/components/paynext/PnMeter.vue';
import {
    FlaskConical, Play, Pause, CircleStop, Trophy, Plus, Activity, Users, ShieldCheck, Clock, Info, Split, CircleCheck,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Experiments — A/B da landing do checkout. Conversão = venda PAGA por sessão.
 * Vencedora só com amostra mínima e significância estatística.
 */
const props = defineProps({
    experiments: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    min: { type: Object, required: true },
});

const STATUS = { draft: 'Rascunho', running: 'Rodando', paused: 'Pausado', finished: 'Encerrado' };
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(2)}%`);
const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);

const form = reactive({ name: '', product_id: '', version_id: '', weight_b: 50 });
const productVersions = computed(() => props.versions.filter((v) => v.product_id === form.product_id));

function create() {
    router.post('/experimentos', form, { preserveScroll: true, onSuccess: () => Object.assign(form, { name: '', version_id: '' }) });
}
function act(e, action) {
    router.post(`/experimentos/${e.id}/acao`, { action }, { preserveScroll: true });
}
function apply(e) {
    if (!window.confirm('Aplicar a vencedora? Se for a B, ela vira a landing publicada do produto.')) return;
    router.post(`/experimentos/${e.id}/aplicar-vencedora`, {}, { preserveScroll: true });
}
function verdictText(v) {
    if (!v) return '';
    if (v.status === 'winner') return `Vencedora: ${v.winner}${v.lift !== null && v.lift !== undefined ? ` (${(v.lift * 100).toFixed(1)}% de diferença)` : ''} · p = ${v.p_value}`;
    return v.reason || 'Inconclusivo';
}

/* Apresentação */
const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR') : '');
const STATUS_BADGE = { draft: 'pn-badge--neutral', running: 'pn-badge--success', paused: 'pn-badge--warning', finished: 'pn-badge--info' };
const VERDICT_ICON = { winner: Trophy, collecting: Clock, inconclusive: Info };
const fieldCls = 'h-11 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)] disabled:opacity-50';

const stats = computed(() => ({
    total: props.experiments.length,
    running: props.experiments.filter((e) => e.status === 'running').length,
    winners: props.experiments.filter((e) => e.results?.verdict?.status === 'winner').length,
    applied: props.experiments.filter((e) => e.winner_key).length,
}));

function variants(e) {
    return Object.entries(e.results?.variants || {}).map(([key, r]) => ({ key, ...r }));
}
function totalPaid(e) {
    return variants(e).reduce((s, r) => s + (Number(r.paid) || 0), 0);
}
function progress(value, target) {
    return target ? Math.min(100, Math.round(((Number(value) || 0) / target) * 100)) : 0;
}
function isWinner(e, key) {
    return e.results?.verdict?.status === 'winner' && e.results.verdict.winner === key;
}
function period(e) {
    if (e.started_at && e.ended_at) return `${fmtDate(e.started_at)} a ${fmtDate(e.ended_at)}`;
    if (e.started_at) return `desde ${fmtDate(e.started_at)}`;
    return 'ainda não iniciado';
}
</script>

<template>
    <div class="space-y-6">
        <PnPageHeader
            eyebrow="Experimentos"
            title="Testes A/B"
            :icon="FlaskConical"
            description="Compare a landing publicada (A) com uma versão salva (B). Cada visitante vê sempre a mesma variante. Conta como conversão só a venda paga."
        />

        <!-- Indicadores -->
        <section v-if="experiments.length" class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumo dos experimentos">
            <PnKpiCard label="Experimentos" :value="stats.total" :icon="FlaskConical" caption="criados nesta conta" :index="0" />
            <PnKpiCard label="Rodando" :value="stats.running" :icon="Activity" caption="recebendo tráfego agora" :index="1" />
            <PnKpiCard label="Com vencedora" :value="stats.winners" :icon="Trophy" caption="diferença significativa" :index="2" />
            <PnKpiCard label="Aplicadas" :value="stats.applied" :icon="CircleCheck" caption="vencedora já em uso" :index="3" />
        </section>

        <!-- Critérios -->
        <section class="pn-glass p-5" aria-labelledby="criterios-titulo">
            <div class="flex flex-wrap items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[var(--border-strong)] text-[var(--text-secondary)]">
                    <ShieldCheck class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 id="criterios-titulo" class="text-sm font-medium text-[var(--text-primary)]">Quando uma variante vence</h2>
                    <p class="mt-1 text-xs text-[var(--text-tertiary)]">
                        Uma variante só vence com pelo menos {{ min.sessions }} sessões em cada lado, {{ min.conversions }} vendas pagas no total
                        e diferença estatisticamente significativa.
                    </p>
                </div>
            </div>
            <ul class="mt-4 grid gap-2 sm:grid-cols-3">
                <li class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                    <p class="text-[11px] text-[var(--text-tertiary)]">Amostra por variante</p>
                    <p class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ num(min.sessions) }} sessões</p>
                </li>
                <li class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                    <p class="text-[11px] text-[var(--text-tertiary)]">Vendas pagas no total</p>
                    <p class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ num(min.conversions) }} vendas</p>
                </li>
                <li class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                    <p class="text-[11px] text-[var(--text-tertiary)]">Significância</p>
                    <p class="mt-0.5 text-sm font-medium text-[var(--text-primary)]">p abaixo de 0,05</p>
                </li>
            </ul>
        </section>

        <!-- Novo experimento -->
        <PnPanel title="Novo experimento" sub="Escolha o produto e a versão salva da landing que vai disputar com a publicada." :index="1">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[1.4fr_1fr_1.2fr_auto]">
                <div class="flex flex-col gap-1.5">
                    <label for="exp-name" class="text-xs text-[var(--text-secondary)]">Nome</label>
                    <input id="exp-name" v-model="form.name" maxlength="120" placeholder="Ex.: Headline nova" :class="fieldCls" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="exp-product" class="text-xs text-[var(--text-secondary)]">Produto</label>
                    <select id="exp-product" v-model="form.product_id" :class="fieldCls" @change="form.version_id = ''">
                        <option value="">Produto…</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="exp-version" class="text-xs text-[var(--text-secondary)]">Versão B da landing</label>
                    <select id="exp-version" v-model="form.version_id" :class="fieldCls" :disabled="!form.product_id">
                        <option value="">Versão B da landing…</option>
                        <option v-for="v in productVersions" :key="v.id" :value="v.id">{{ new Date(v.created_at).toLocaleString('pt-BR') }} · {{ v.blocks }} blocos</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="exp-weight" class="text-xs text-[var(--text-secondary)]">Tráfego na B (%)</label>
                    <input id="exp-weight" v-model.number="form.weight_b" type="number" min="10" max="90" :class="[fieldCls, 'xl:w-28']" />
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3 text-[11px] text-[var(--text-tertiary)]" aria-hidden="true">
                <span class="shrink-0">A {{ 100 - (Number(form.weight_b) || 0) }}%</span>
                <span class="flex h-2 flex-1 overflow-hidden rounded-full bg-[var(--bg-muted)]">
                    <span class="h-full bg-[var(--ink-300)]" :style="{ width: `${Math.max(0, Math.min(100, 100 - (Number(form.weight_b) || 0)))}%` }" />
                    <span class="h-full bg-[var(--lime-300)]" :style="{ width: `${Math.max(0, Math.min(100, Number(form.weight_b) || 0))}%` }" />
                </span>
                <span class="shrink-0">B {{ Number(form.weight_b) || 0 }}%</span>
            </div>

            <p v-if="form.product_id && !productVersions.length" class="mt-3 rounded-2xl bg-[var(--status-warning-bg)] px-4 py-2.5 text-xs text-[var(--status-warning-fg)]">
                Este produto ainda não tem versões salvas da landing por blocos. Salve uma versão no editor do checkout.
            </p>
            <div class="mt-4 flex justify-end">
                <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="!form.name || !form.product_id || !form.version_id" @click="create">
                    <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Criar experimento
                </button>
            </div>
        </PnPanel>

        <!-- Lista -->
        <PnEmptyState
            v-if="!experiments.length"
            :icon="FlaskConical"
            title="Nenhum experimento ainda."
            description="Crie o primeiro acima. Ele começa como rascunho e só recebe tráfego depois que você clicar em Iniciar."
        />

        <PnPanel v-for="(e, idx) in experiments" :key="e.id" :title="e.name" :sub="`${e.product || 'Produto removido'} · ${period(e)}`" :index="idx + 2">
            <template #right>
                <span class="pn-badge shrink-0" :class="STATUS_BADGE[e.status] || 'pn-badge--neutral'">{{ STATUS[e.status] }}</span>
            </template>

            <!-- Ações e divisão de tráfego -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2 text-xs text-[var(--text-secondary)]">
                    <Split class="h-3.5 w-3.5 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" />
                    <span>A {{ e.weights.A }}% / B {{ e.weights.B }}%</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="e.status === 'draft' || e.status === 'paused'" type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" @click="act(e, 'start')">
                        <Play class="h-3.5 w-3.5" aria-hidden="true" /> Iniciar
                    </button>
                    <button v-if="e.status === 'running'" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="act(e, 'pause')">
                        <Pause class="h-3.5 w-3.5" aria-hidden="true" /> Pausar
                    </button>
                    <button v-if="e.status !== 'finished'" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="act(e, 'finish')">
                        <CircleStop class="h-3.5 w-3.5" aria-hidden="true" /> Encerrar
                    </button>
                    <button v-if="e.results.verdict.status === 'winner' && !e.winner_key" type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" @click="apply(e)">
                        <Trophy class="h-3.5 w-3.5" aria-hidden="true" /> Aplicar vencedora
                    </button>
                </div>
            </div>

            <!-- Variantes lado a lado -->
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div v-for="r in variants(e)" :key="r.key" class="rounded-[22px] border p-4"
                    :class="isWinner(e, r.key) ? 'border-[var(--lime-400)] bg-[var(--lime-100)]/40' : 'border-[var(--border-strong)] bg-[var(--bg-muted)]/40'">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="pn-avatar pn-avatar--sm shrink-0" :class="r.key === 'B' ? '!bg-[var(--lime-300)]' : '!bg-[var(--ink-100)] !text-[var(--ink-700)]'">{{ r.key }}</span>
                            <p class="truncate text-[13px] text-[var(--text-secondary)]">{{ r.label }}</p>
                        </div>
                        <span v-if="isWinner(e, r.key)" class="pn-badge pn-badge--brand shrink-0">Vencedora</span>
                    </div>
                    <p class="mt-3 text-2xl font-medium tabular-nums tracking-tight text-[var(--text-primary)]">{{ pct(r.conversion) }}</p>
                    <p class="text-xs text-[var(--text-tertiary)]">conversão · {{ num(r.paid) }} pagos de {{ num(r.sessions) }} sessões · {{ brl(r.revenue) }}</p>
                    <div class="mt-4">
                        <div class="mb-1.5 flex items-center justify-between text-[11px] text-[var(--text-tertiary)]">
                            <span class="inline-flex items-center gap-1"><Users class="h-3 w-3" aria-hidden="true" /> Amostra mínima</span>
                            <span class="tabular-nums">{{ num(Math.min(r.sessions, min.sessions)) }} de {{ num(min.sessions) }}</span>
                        </div>
                        <PnMeter :value="progress(r.sessions, min.sessions)" :show-marker="false" :aria-label="`Amostra mínima da variante ${r.key}`" />
                    </div>
                </div>
            </div>
            <p class="mt-2 text-[11px] text-[var(--text-tertiary)]">
                Vendas pagas no total: <span class="tabular-nums text-[var(--text-secondary)]">{{ num(totalPaid(e)) }} de {{ num(min.conversions) }}</span> necessárias.
            </p>

            <!-- Tabela completa -->
            <div class="relative mt-4 overflow-x-auto rounded-2xl border border-[var(--border-strong)]">
                <table class="w-full min-w-[620px] text-sm">
                    <thead class="bg-[var(--bg-muted)]/60 text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-medium">Variante</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Sessões</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Iniciaram</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Pedidos</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Pagos</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Conversão</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Ticket médio</th>
                            <th scope="col" class="px-4 py-2.5 text-right font-medium">Receita</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums text-[var(--text-primary)]">
                        <tr v-for="(r, key) in e.results.variants" :key="key" class="border-t border-[var(--border-strong)]">
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center gap-1.5">
                                    {{ r.label }}
                                    <Trophy v-if="isWinner(e, key)" class="h-3.5 w-3.5 text-[var(--lime-700)]" aria-label="Vencedora" />
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-right">{{ num(r.sessions) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ num(r.started) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ num(r.orders) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ num(r.paid) }}</td>
                            <td class="px-3 py-2.5 text-right font-medium">{{ pct(r.conversion) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ r.avg_ticket === null || r.avg_ticket === undefined ? '—' : brl(r.avg_ticket) }}</td>
                            <td class="px-4 py-2.5 text-right">{{ brl(r.revenue) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Veredito -->
            <div class="mt-4 flex items-start gap-2.5 rounded-2xl px-4 py-3 text-xs"
                :class="e.results.verdict.status === 'winner' ? 'bg-[var(--status-success-bg)] text-[var(--status-success-fg)]' : 'bg-[var(--bg-muted)]/60 text-[var(--text-secondary)]'">
                <component :is="VERDICT_ICON[e.results.verdict.status] || Info" class="mt-px h-4 w-4 shrink-0" aria-hidden="true" />
                <div class="min-w-0">
                    <p>{{ verdictText(e.results.verdict) }}</p>
                    <p v-if="e.winner_key" class="mt-1 opacity-80">Aplicada: variante {{ e.winner_key }}.</p>
                </div>
            </div>
        </PnPanel>
    </div>
</template>
