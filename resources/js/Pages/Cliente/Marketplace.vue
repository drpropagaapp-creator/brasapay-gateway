<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { Package, Search } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    products: { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({ q: '', category: '' }) },
    categories: { type: Array, default: () => [] },
    platformName: { type: String, default: 'plataforma' },
});

const filterForm = reactive({
    q: props.filters?.q || '',
    category: props.filters?.category || '',
});

function applyFilters() {
    router.get('/painel-cliente/marketplace', {
        q: filterForm.q || undefined,
        category: filterForm.category || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function formatMoney(amount, currency) {
    const code = ['BRL', 'EUR', 'USD'].includes(currency) ? currency : 'BRL';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: code }).format(Number(amount) || 0);
}
</script>

<template>
    <div>
        <Head title="Marketplace" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Marketplace</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Outros produtos da {{ platformName }}. A compra abre direto no checkout.
                </p>
            </div>

            <form
                class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/70 sm:flex-row sm:items-center"
                @submit.prevent="applyFilters"
            >
                <div class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        v-model="filterForm.q"
                        type="search"
                        placeholder="Buscar por nome"
                        class="w-full rounded-lg border border-zinc-200 bg-zinc-50 py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                </div>
                <select
                    v-model="filterForm.category"
                    class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    @change="applyFilters"
                >
                    <option value="">Todas as categorias</option>
                    <option v-for="cat in categories" :key="cat.value" :value="cat.value">{{ cat.label }}</option>
                </select>
                <button
                    type="submit"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:opacity-90 dark:bg-white dark:text-zinc-900"
                >
                    Buscar
                </button>
            </form>

            <p
                v-if="!(products.data || []).length"
                class="rounded-xl border border-dashed border-zinc-200 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
            >
                Nenhum produto no marketplace com esses filtros.
            </p>

            <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="product in products.data"
                    :key="product.id"
                    class="flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70"
                >
                    <div class="relative h-40 w-full overflow-hidden bg-zinc-100 sm:h-44 dark:bg-zinc-800/80">
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center text-zinc-400">
                            <Package class="h-12 w-12" aria-hidden="true" />
                        </div>
                        <span
                            v-if="product.category_label"
                            class="absolute left-2 top-2 rounded-full bg-zinc-900/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white"
                        >
                            {{ product.category_label }}
                        </span>
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <h2 class="line-clamp-2 font-semibold text-zinc-900 dark:text-white">{{ product.name }}</h2>
                        <p v-if="product.seller_name" class="text-xs text-zinc-500 dark:text-zinc-400">{{ product.seller_name }}</p>
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ formatMoney(product.price, product.currency) }}</p>
                        <a
                            :href="product.checkout_url"
                            class="mt-auto inline-flex items-center justify-center rounded-xl bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90"
                        >
                            Comprar
                        </a>
                    </div>
                </article>
            </div>

            <div v-if="(products.links || []).length > 3" class="flex flex-wrap justify-center gap-2">
                <template v-for="(link, index) in products.links" :key="`${link.label}-${index}`">
                    <button
                        v-if="link.url"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :class="link.active
                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                            : 'border border-zinc-200 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800'"
                        @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                        v-html="link.label"
                    />
                    <span v-else class="px-3 py-1.5 text-sm text-zinc-400" v-html="link.label" />
                </template>
            </div>
        </div>
    </div>
</template>
