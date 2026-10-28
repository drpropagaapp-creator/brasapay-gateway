<script setup>
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import { Activity, FlaskConical, LoaderCircle, Power, Route } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/**
 * Desempenho real das adquirentes (tentativas registradas no pagamento) e configuração do
 * Smart Routing. O roteamento só reordena; a política de adquirentes do seller sempre vale.
 */
const props = defineProps({
    hours: { type: Number, default: 24 },
    stats: { type: [Object, Array], default: () => ({}) },
    gateway_names: { type: Object, default: () => ({}) },
    config: { type: Object, required: true },
    strategies: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
});

const page = usePage();
const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const METHOD_LABELS = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto' };
const STRATEGY_LABELS = {
    manual: 'Manual (ordem fixa)',
    performance: 'Performance',
    cost: 'Menor custo',
    balanced: 'Balanceado',
    custom: 'Personalizado',
};
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);
const name = (slug) => props.gateway_names?.[slug] || slug;

/* Classes de apresentação. */
const FIELD = 'mt-1.5 h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]';
const LABEL = 'block text-xs text-[var(--text-secondary)]';
const CHECK = 'h-4 w-4 shrink-0 rounded accent-[var(--lime-500)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]';
const THEAD = 'text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]';
const TH = 'whitespace-nowrap px-3 py-2.5 font-normal first:pl-0 last:pr-0';
const TD = 'px-3 py-2.5 tabular-nums first:pl-0 last:pr-0';

const form = reactive({
    enabled: !!props.config.enabled,
    strategy: props.config.strategy,
    methods: [...(props.config.methods || [])],
    min_sample: props.config.min_sample,
    window_hours: props.config.window_hours,
    weights: { ...props.config.weights },
});
const stepUp = ref(null);
const saving = ref(false);

function request(kind) {
    if (totpEnabled.value) {
        stepUp.value = kind;
        return;
    }
    kind === 'save' ? save({}) : null;
}

function save({ totp_code: totpCode } = {}) {
    saving.value = true;
    router.put('/plataforma/ops/adquirentes/roteamento', { ...form, totp_code: totpCode || undefined }, {
        preserveScroll: true,
        onSuccess: () => (stepUp.value = null),
        onFinish: () => (saving.value = false),
    });
}

function emergencyOff() {
    if (!window.confirm('Desligar o Smart Routing agora? Os pagamentos voltam para a ordem manual.')) return;
    router.post('/plataforma/ops/adquirentes/roteamento/desligar', {}, { preserveScroll: true });
}

function changeHours(h) {
    router.get('/plataforma/ops/adquirentes', { horas: h }, { preserveScroll: true, preserveState: true });
}

const sim = reactive({ method: 'pix', tenant_id: '', loading: false, result: null, error: '' });
async function simulate() {
    sim.loading = true;
    sim.error = '';
    try {
        const { data } = await axios.get('/plataforma/ops/adquirentes/simular', {
            params: { method: sim.method, tenant_id: sim.tenant_id || undefined },
        });
        sim.result = data;
    } catch (e) {
        sim.error = e?.response?.data?.message || 'Não foi possível simular.';
    } finally {
        sim.loading = false;
    }
}

const methodRows = computed(() =>
    Object.entries(props.stats || {}).map(([method, slugs]) => ({
        method,
        rows: Object.entries(slugs).map(([slug, m]) => ({ slug, ...m })).sort((a, b) => b.attempts - a.attempts),
    }))
);
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <PnPageHeader eyebrow="Pagamentos" title="Adquirentes e Smart Routing" :icon="Activity"
            description="Medido em cada tentativa real de cobrança. Recusa de cartão é do banco emissor e não conta como falha da adquirente. PIX criado só vira venda quando é pago — veja a coluna “PIX pago”.">
            <template #actions>
                <span :class="config.enabled ? 'pn-badge pn-badge--success' : 'pn-badge pn-badge--neutral'">{{ config.enabled ? 'Roteamento ligado' : 'Roteamento desligado' }}</span>
                <button v-if="config.enabled" type="button" class="pn-btn pn-btn--danger pn-btn--sm !w-auto" @click="emergencyOff">
                    <Power class="h-4 w-4" aria-hidden="true" /> Desligar roteamento
                </button>
            </template>
        </PnPageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-xs text-[var(--text-tertiary)]">Período</span>
            <nav class="relative pn-seg max-w-full overflow-x-auto" aria-label="Período">
                <button v-for="h in [1, 6, 24, 72, 168]" :key="h" type="button"
                    class="pn-tab pn-keep-shape focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                    :class="hours === h ? 'pn-tab--active' : ''" :aria-pressed="hours === h" @click="changeHours(h)">
                    {{ h < 24 ? `${h} h` : `${h / 24} d` }}
                </button>
            </nav>
        </div>

        <PnEmptyState v-if="!methodRows.length" title="Nenhuma tentativa registrada neste período" description="A medição começa a partir desta versão. Escolha um período maior ou volte depois das primeiras cobranças." :icon="Activity" />

        <PnPanel v-for="(group, gi) in methodRows" :key="group.method" :title="METHOD_LABELS[group.method] || group.method"
            :sub="`${group.rows.length} adquirente(s) · erro técnico acima de 10% em vermelho, timeout acima de 5% em laranja`" :index="gi">
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead :class="THEAD">
                        <tr>
                            <th :class="TH">Adquirente</th>
                            <th :class="TH">Tentativas</th>
                            <th :class="TH">Aprovação técnica</th>
                            <th :class="TH">Recusa</th>
                            <th :class="TH">Erro técnico</th>
                            <th :class="TH">Timeout</th>
                            <th :class="TH">Latência p50 / p95</th>
                            <th v-if="group.method === 'pix'" :class="TH">PIX pago</th>
                            <th v-if="group.method === 'pix'" :class="TH">Custo / R$100</th>
                        </tr>
                    </thead>
                    <tbody class="text-[var(--text-primary)]">
                        <tr v-for="r in group.rows" :key="r.slug" class="border-t border-[var(--border-strong)] transition-colors hover:bg-[var(--bg-muted)]/40">
                            <td class="px-3 py-2.5 font-medium first:pl-0">
                                {{ name(r.slug) }}
                                <span v-if="!r.sufficient_sample" class="pn-badge pn-badge--neutral pn-badge--nodot ml-1 !h-5 !px-2 !text-[10.5px] font-normal">amostra pequena</span>
                            </td>
                            <td :class="TD">{{ r.attempts }}</td>
                            <td :class="TD">{{ pct(r.success_rate) }}</td>
                            <td :class="TD">{{ pct(r.decline_rate) }}</td>
                            <td :class="[TD, r.technical_error_rate > 0.1 ? 'font-medium text-red-600 dark:text-red-400' : '']">{{ pct(r.technical_error_rate) }}</td>
                            <td :class="[TD, r.timeout_rate > 0.05 ? 'font-medium text-orange-600 dark:text-orange-400' : '']">{{ pct(r.timeout_rate) }}</td>
                            <td :class="TD" class="whitespace-nowrap">{{ r.latency_p50_ms ?? '—' }} / {{ r.latency_p95_ms ?? '—' }} ms</td>
                            <td v-if="group.method === 'pix'" :class="TD">{{ pct(r.paid_conversion) }}</td>
                            <td v-if="group.method === 'pix'" :class="TD" class="whitespace-nowrap">{{ r.cost_per_100_brl === null ? '—' : `R$ ${Number(r.cost_per_100_brl).toFixed(2)}` }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PnPanel>

        <PnPanel title="Smart Routing" sub="Só reordena as adquirentes que o seller já pode usar. Adquirente sem amostra suficiente fica na posição atual. Produto ou aplicação de API com adquirente fixada não é reordenado.">
            <template #right><Route class="h-5 w-5 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
            <div class="grid gap-5 md:grid-cols-2">
                <label class="flex items-center gap-3 rounded-2xl bg-[var(--bg-muted)]/60 p-3 text-sm text-[var(--text-primary)] md:col-span-2">
                    <input v-model="form.enabled" type="checkbox" :class="CHECK" /> Ativo para sellers que herdam a plataforma
                </label>
                <label class="text-sm">
                    <span :class="LABEL">Estratégia</span>
                    <select v-model="form.strategy" :class="FIELD">
                        <option v-for="s in strategies" :key="s" :value="s">{{ STRATEGY_LABELS[s] || s }}</option>
                    </select>
                </label>
                <fieldset class="text-sm">
                    <legend :class="LABEL">Métodos</legend>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        <label v-for="m in methods" :key="m" class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-3.5 text-[var(--text-primary)] has-[:checked]:border-[var(--lime-500)] has-[:checked]:bg-[var(--lime-100)] has-[:checked]:text-[var(--ink-900)]">
                            <input v-model="form.methods" type="checkbox" :value="m" :class="CHECK" /> {{ METHOD_LABELS[m] || m }}
                        </label>
                    </div>
                </fieldset>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <label><span :class="LABEL">Amostra mínima</span><input v-model.number="form.min_sample" type="number" min="10" :class="FIELD" /></label>
                    <label><span :class="LABEL">Janela (horas)</span><input v-model.number="form.window_hours" type="number" min="1" max="168" :class="FIELD" /></label>
                </div>
                <div v-if="form.strategy === 'custom'" class="grid grid-cols-2 gap-3 text-sm md:col-span-2 md:grid-cols-4">
                    <label v-for="(label, key) in { approval: 'Aprovação', stability: 'Estabilidade', latency: 'Latência', cost: 'Custo' }" :key="key">
                        <span :class="LABEL">{{ label }}</span>
                        <input v-model.number="form.weights[key]" type="number" min="0" max="1" step="0.05" :class="FIELD" />
                    </label>
                </div>
            </div>
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="saving" @click="request('save')">
                    <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" aria-hidden="true" /> Salvar roteamento
                </button>
                <span v-if="totpEnabled" class="text-xs text-[var(--text-tertiary)]">Pede o código 2FA antes de aplicar.</span>
            </div>
        </PnPanel>

        <PnPanel title="Simular ordem de cobrança" sub="Mostra a ordem que um pagamento usaria agora, já com a política do seller. Nada é cobrado.">
            <template #right><FlaskConical class="h-5 w-5 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
            <div class="flex flex-wrap items-end gap-3 text-sm">
                <label class="w-full sm:w-40">
                    <span :class="LABEL">Método</span>
                    <select v-model="sim.method" :class="FIELD">
                        <option v-for="m in methods" :key="m" :value="m">{{ METHOD_LABELS[m] || m }}</option>
                    </select>
                </label>
                <label class="w-full sm:w-56">
                    <span :class="LABEL">ID do seller (opcional)</span>
                    <input v-model="sim.tenant_id" type="number" min="1" placeholder="ID do seller (opcional)" :class="FIELD" />
                </label>
                <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" :disabled="sim.loading" @click="simulate">
                    <LoaderCircle v-if="sim.loading" class="h-4 w-4 animate-spin" aria-hidden="true" /> Simular
                </button>
            </div>
            <p v-if="sim.error" class="mt-3 rounded-2xl bg-[var(--status-error-bg)] px-3 py-2 text-sm text-[var(--status-error-fg)]" role="alert">{{ sim.error }}</p>
            <div v-if="sim.result" class="mt-4 rounded-2xl bg-[var(--bg-muted)]/60 p-4 text-sm">
                <p class="text-xs text-[var(--text-secondary)]">Ordem final</p>
                <p v-if="sim.result.final_order.length" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[var(--text-primary)]">
                    <template v-for="(slug, i) in sim.result.final_order" :key="slug + i">
                        <span v-if="i" class="text-[var(--text-tertiary)]" aria-hidden="true">→</span>
                        <span class="rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg-strong)] px-2.5 py-0.5 font-medium">{{ name(slug) }}</span>
                    </template>
                </p>
                <p v-else class="mt-1.5 text-[var(--text-primary)]">nenhuma adquirente disponível</p>
                <p class="mt-2 text-xs text-[var(--text-tertiary)]">Estratégia: {{ sim.result.strategy }} · Seller: {{ sim.result.tenant_mode }} · Política: {{ sim.result.policy?.mode || 'inherit' }}</p>
            </div>
        </PnPanel>

        <PlatformStepUpModal
            :open="stepUp !== null"
            title="Alterar Smart Routing"
            description="Informe o código 2FA para aplicar a nova configuração de roteamento."
            confirm-label="Aplicar"
            :loading="saving"
            :require-totp="totpEnabled"
            @close="stepUp = null"
            @confirm="save"
        />
    </div>
</template>
