<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { ArrowLeft, X } from 'lucide-vue-next';

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
</script>

<template>
    <div class="space-y-5">
        <Link href="/crm" class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:underline"><ArrowLeft class="h-4 w-4" /> Clientes</Link>
        <div>
            <h1 class="text-2xl font-semibold">{{ contact.name || contact.email }}</h1>
            <p class="text-sm text-zinc-500">{{ contact.email }}<template v-if="contact.phone"> · {{ contact.phone }}</template></p>
            <p v-if="contact.recovery_opted_out" class="mt-1 text-xs text-amber-700">Descadastrado dos e-mails de recuperação.</p>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Compras pagas</p><p class="mt-1 text-xl font-semibold">{{ contact.paid_orders }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Total pago</p><p class="mt-1 text-xl font-semibold">{{ brl(contact.total_paid) }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Ticket médio</p><p class="mt-1 text-xl font-semibold">{{ brl(contact.avg_ticket) }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Última compra</p><p class="mt-1 text-xl font-semibold">{{ contact.last_paid_at ? new Date(contact.last_paid_at).toLocaleDateString('pt-BR') : '—' }}</p></div>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Tags e notas</h2>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span v-for="(t, i) in tags" :key="t" class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-xs dark:bg-zinc-800">{{ t }}<button type="button" :aria-label="`Remover ${t}`" @click="tags.splice(i, 1)"><X class="h-3 w-3" /></button></span>
                <input v-model="newTag" maxlength="30" placeholder="Nova tag + Enter" class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900" @keydown.enter.prevent="addTag" />
            </div>
            <textarea v-model="notes" rows="3" maxlength="5000" placeholder="Notas internas (só você e sua equipe veem)" class="mt-3 w-full rounded-xl border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
            <Button type="button" class="mt-2" :disabled="saving" @click="save">Salvar</Button>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Pedidos</h2>
            <ul class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="o in contact.orders" :key="o.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>#{{ o.id }} · {{ o.product || '—' }} <span class="text-xs text-zinc-500">· {{ o.payment_method }}<template v-if="o.utm_source"> · origem {{ o.utm_source }}</template></span></span>
                    <span class="text-xs"><b class="tabular-nums">{{ brl(o.amount) }}</b> · {{ STATUS[o.status] || o.status }} · {{ dt(o.created_at) }}</span>
                </li>
            </ul>
        </section>

        <section v-if="contact.subscriptions.length" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Assinaturas</h2>
            <ul class="mt-2 text-sm">
                <li v-for="(s, i) in contact.subscriptions" :key="i">{{ s.product }} · {{ s.status }} · até {{ s.current_period_end || '—' }}</li>
            </ul>
        </section>
    </div>
</template>
