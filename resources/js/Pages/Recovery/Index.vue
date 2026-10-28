<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnBanner from '@/components/paynext/PnBanner.vue';
import {
    MailCheck, Plus, Trash2, BadgeCheck, Wallet, Send, MailX, MailWarning, Moon, Braces, Inbox, Save, Clock, Lock,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Recovery — e-mails de recuperação. Venda recuperada só conta com pagamento confirmado
 * depois do e-mail; quem se descadastra ou já comprou o produto não recebe.
 */
const props = defineProps({
    settings: { type: Object, required: true },
    smtp_ready: { type: Boolean, default: false },
    can_edit: { type: Boolean, default: true },
    stats: { type: Object, required: true },
    by_event: { type: [Object, Array], default: () => ({}) },
    recent: { type: Array, default: () => [] },
});

const EVENTS = {
    cart_abandoned: { label: 'Carrinho abandonado', hint: 'Preencheu o checkout e não comprou.' },
    pix_pending: { label: 'PIX não pago', hint: 'Gerou o PIX e não pagou.' },
    boleto_pending: { label: 'Boleto em aberto', hint: 'Gerou o boleto e não pagou.' },
};
const STATUS = { sent: 'Enviado', failed: 'Falhou', skipped: 'Pulado', sending: 'Enviando' };
const STATUS_BADGE = { sent: 'pn-badge--success', failed: 'pn-badge--error', skipped: 'pn-badge--neutral', sending: 'pn-badge--info' };
const SKIP = { opted_out: 'descadastrado', already_bought: 'já comprou' };
const VARS = ['{nome}', '{produto}', '{valor}', '{link}'];

const form = reactive(JSON.parse(JSON.stringify(props.settings)));
const saving = ref(false);
const brl = (c) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(c) || 0) / 100);
const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const delayLabel = (m) => (m >= 1440 ? `${Math.round(m / 1440)} dia(s)` : m >= 60 ? `${Math.round(m / 60)} h` : `${m} min`);
const when = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '');
const field = 'w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)]';

function addStep(key) {
    const steps = form.events[key].steps;
    if (steps.length >= 3) return;
    const last = steps[steps.length - 1];
    steps.push({ delay_minutes: last ? last.delay_minutes * 2 : 60, subject: '{nome}, ainda dá tempo', body: 'Oi {nome}, {produto} continua disponível:\n\n{link}' });
}
function save() {
    saving.value = true;
    router.put('/recuperacao', form, { preserveScroll: true, onFinish: () => (saving.value = false) });
}
</script>

<template>
    <div class="space-y-6">
        <PnPageHeader
            title="Recuperação de vendas"
            :icon="MailCheck"
            description="E-mails automáticos para quem abandonou o checkout ou não pagou o PIX/boleto. Respeita horário silencioso, limite por dia e descadastro. Só conta como recuperada a venda paga depois do e-mail."
        />

        <PnBanner
            v-if="!smtp_ready"
            tone="warning"
            :icon="MailWarning"
            title="Configure o seu e-mail de envio"
            text="Vá em Configurações → E-mail para os lembretes saírem. Por segurança, a recuperação nunca usa o e-mail da plataforma."
        />

        <!-- Indicadores (30 dias) -->
        <section class="kpi-row grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resultados dos últimos 30 dias">
            <PnKpiCard :index="0" label="Vendas recuperadas (30 d)" :icon="BadgeCheck" :value="num(stats.recovered_orders)" caption="pagas depois do e-mail" />
            <PnKpiCard :index="1" label="Receita recuperada" :icon="Wallet" :value="brl(stats.recovered_amount_cents)" caption="só pagamento confirmado" />
            <PnKpiCard :index="2" label="E-mails enviados" :icon="Send" :value="num(stats.sent)" :caption="`${num(stats.failed)} falhas · ${num(stats.skipped)} pulados`" />
            <PnKpiCard :index="3" label="Descadastros" :icon="MailX" :value="num(stats.opt_outs)" caption="não recebem mais lembretes" />
        </section>

        <!-- Configuração geral -->
        <PnPanel title="Configuração geral" sub="Vale para todos os lembretes de recuperação." :index="1">
            <template #right>
                <span class="pn-badge shrink-0" :class="form.enabled ? 'pn-badge--success' : 'pn-badge--neutral'">{{ form.enabled ? 'Ativa' : 'Desligada' }}</span>
            </template>

            <div class="space-y-5">
                <label class="flex cursor-pointer items-center justify-between gap-4 rounded-[20px] bg-[var(--bg-muted)]/60 p-4">
                    <span>
                        <span class="block text-sm font-medium text-[var(--text-primary)]">Recuperação por e-mail ativa</span>
                        <span class="block text-xs text-[var(--text-tertiary)]">Desligada, nenhum lembrete é enviado.</span>
                    </span>
                    <input v-model="form.enabled" type="checkbox" class="peer sr-only" />
                    <span class="toggle" aria-hidden="true" />
                </label>

                <div>
                    <p class="mb-2 flex items-center gap-1.5 text-xs font-medium text-[var(--text-secondary)]"><Moon class="h-3.5 w-3.5" aria-hidden="true" /> Horário silencioso e limite</p>
                    <div class="grid grid-cols-3 gap-3 sm:max-w-md">
                        <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                            Silêncio das
                            <input v-model.number="form.quiet_start" type="number" min="0" max="23" class="h-11 tabular-nums" :class="field" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                            até as
                            <input v-model.number="form.quiet_end" type="number" min="0" max="23" class="h-11 tabular-nums" :class="field" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                            Máx. por dia
                            <input v-model.number="form.max_per_day" type="number" min="1" max="5" class="h-11 tabular-nums" :class="field" />
                        </label>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 text-xs text-[var(--text-tertiary)]">
                    <Braces class="h-3.5 w-3.5" aria-hidden="true" />
                    <span>Variáveis:</span>
                    <code v-for="v in VARS" :key="v" class="rounded-full bg-[var(--bg-muted)] px-2 py-0.5 font-mono text-[11px] text-[var(--text-primary)]">{{ v }}</code>
                    <span>O link de descadastro entra sempre no rodapé.</span>
                </div>
            </div>
        </PnPanel>

        <!-- Sequências por evento -->
        <section class="space-y-4" aria-label="Sequências de e-mail">
            <PnPanel v-for="(meta, key, idx) in EVENTS" :key="key" :index="2 + idx">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <label class="flex min-w-0 cursor-pointer items-center gap-3">
                        <input v-model="form.events[key].enabled" type="checkbox" class="peer sr-only" />
                        <span class="toggle" aria-hidden="true" />
                        <span class="min-w-0">
                            <span class="block text-base font-medium text-[var(--text-primary)]">{{ meta.label }}</span>
                            <span class="block text-xs text-[var(--text-tertiary)]">{{ meta.hint }}</span>
                        </span>
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="pn-badge pn-badge--neutral pn-badge--nodot tabular-nums">{{ by_event[key] || 0 }} enviados (30 d)</span>
                        <span class="pn-badge pn-badge--nodot tabular-nums" :class="form.events[key].steps.length ? 'pn-badge--info' : 'pn-badge--warning'">{{ form.events[key].steps.length }}/3 passos</span>
                    </div>
                </div>

                <ol class="mt-4 space-y-3">
                    <li v-for="(step, i) in form.events[key].steps" :key="i" class="rounded-[20px] border border-[var(--border-strong)] bg-[var(--bg-muted)]/40 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2 text-sm text-[var(--text-secondary)]">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--text-primary)] text-xs font-medium text-[var(--text-inverse)]">{{ i + 1 }}</span>
                                <label class="flex flex-wrap items-center gap-2">
                                    <Clock class="h-3.5 w-3.5" aria-hidden="true" /> Enviar após
                                    <input v-model.number="step.delay_minutes" type="number" min="10" max="10080" class="h-9 !w-24 tabular-nums" :class="field" /> min
                                </label>
                                <span class="pn-badge pn-badge--brand pn-badge--nodot">{{ delayLabel(step.delay_minutes) }}</span>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-full text-[var(--text-tertiary)] transition hover:bg-[var(--status-error-bg)] hover:text-[var(--status-error-fg)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                                aria-label="Remover passo"
                                @click="form.events[key].steps.splice(i, 1)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="mt-3 space-y-3">
                            <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                                Assunto
                                <input v-model="step.subject" maxlength="150" placeholder="Assunto" class="h-11" :class="field" />
                            </label>
                            <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                                Mensagem
                                <textarea v-model="step.body" rows="4" maxlength="3000" class="!rounded-[20px] py-3 leading-6" :class="field" />
                            </label>
                        </div>
                    </li>
                </ol>
                <p v-if="!form.events[key].steps.length" class="mt-4 rounded-[20px] border border-dashed border-[var(--border-strong)] px-4 py-5 text-center text-xs text-[var(--text-tertiary)]">Nenhum passo nesta sequência.</p>

                <button v-if="form.events[key].steps.length < 3" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto mt-3" @click="addStep(key)">
                    <Plus class="h-4 w-4" aria-hidden="true" /> Adicionar passo
                </button>
            </PnPanel>
        </section>

        <div class="pn-glass flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <template v-if="can_edit">
                <p class="text-xs text-[var(--text-tertiary)]">Salve para aplicar as mudanças nas sequências e no horário.</p>
                <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="saving" @click="save">
                    <Save class="h-4 w-4" aria-hidden="true" /> {{ saving ? 'Salvando…' : 'Salvar' }}
                </button>
            </template>
            <p v-else class="flex items-center gap-2 text-xs text-[var(--text-tertiary)]"><Lock class="h-3.5 w-3.5" aria-hidden="true" /> Só o titular da conta pode alterar os textos.</p>
        </div>

        <!-- Últimos envios -->
        <PnPanel title="Últimos envios" sub="Status de cada lembrete e se a venda foi recuperada." :index="5">
            <div v-if="!recent.length" class="flex flex-col items-center gap-2 rounded-[20px] border border-dashed border-[var(--border-strong)] px-6 py-8 text-center">
                <Inbox class="h-6 w-6 text-[var(--text-tertiary)]" aria-hidden="true" />
                <p class="text-sm text-[var(--text-primary)]">Nenhum envio ainda.</p>
                <p class="text-xs text-[var(--text-tertiary)]">Os lembretes aparecem aqui assim que forem disparados.</p>
            </div>
            <ul v-else class="divide-y divide-[var(--border-strong)]">
                <li v-for="m in recent" :key="m.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3 first:pt-0 last:pb-0">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ EVENTS[m.event]?.label }} <span class="font-normal text-[var(--text-tertiary)]">· passo {{ m.step + 1 }}</span></p>
                        <p class="truncate text-xs text-[var(--text-secondary)]">{{ m.recipient }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="pn-badge" :class="STATUS_BADGE[m.status] || 'pn-badge--neutral'">
                            {{ STATUS[m.status] || m.status }}<template v-if="m.skip_reason"> ({{ SKIP[m.skip_reason] || m.skip_reason }})</template>
                        </span>
                        <span v-if="m.recovered" class="pn-badge pn-badge--brand">recuperado</span>
                        <span v-if="m.sent_at" class="text-xs tabular-nums text-[var(--text-tertiary)]">{{ when(m.sent_at) }}</span>
                    </div>
                </li>
            </ul>
        </PnPanel>
    </div>
</template>

<style scoped>
.kpi-row :deep(.pn-kpi) {
    container-type: inline-size;
}
.kpi-row :deep(.pn-kpi__value) {
    /* Tamanho acompanha a largura do card; só quebra no espaço depois de "R$", nunca no meio do número. */
    font-size: clamp(18px, 9.5cqi, 32px);
    line-height: 1.2;
    overflow-wrap: normal;
}

/* Interruptor visual; o checkbox real (sr-only, peer) continua sendo o controle. */
.toggle {
    position: relative;
    flex: none;
    width: 44px;
    height: 26px;
    border-radius: 9999px;
    background: var(--bg-muted);
    border: 1px solid var(--border-strong);
    transition: background-color 150ms, border-color 150ms, box-shadow 150ms;
}
.toggle::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 18px;
    height: 18px;
    border-radius: 9999px;
    background: var(--text-tertiary);
    transition: transform 180ms cubic-bezier(0.16, 1, 0.3, 1), background-color 150ms;
}
.peer:checked + .toggle {
    background: var(--lime-300);
    border-color: var(--lime-400);
}
.peer:checked + .toggle::after {
    transform: translateX(18px);
    background: var(--ink-900);
}
.peer:focus-visible + .toggle {
    box-shadow: 0 0 0 4px var(--focus-ring);
}
@media (prefers-reduced-motion: reduce) {
    .toggle,
    .toggle::after {
        transition: none;
    }
}
</style>
