<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { Contact, Search } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/** Brasa CRM — compradores do seller, montados a partir dos próprios pedidos. */
const props = defineProps({
    filters: { type: Object, required: true },
    segments: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    contacts: { type: Object, required: true },
});

const q = ref(props.filters.q || '');
const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const date = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR') : '—');

function apply(patch) {
    router.get('/crm', { ...props.filters, q: q.value || undefined, ...patch }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold"><Contact class="h-6 w-6" /> Clientes</h1>
            <p class="mt-1 text-sm text-zinc-500">Quem comprou ou tentou comprar seus produtos. Você só vê o que aconteceu nos seus produtos.</p>
        </div>

        <nav class="pn-seg max-w-full overflow-x-auto" aria-label="Segmentos">
            <button v-for="(label, key) in segments" :key="key" type="button" class="pn-tab pn-keep-shape" :class="filters.segment === key ? 'pn-tab--active' : ''" @click="apply({ segment: key, page: undefined })">{{ label }}</button>
        </nav>

        <div class="flex flex-wrap gap-2">
            <form class="relative w-full max-w-sm" @submit.prevent="apply({ page: undefined })">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input v-model="q" type="search" placeholder="Buscar por e-mail" class="w-full rounded-xl border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
            </form>
            <select class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" :value="filters.product || ''" @change="apply({ product: $event.target.value || undefined, page: undefined })">
                <option value="">Todos os produtos</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
        </div>

        <p class="text-xs text-zinc-500">{{ contacts.total }} contato(s)</p>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="text-left text-xs text-zinc-500">
                    <tr><th class="px-4 py-2">Contato</th><th class="px-4 py-2">Compras</th><th class="px-4 py-2">Total pago</th><th class="px-4 py-2">Última compra</th><th class="px-4 py-2">Tags</th></tr>
                </thead>
                <tbody>
                    <tr v-if="!contacts.data.length"><td colspan="5" class="px-4 py-8 text-center text-zinc-500">Nenhum contato neste segmento.</td></tr>
                    <tr v-for="c in contacts.data" :key="c.email" class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="px-4 py-2"><Link :href="`/crm/contato/${c.key}`" class="font-medium hover:underline">{{ c.email }}</Link><p v-if="c.phone" class="text-xs text-zinc-500">{{ c.phone }}</p></td>
                        <td class="px-4 py-2 tabular-nums">{{ c.paid_orders }}<span v-if="c.pending_orders" class="text-xs text-amber-600"> · {{ c.pending_orders }} pend.</span><span v-if="c.refunds" class="text-xs text-red-600"> · {{ c.refunds }} reemb.</span></td>
                        <td class="px-4 py-2 tabular-nums">{{ brl(c.total_paid) }}</td>
                        <td class="px-4 py-2">{{ date(c.last_paid_at) }}</td>
                        <td class="px-4 py-2"><span v-for="t in c.tags" :key="t" class="mr-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] dark:bg-zinc-800">{{ t }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="contacts.total > contacts.data.length" class="flex flex-wrap gap-1">
            <Link v-for="(l, i) in contacts.links" :key="i" :href="l.url || ''" preserve-scroll class="rounded-lg px-3 py-1.5 text-sm" :class="[l.active ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600', !l.url ? 'pointer-events-none opacity-40' : '']" v-html="l.label" />
        </nav>
    </div>
</template>
