<script setup>
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { Activity, Power, Route } from 'lucide-vue-next';

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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Pagamentos</p>
                <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    <Activity class="h-6 w-6" /> Adquirentes e Smart Routing
                </h1>
                <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Medido em cada tentativa real de cobrança. Recusa de cartão é do banco emissor e não conta como falha da
                    adquirente. PIX criado só vira venda quando é pago — veja a coluna "PIX pago".
                </p>
            </div>
            <Button v-if="config.enabled" type="button" variant="destructive" class="gap-2" @click="emergencyOff">
                <Power class="h-4 w-4" /> Desligar roteamento
            </Button>
        </div>

        <div class="flex flex-wrap gap-2">
            <nav class="pn-seg max-w-full overflow-x-auto" aria-label="Período">
                <button v-for="h in [1, 6, 24, 72, 168]" :key="h" type="button" class="pn-tab pn-keep-shape" :class="hours === h ? 'pn-tab--active' : ''" @click="changeHours(h)">
                    {{ h < 24 ? `${h} h` : `${h / 24} d` }}
                </button>
            </nav>
        </div>

        <p v-if="!methodRows.length" class="rounded-2xl border border-dashed border-zinc-300 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhuma tentativa registrada neste período. A medição começa a partir desta versão.
        </p>

        <section v-for="group in methodRows" :key="group.method" class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="border-b border-zinc-100 px-4 py-3 font-semibold dark:border-zinc-800">{{ METHOD_LABELS[group.method] || group.method }}</h2>
            <table class="w-full min-w-[760px] text-sm">
                <thead class="text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2">Adquirente</th>
                        <th class="px-4 py-2">Tentativas</th>
                        <th class="px-4 py-2">Aprovação técnica</th>
                        <th class="px-4 py-2">Recusa</th>
                        <th class="px-4 py-2">Erro técnico</th>
                        <th class="px-4 py-2">Timeout</th>
                        <th class="px-4 py-2">Latência p50 / p95</th>
                        <th v-if="group.method === 'pix'" class="px-4 py-2">PIX pago</th>
                        <th v-if="group.method === 'pix'" class="px-4 py-2">Custo / R$100</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in group.rows" :key="r.slug" class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="px-4 py-2 font-medium">
                            {{ name(r.slug) }}
                            <span v-if="!r.sufficient_sample" class="ml-1 text-[11px] font-normal text-zinc-400">amostra pequena</span>
                        </td>
                        <td class="px-4 py-2 tabular-nums">{{ r.attempts }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ pct(r.success_rate) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ pct(r.decline_rate) }}</td>
                        <td class="px-4 py-2 tabular-nums" :class="r.technical_error_rate > 0.1 ? 'text-red-600' : ''">{{ pct(r.technical_error_rate) }}</td>
                        <td class="px-4 py-2 tabular-nums" :class="r.timeout_rate > 0.05 ? 'text-orange-600' : ''">{{ pct(r.timeout_rate) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ r.latency_p50_ms ?? '—' }} / {{ r.latency_p95_ms ?? '—' }} ms</td>
                        <td v-if="group.method === 'pix'" class="px-4 py-2 tabular-nums">{{ pct(r.paid_conversion) }}</td>
                        <td v-if="group.method === 'pix'" class="px-4 py-2 tabular-nums">{{ r.cost_per_100_brl === null ? '—' : `R$ ${Number(r.cost_per_100_brl).toFixed(2)}` }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="flex items-center gap-2 font-semibold"><Route class="h-4 w-4" /> Smart Routing</h2>
            <p class="mt-1 text-xs text-zinc-500">
                Só reordena as adquirentes que o seller já pode usar. Adquirente sem amostra suficiente fica na posição atual.
                Produto ou aplicação de API com adquirente fixada não é reordenado.
            </p>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.enabled" type="checkbox" class="h-4 w-4" /> Ativo para sellers que herdam a plataforma
                </label>
                <label class="text-sm">
                    Estratégia
                    <select v-model="form.strategy" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                        <option v-for="s in strategies" :key="s" :value="s">{{ STRATEGY_LABELS[s] || s }}</option>
                    </select>
                </label>
                <div class="text-sm">
                    Métodos
                    <div class="mt-1 flex flex-wrap gap-3">
                        <label v-for="m in methods" :key="m" class="flex items-center gap-1.5">
                            <input v-model="form.methods" type="checkbox" :value="m" class="h-4 w-4" /> {{ METHOD_LABELS[m] || m }}
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <label>Amostra mínima<input v-model.number="form.min_sample" type="number" min="10" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                    <label>Janela (horas)<input v-model.number="form.window_hours" type="number" min="1" max="168" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                </div>
                <div v-if="form.strategy === 'custom'" class="grid grid-cols-2 gap-3 text-sm md:col-span-2 md:grid-cols-4">
                    <label v-for="(label, key) in { approval: 'Aprovação', stability: 'Estabilidade', latency: 'Latência', cost: 'Custo' }" :key="key">
                        {{ label }}
                        <input v-model.number="form.weights[key]" type="number" min="0" max="1" step="0.05" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" />
                    </label>
                </div>
            </div>
            <Button type="button" class="mt-4" :disabled="saving" @click="request('save')">Salvar roteamento</Button>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Simular ordem de cobrança</h2>
            <p class="mt-1 text-xs text-zinc-500">Mostra a ordem que um pagamento usaria agora, já com a política do seller. Nada é cobrado.</p>
            <div class="mt-3 flex flex-wrap items-end gap-2 text-sm">
                <select v-model="sim.method" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                    <option v-for="m in methods" :key="m" :value="m">{{ METHOD_LABELS[m] || m }}</option>
                </select>
                <input v-model="sim.tenant_id" type="number" min="1" placeholder="ID do seller (opcional)" class="w-48 rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" />
                <Button type="button" variant="outline" :disabled="sim.loading" @click="simulate">Simular</Button>
            </div>
            <p v-if="sim.error" class="mt-2 text-sm text-red-600">{{ sim.error }}</p>
            <div v-if="sim.result" class="mt-3 rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800/60">
                <p><b>Ordem final:</b> {{ sim.result.final_order.length ? sim.result.final_order.map(name).join(' → ') : 'nenhuma adquirente disponível' }}</p>
                <p class="mt-1 text-xs text-zinc-500">Estratégia: {{ sim.result.strategy }} · Seller: {{ sim.result.tenant_mode }} · Política: {{ sim.result.policy?.mode || 'inherit' }}</p>
            </div>
        </section>

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
