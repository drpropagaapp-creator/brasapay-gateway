<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CircleDollarSign, Repeat, RotateCcw } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const page = usePage();
const { t } = useI18n();

const medOpenCount = computed(() => Number(page.props.med_open_count ?? 0));

const isVendas = computed(() => {
    const url = page.url.split('?')[0];
    return url === '/vendas' || (
        url.startsWith('/vendas')
        && !url.startsWith('/vendas/assinaturas')
        && !url.startsWith('/vendas/disputas')
        && !url.startsWith('/vendas/reembolsos')
    );
});

const isAssinaturas = computed(() => page.url.split('?')[0].startsWith('/vendas/assinaturas'));

const isDisputas = computed(() => page.url.split('?')[0].startsWith('/vendas/disputas'));

const isReembolsos = computed(() => page.url.split('?')[0].startsWith('/vendas/reembolsos'));

/** Abas no padrão Paynext DS: barra preta em pílula, ativa em lime. */
const navClass = 'pn-tabs max-w-full';

function linkClass(active) {
    return ['pn-tab pn-keep-shape inline-flex items-center gap-2 no-underline hover:no-underline', active ? 'pn-tab--active' : ''];
}
</script>

<template>
    <nav
        :class="navClass"
        :aria-label="t('sidebar.sales', 'Vendas')"
    >
        <Link href="/vendas" :class="linkClass(isVendas)">
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('sales.tab_sales', 'Vendas') }}
        </Link>
        <Link href="/vendas/assinaturas" :class="linkClass(isAssinaturas)">
            <Repeat class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('sales.tab_subscriptions', 'Assinaturas') }}
        </Link>
        <Link href="/vendas/disputas" :class="linkClass(isDisputas)">
            <AlertTriangle class="h-4 w-4 shrink-0" aria-hidden="true" />
            Disputas MED
            <span
                v-if="medOpenCount > 0"
                class="pn-nav__count"
            >
                {{ medOpenCount > 99 ? '99+' : medOpenCount }}
            </span>
        </Link>
        <Link href="/vendas/reembolsos" :class="linkClass(isReembolsos)">
            <RotateCcw class="h-4 w-4 shrink-0" aria-hidden="true" />
            Reembolsos
        </Link>
    </nav>
</template>
