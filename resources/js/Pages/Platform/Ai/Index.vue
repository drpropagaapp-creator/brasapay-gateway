<script setup>
import { useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import { Bot, Coins, KeyRound, LoaderCircle, Lock, MessageSquare, Timer, TriangleAlert } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/** Brasa AI (admin). Custos são estimativas pela tabela de preços do modelo, em dólar. */
const props = defineProps({
    settings: { type: Object, required: true },
    has_key: { type: Boolean, default: false },
    models: { type: Array, default: () => [] },
    efforts: { type: Array, default: () => [] },
    month: { type: Object, required: true },
    tenants: { type: Array, default: () => [] },
});

const usd = (micros) => `US$ ${(micros / 1_000_000).toFixed(2)}`;
const form = useForm({ ...props.settings, api_key: '', remove_key: false });
function save() {
    form.put('/plataforma/assistente-ia', { preserveScroll: true, onSuccess: () => { form.api_key = ''; form.remove_key = false; } });
}

/* Classes de apresentação. */
const FIELD = 'mt-1.5 h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)] disabled:cursor-not-allowed disabled:opacity-70';
const LABEL = 'block text-xs text-[var(--text-secondary)]';
const CHECK = 'h-4 w-4 shrink-0 rounded accent-[var(--lime-500)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]';
const THEAD = 'text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]';
const TH = 'whitespace-nowrap px-3 py-2.5 font-normal first:pl-0 last:pr-0';
const TD = 'px-3 py-2.5 tabular-nums first:pl-0 last:pr-0';
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6">
        <PnPageHeader eyebrow="Inteligência" title="Assistente IA" :icon="Bot"
            description="O assistente só consulta dados agregados da própria conta do seller, por ferramentas fixas (sem acesso livre ao banco), e não executa ações. Sem chave configurada, os sellers veem “configuração pendente”.">
            <template #actions>
                <span :class="settings.enabled ? 'pn-badge pn-badge--success' : 'pn-badge pn-badge--neutral'">{{ settings.enabled ? 'Ligado' : 'Desligado' }}</span>
                <span :class="has_key ? 'pn-badge pn-badge--info' : 'pn-badge pn-badge--warning'">{{ has_key ? 'Chave configurada' : 'Sem chave' }}</span>
            </template>
        </PnPageHeader>

        <!-- Este mês -->
        <section class="ai-kpis grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Uso neste mês">
            <PnKpiCard label="Respostas" :value="month.answers" :icon="MessageSquare" caption="neste mês" :index="0" />
            <PnKpiCard label="Falhas do provedor" :value="month.errors" :icon="TriangleAlert" caption="neste mês" :index="1" />
            <PnKpiCard label="Custo estimado" :value="usd(month.cost_micros)" :icon="Coins" caption="pela tabela de preços do modelo" :index="2" />
            <PnKpiCard label="Tempo médio" :value="month.avg_ms ? (month.avg_ms / 1000).toFixed(1) + ' s' : '—'" :icon="Timer" caption="por resposta" :index="3" />
        </section>

        <PnPanel title="Configuração" sub="Vale para todos os sellers da plataforma.">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-3 rounded-2xl bg-[var(--bg-muted)]/60 p-3 text-sm text-[var(--text-primary)] md:col-span-2"><input v-model="form.enabled" type="checkbox" :class="CHECK" /> Assistente ligado para os sellers</label>
                <label class="text-sm"><span :class="LABEL">Provedor</span><input value="Anthropic (Claude)" disabled :class="FIELD" /></label>
                <label class="text-sm">
                    <span :class="LABEL">Chave da API</span>
                    <span class="relative block">
                        <KeyRound class="pointer-events-none absolute left-4 top-[calc(50%+3px)] h-4 w-4 -translate-y-1/2 text-[var(--text-tertiary)]" aria-hidden="true" />
                        <input v-model="form.api_key" type="password" autocomplete="off" :placeholder="has_key ? 'Configurada (deixe vazio para manter)' : 'sk-ant-…'" :class="FIELD" class="!pl-10" aria-describedby="ai-key-help" />
                    </span>
                    <span id="ai-key-help" class="mt-1.5 flex items-center gap-1 text-[11px] text-[var(--text-tertiary)]"><Lock class="h-3 w-3 shrink-0" aria-hidden="true" /> A chave salva nunca volta para a tela.</span>
                </label>
                <label class="text-sm">
                    <span :class="LABEL">Modelo</span>
                    <select v-model="form.model" :class="FIELD">
                        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.label }} (US$ {{ m.input }} / {{ m.output }} por milhão de tokens)</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span :class="LABEL">Esforço</span>
                    <select v-model="form.effort" :class="FIELD">
                        <option v-for="e in efforts" :key="e" :value="e">{{ e }}</option>
                    </select>
                </label>
                <label class="text-sm"><span :class="LABEL">Perguntas por usuário/dia</span><input v-model.number="form.daily_messages_per_user" type="number" min="1" max="500" :class="FIELD" /></label>
                <label class="text-sm"><span :class="LABEL">Orçamento por conta/mês (centavos de US$)</span><input v-model.number="form.monthly_budget_cents_per_tenant" type="number" min="0" :class="FIELD" /></label>
                <label class="text-sm"><span :class="LABEL">Orçamento da plataforma/mês (centavos de US$)</span><input v-model.number="form.monthly_budget_cents_platform" type="number" min="0" :class="FIELD" /></label>
                <label v-if="has_key" class="flex items-center gap-3 self-end rounded-2xl border border-dashed border-[var(--border-strong)] p-3 text-sm text-[var(--text-primary)]"><input v-model="form.remove_key" type="checkbox" :class="CHECK" /> Remover a chave salva</label>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="mt-2 text-sm text-[var(--status-error-fg)]" role="alert">{{ e }}</p>
            <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto mt-5" :disabled="form.processing" @click="save">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" /> Salvar
            </button>
        </PnPanel>

        <PnPanel title="Uso por conta neste mês" sub="Custos estimados pela tabela de preços do modelo, em dólar.">
            <div v-if="Object.keys(month.tools || {}).length" class="mb-4">
                <p class="mb-2 text-xs text-[var(--text-secondary)]">Ferramentas mais usadas</p>
                <div class="flex flex-wrap gap-1.5">
                    <span v-for="[k, v] in Object.entries(month.tools)" :key="k" class="inline-flex items-center gap-1.5 rounded-full border border-[var(--border-strong)] px-2.5 py-1 font-mono text-[11px] text-[var(--text-secondary)]">{{ k }} <b class="font-sans font-medium tabular-nums text-[var(--text-primary)]">{{ v }}</b></span>
                </div>
            </div>
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[480px] text-sm">
                    <thead :class="THEAD"><tr><th :class="TH">Conta</th><th :class="TH">Respostas</th><th :class="TH">Falhas</th><th :class="TH">Custo</th></tr></thead>
                    <tbody class="text-[var(--text-primary)]">
                        <tr v-for="t in tenants" :key="t.tenant_id" class="border-t border-[var(--border-strong)] transition-colors hover:bg-[var(--bg-muted)]/40">
                            <td class="px-3 py-2.5 first:pl-0"><span class="font-medium">{{ t.name || '#' + t.tenant_id }}</span> <span class="block text-xs text-[var(--text-tertiary)]">{{ t.email }}</span></td>
                            <td :class="TD">{{ t.answers }}</td>
                            <td :class="[TD, t.errors ? 'text-red-600 dark:text-red-400' : '']">{{ t.errors }}</td>
                            <td :class="TD" class="whitespace-nowrap">{{ usd(t.cost_micros) }}</td>
                        </tr>
                        <tr v-if="!tenants.length"><td colspan="4" class="py-6 text-center text-[var(--text-tertiary)]">Sem uso neste mês.</td></tr>
                    </tbody>
                </table>
            </div>
        </PnPanel>
    </div>
</template>

<style scoped>
.ai-kpis :deep(.pn-kpi) {
    container-type: inline-size;
}
.ai-kpis :deep(.pn-kpi__value) {
    /* Tamanho acompanha a largura do card; só quebra no espaço depois de "R$", nunca no meio do número. */
    font-size: clamp(18px, 9.5cqi, 32px);
    line-height: 1.2;
    overflow-wrap: normal;
}
</style>
