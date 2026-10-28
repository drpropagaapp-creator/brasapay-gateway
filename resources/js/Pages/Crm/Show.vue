<script setup>
import { computed, h, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import {
    ArrowLeft, X, Plus, Copy, MessageCircle, Wallet, ShoppingBag, Receipt, CalendarClock, Package, Repeat, History, Tag, MailX,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({ contact: { type: Object, required: true } });

const STATUS = { completed: 'Pago', pending: 'Pendente', refunded: 'Reembolsado', disputed: 'Em MED', cancelled: 'Cancelado', failed: 'Falhou', refund_pending: 'Reembolso em andamento' };
const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const dt = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

const tags = ref([...(props.contact.tags || [])]);
const newTag = ref('');
const notes = ref(props.contact.notes || '');
const saving = ref(false);

function addTag() {
    const t = newTag.value.trim().slice(0, 30);
    if (t && !tags.value.includes(t) && tags.value.length < 10) tags.value.push(t);
    newTag.value = '';
}
function save() {
    saving.value = true;
    router.put(`/crm/contato/${props.contact.key}`, { tags: tags.value, notes: notes.value }, { preserveScroll: true, onFinish: () => (saving.value = false) });
}

/* ---- Apresentação ---- */
const day = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR') : '—');
// current_period_end das assinaturas vem como data pura (AAAA-MM-DD).
const dateOnly = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('pt-BR') : '—');

const displayName = computed(() => props.contact.name || (props.contact.placeholder ? 'Sem e-mail' : props.contact.email));
const subtitle = computed(() => {
    const c = props.contact;
    // Sem nome, o e-mail já é o título: não repete.
    const first = c.placeholder ? `Comprou por ${c.placeholder}, sem e-mail informado` : c.name ? c.email : '';
    return [first, c.phone].filter(Boolean).join(' · ');
});
const initials = computed(() => {
    const c = props.contact;
    if (c.placeholder?.startsWith('Telegram')) return 'TG';
    if (c.placeholder === 'PixGo') return 'PX';
    const base = (c.name || (c.email || '?').split('@')[0]).replace(/[^a-zA-ZÀ-ÿ]/g, ' ').trim().split(/\s+/);
    return ((base[0]?.[0] || '?') + (base[1]?.[0] || base[0]?.[1] || '')).toUpperCase();
});
// Iniciais no círculo do cabeçalho (PnPageHeader recebe um componente como ícone).
// As classes de tamanho passadas pelo cabeçalho chegam por herança de atributos (class).
const AvatarMark = () => h('span', { class: 'inline-flex items-center justify-center text-[13px] font-semibold leading-none' }, initials.value);

const whatsapp = computed(() => {
    const d = (props.contact.phone || '').replace(/\D/g, '');
    return d.length >= 10 ? `https://wa.me/${d.length <= 11 ? '55' + d : d}` : null;
});
const copied = ref(false);
function copyEmail() {
    navigator.clipboard?.writeText(props.contact.email);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

const DOT = {
    completed: 'bg-[var(--lime-400)] ring-2 ring-[var(--lime-600)]/30',
    pending: 'bg-[var(--bg-surface)] ring-2 ring-amber-500',
    refunded: 'bg-red-500', disputed: 'bg-red-600', refund_pending: 'bg-red-300',
    cancelled: 'bg-[var(--ink-200)]', failed: 'bg-[var(--ink-300)]',
};
const BADGE = {
    completed: 'pn-badge--success', pending: 'pn-badge--warning', refunded: 'pn-badge--error', disputed: 'pn-badge--error',
    refund_pending: 'pn-badge--error', cancelled: 'pn-badge--neutral', failed: 'pn-badge--neutral',
};
const SUB_STATUS = { active: 'Ativa', past_due: 'Pagamento pendente', cancelled: 'Cancelada' };
const SUB_BADGE = { active: 'pn-badge--success', past_due: 'pn-badge--warning', cancelled: 'pn-badge--neutral' };
</script>

<template>
    <div class="space-y-6">
        <Link href="/crm" class="inline-flex items-center gap-1.5 rounded-full text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]">
            <ArrowLeft class="h-4 w-4" /> Clientes
        </Link>

        <PnPageHeader class="[&_h1]:[overflow-wrap:anywhere]" eyebrow="Ficha do cliente" :title="displayName" :description="subtitle" :icon="AvatarMark">
            <template #actions>
                <button v-if="!contact.placeholder" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="copyEmail">
                    <Copy class="h-3.5 w-3.5" /> {{ copied ? 'E-mail copiado' : 'Copiar e-mail' }}
                </button>
                <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto">
                    <MessageCircle class="h-3.5 w-3.5" /> WhatsApp
                </a>
            </template>
        </PnPageHeader>

        <p v-if="contact.recovery_opted_out" class="flex items-center gap-2 rounded-2xl bg-[var(--status-warning-bg)] px-4 py-2.5 text-xs text-[var(--status-warning-fg)]">
            <MailX class="h-4 w-4 shrink-0" /> Descadastrado dos e-mails de recuperação.
        </p>

        <!-- Indicadores -->
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumo do cliente">
            <PnKpiCard label="Total pago" :value="Number(contact.total_paid) || 0" prefix="R$" :decimals="2" :icon="Wallet" caption="soma das compras pagas" :index="0" />
            <PnKpiCard label="Compras pagas" :value="Number(contact.paid_orders) || 0" :icon="ShoppingBag" :caption="`${contact.orders.length} pedido(s) no total`" :index="1" />
            <PnKpiCard label="Ticket médio" :value="Number(contact.avg_ticket) || 0" prefix="R$" :decimals="2" :icon="Receipt" caption="média por compra paga" :index="2" />
            <PnKpiCard label="Última compra" :value="contact.last_paid_at ? day(contact.last_paid_at) : '—'" :icon="CalendarClock"
                :caption="contact.first_seen_at ? `cliente desde ${day(contact.first_seen_at)}` : 'sem compra paga'" :index="3" />
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <!-- Histórico -->
            <PnPanel title="Histórico de pedidos" :sub="`${contact.orders.length} pedido(s), do mais recente ao mais antigo`" :index="4">
                <template #right><History class="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                <ol v-if="contact.orders.length" class="relative ml-1.5 space-y-5 border-l border-[var(--border-strong)] pl-6">
                    <li v-for="o in contact.orders" :key="o.id" class="relative">
                        <span class="absolute -left-[30px] top-1.5 h-3 w-3 rounded-full" :class="DOT[o.status] || DOT.failed" aria-hidden="true" />
                        <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-[var(--text-primary)]">
                                    {{ o.product || '—' }} <span class="font-normal text-[var(--text-tertiary)]">#{{ o.id }}</span>
                                </p>
                                <p class="mt-0.5 text-[11.5px] text-[var(--text-tertiary)]">
                                    {{ dt(o.created_at) }} · {{ o.payment_method || '—' }}<template v-if="o.utm_source"> · origem {{ o.utm_source }}</template>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="pn-badge" :class="BADGE[o.status] || 'pn-badge--neutral'">{{ STATUS[o.status] || o.status }}</span>
                                <span class="text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ brl(o.amount) }}</span>
                            </div>
                        </div>
                    </li>
                </ol>
                <p v-else class="py-6 text-center text-sm text-[var(--text-tertiary)]">Nenhum pedido registrado.</p>
            </PnPanel>

            <div class="space-y-6">
                <!-- Produtos -->
                <PnPanel v-if="contact.products?.length" title="Produtos comprados" :index="5">
                    <template #right><Package class="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="p in contact.products" :key="p" class="rounded-full bg-[var(--lime-100)] px-2.5 py-1 text-xs text-[var(--lime-700)]">{{ p }}</span>
                    </div>
                </PnPanel>

                <!-- Assinaturas -->
                <PnPanel v-if="contact.subscriptions.length" title="Assinaturas" :index="6">
                    <template #right><Repeat class="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                    <ul class="space-y-2">
                        <li v-for="(s, i) in contact.subscriptions" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-[var(--bg-muted)]/60 px-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-[var(--text-primary)]">{{ s.product || '—' }}</p>
                                <p class="text-[11.5px] text-[var(--text-tertiary)]">até {{ dateOnly(s.current_period_end) }}</p>
                            </div>
                            <span class="pn-badge" :class="SUB_BADGE[s.status] || 'pn-badge--neutral'">{{ SUB_STATUS[s.status] || s.status }}</span>
                        </li>
                    </ul>
                </PnPanel>

                <!-- Tags e notas -->
                <PnPanel title="Tags e notas" sub="Só você e sua equipe veem." :index="7">
                    <template #right><Tag class="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span v-for="(t, i) in tags" :key="t" class="inline-flex items-center gap-1 rounded-full bg-[var(--bg-muted)] py-1 pl-2.5 pr-1.5 text-xs text-[var(--text-primary)]">
                                {{ t }}
                                <button type="button" class="rounded-full p-0.5 hover:bg-[var(--glass-bg-strong)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]" :aria-label="`Remover ${t}`" @click="tags.splice(i, 1)"><X class="h-3 w-3" /></button>
                            </span>
                            <span v-if="!tags.length" class="text-xs text-[var(--text-tertiary)]">Nenhuma tag.</span>
                        </div>
                        <div v-if="tags.length < 10" class="flex items-center gap-1.5">
                            <label for="crm-new-tag" class="sr-only">Nova tag</label>
                            <input id="crm-new-tag" v-model="newTag" maxlength="30" placeholder="Nova tag + Enter"
                                class="h-9 min-w-0 flex-1 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-3.5 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]"
                                @keydown.enter.prevent="addTag" />
                            <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm pn-btn--icon !w-[34px]" aria-label="Adicionar tag" @click="addTag"><Plus class="h-3.5 w-3.5" /></button>
                        </div>
                        <div>
                            <label for="crm-notes" class="mb-1.5 block text-xs font-medium text-[var(--text-secondary)]">Notas internas</label>
                            <textarea id="crm-notes" v-model="notes" rows="4" maxlength="5000" placeholder="Notas internas (só você e sua equipe veem)"
                                class="w-full rounded-2xl border border-[var(--border-strong)] bg-[var(--glass-bg)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]" />
                        </div>
                        <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="saving" @click="save">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
                    </div>
                </PnPanel>
            </div>
        </div>
    </div>
</template>
