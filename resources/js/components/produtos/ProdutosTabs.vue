<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Package, Handshake, UserPlus } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const page = usePage();
const { t } = useI18n();

const path = computed(() => page.url.split('?')[0]);

const isCoproducao = computed(() =>
    path.value === '/coproducao'
    || path.value === '/produtos/coproducao'
    || path.value.startsWith('/produtos/coproducao/')
);

const isAfiliados = computed(() =>
    path.value === '/produtos/afiliados'
    || path.value.startsWith('/produtos/afiliados/')
    || /^\/produtos\/[^/]+\/painel-afiliado/.test(path.value)
);

const isProdutos = computed(() => {
    const p = path.value;
    if (
        p === '/coproducao'
        || p === '/produtos/coproducao'
        || p.startsWith('/produtos/coproducao/')
        || p === '/produtos/afiliados'
        || p.startsWith('/produtos/afiliados/')
        || /^\/produtos\/[^/]+\/painel-afiliado/.test(p)
    ) {
        return false;
    }
    return p === '/produtos' || /^\/produtos\/[^/]+/.test(p);
});

/** Abas no padrão Paynext DS: barra preta em pílula, ativa em lime. */
const navClass = 'pn-tabs max-w-full';

function linkClass(active) {
    return ['pn-tab pn-keep-shape inline-flex items-center gap-2 no-underline hover:no-underline', active ? 'pn-tab--active' : ''];
}
</script>

<template>
    <nav
        :class="navClass"
        :aria-label="t('sidebar.products', 'Produtos')"
    >
        <Link href="/produtos" :class="linkClass(isProdutos)">
            <Package class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('products.tab_products', 'Produtos') }}
        </Link>
        <Link href="/coproducao" :class="linkClass(isCoproducao)">
            <Handshake class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('products.tab_coproduction', 'Co-produção') }}
        </Link>
        <Link href="/produtos/afiliados" :class="linkClass(isAfiliados)">
            <UserPlus class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('products.tab_affiliates', 'Afiliados') }}
        </Link>
    </nav>
</template>
