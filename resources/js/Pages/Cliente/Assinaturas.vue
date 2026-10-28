<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import { Repeat, ArrowLeft, CalendarClock, CreditCard, CircleCheck, CircleAlert, CircleSlash, X } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    reasons: { type: Object, required: true },
});

const STATUS = { active: 'Ativa', past_due: 'Pagamento pendente', cancelled: 'Cancelada' };
const INTERVAL = { weekly: 'semana', monthly: 'mês', quarterly: 'trimestre', semi_annual: 'semestre', annual: 'ano', lifetime: 'vitalício' };
const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const date = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('pt-BR') : '—');

const cancelling = ref(null);
const reason = ref('');
const note = ref('');
const busy = ref(false);

function confirmCancel() {
    if (!reason.value) return;
    busy.value = true;
    router.post(`/painel-cliente/assinaturas/${cancelling.value.id}/cancelar`, { reason: reason.value, note: note.value || undefined }, {
        preserveScroll: true,
        onSuccess: () => (cancelling.value = null),
        onFinish: () => (busy.value = false),
    });
}
function reactivate(s) {
    router.post(`/painel-cliente/assinaturas/${s.id}/reativar`, {}, { preserveScroll: true });
}

/* ---- Apresentação ---- */
const BADGE = { active: 'pn-badge--success', past_due: 'pn-badge--warning', cancelled: 'pn-badge--neutral' };
const counts = computed(() => ({
    active: props.subscriptions.filter((s) => s.status === 'active').length,
    past_due: props.subscriptions.filter((s) => s.status === 'past_due').length,
    cancelled: props.subscriptions.filter((s) => s.status === 'cancelled').length,
}));
</script>

<template>
    <div class="space-y-6">
        <Link href="/painel-cliente" class="inline-flex items-center gap-1.5 rounded-full text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]">
            <ArrowLeft class="h-4 w-4" /> Minhas compras
        </Link>

        <PnPageHeader title="Minhas assinaturas" :icon="Repeat"
            description="Acompanhe suas renovações, pague uma cobrança pendente ou cancele. Ao cancelar, você mantém o acesso até o fim do período já pago." />

        <PnEmptyState v-if="!subscriptions.length" title="Você não tem assinaturas." description="Quando você assinar um produto, ele aparece aqui com a data de renovação." :icon="Repeat">
            <Link href="/painel-cliente" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto">Ver minhas compras</Link>
        </PnEmptyState>

        <template v-else>
            <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Resumo das assinaturas">
                <PnKpiCard label="Ativas" :value="counts.active" :icon="CircleCheck" caption="com acesso liberado" :index="0" />
                <PnKpiCard label="Pagamento pendente" :value="counts.past_due" :icon="CircleAlert" caption="aguardando a renovação ser paga" :index="1" />
                <PnKpiCard label="Canceladas" :value="counts.cancelled" :icon="CircleSlash" caption="sem novas cobranças" :index="2" />
            </section>

            <ul class="grid gap-3 lg:grid-cols-2">
                <li v-for="s in subscriptions" :key="s.id" class="pn-glass flex flex-col gap-4 p-5">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[var(--border-strong)] text-[var(--text-secondary)]">
                            <Repeat class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-base font-medium text-[var(--text-primary)]">{{ s.product }}</p>
                            <p class="truncate text-xs text-[var(--text-tertiary)]">{{ s.plan }}</p>
                        </div>
                        <span class="pn-badge shrink-0" :class="BADGE[s.status] || 'pn-badge--neutral'">{{ STATUS[s.status] || s.status }}</span>
                    </div>

                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <p class="text-xl font-medium tabular-nums tracking-tight text-[var(--text-primary)]">
                            {{ brl(s.price) }} <span class="text-sm font-normal text-[var(--text-tertiary)]">/ {{ INTERVAL[s.interval] || s.interval }}</span>
                        </p>
                        <p v-if="s.status !== 'cancelled'" class="flex items-center gap-1.5 text-xs text-[var(--text-secondary)]">
                            <CalendarClock class="h-3.5 w-3.5" aria-hidden="true" /> acesso até {{ date(s.current_period_end) }}
                        </p>
                    </div>

                    <p v-if="s.cancel_at_period_end" class="rounded-2xl bg-[var(--status-warning-bg)] px-3.5 py-2 text-xs text-[var(--status-warning-fg)]">
                        Renovação cancelada — termina em {{ date(s.current_period_end) }}.
                    </p>

                    <div v-if="s.status !== 'cancelled'" class="flex flex-wrap gap-2 border-t border-[var(--border-strong)] pt-4">
                        <a v-if="s.renewal_url" :href="s.renewal_url" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto"><CreditCard class="h-3.5 w-3.5" /> Pagar / trocar forma de pagamento</a>
                        <button v-if="!s.cancel_at_period_end" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="cancelling = s; reason = ''; note = ''">Cancelar renovação</button>
                        <button v-if="s.cancel_at_period_end && s.status === 'active' && !s.pix_auto" type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" @click="reactivate(s)">Manter assinatura</button>
                    </div>
                </li>
            </ul>
        </template>

        <Teleport to="body">
            <div v-if="cancelling" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 backdrop-blur-[2px] sm:items-center" @click.self="cancelling = null">
                <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-[28px] border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6 shadow-2xl"
                    role="dialog" aria-modal="true" aria-labelledby="cancel-sub-title" @keydown.esc="cancelling = null">
                    <div class="flex items-start justify-between gap-3">
                        <h2 id="cancel-sub-title" class="text-base font-medium text-[var(--text-primary)]">Cancelar a renovação de {{ cancelling.product }}?</h2>
                        <button type="button" class="-mr-1 -mt-1 rounded-full p-2 text-[var(--text-secondary)] hover:bg-[var(--bg-muted)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" aria-label="Fechar" @click="cancelling = null"><X class="h-4 w-4" /></button>
                    </div>
                    <p class="mt-1.5 text-sm text-[var(--text-secondary)]">Você mantém o acesso até {{ date(cancelling.current_period_end) }} e não será cobrado de novo.</p>

                    <fieldset class="mt-5">
                        <legend class="text-sm font-medium text-[var(--text-primary)]">Por que está cancelando?</legend>
                        <div class="mt-2 space-y-1.5">
                            <label v-for="(label, key) in reasons" :key="key"
                                class="flex cursor-pointer items-center gap-3 rounded-2xl border px-3.5 py-2.5 text-sm text-[var(--text-primary)] transition focus-within:ring-4 focus-within:ring-[var(--focus-ring)]"
                                :class="reason === key ? 'border-[var(--text-primary)] bg-[var(--glass-bg-strong)]' : 'border-[var(--border-strong)] hover:bg-[var(--bg-muted)]'">
                                <input v-model="reason" type="radio" :value="key" class="accent-[var(--ink-900)] dark:accent-[var(--lime-300)]" /> {{ label }}
                            </label>
                        </div>
                    </fieldset>

                    <label for="cancel-sub-note" class="mt-4 block text-xs font-medium text-[var(--text-secondary)]">Quer contar mais? (opcional)</label>
                    <textarea id="cancel-sub-note" v-model="note" rows="2" maxlength="1000" placeholder="Quer contar mais? (opcional)"
                        class="mt-1.5 w-full rounded-2xl border border-[var(--border-strong)] bg-[var(--glass-bg)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]" />

                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="cancelling = null">Voltar</button>
                        <button type="button" class="pn-btn pn-btn--danger pn-btn--sm !w-auto" :disabled="!reason || busy" @click="confirmCancel">Cancelar renovação</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
