<script setup>
import PnBanner from '@/components/paynext/PnBanner.vue';
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ShieldAlert, ChevronRight } from 'lucide-vue-next';

const page = usePage();

const openCount = computed(() => Number(page.props.med_open_count ?? 0));

// Não repete o alerta dentro da própria área de disputas.
const onDisputesPage = computed(() => (page.url.split('?')[0] || '').startsWith('/vendas/disputas'));

const show = computed(() => openCount.value > 0 && !onDisputesPage.value);
</script>

<template>
    <PnBanner
        v-if="show"
        tone="error"
        role="alert"
        :icon="ShieldAlert"
        :title="openCount === 1 ? 'Você tem 1 disputa MED aberta' : `Você tem ${openCount} disputas MED abertas`"
        text="Um cliente contestou o pagamento PIX de uma venda sua junto ao banco (MED — Mecanismo Especial de Devolução). O valor da(s) venda(s) contestada(s) está retido na sua carteira até a resolução. Envie sua defesa com comprovantes de entrega."
    >
        <template #actions>
            <Link href="/vendas/disputas" class="pn-btn pn-btn--danger pn-btn--sm pn-keep-shape no-underline hover:no-underline">
                Ver disputas <ChevronRight class="h-4 w-4" />
            </Link>
        </template>
    </PnBanner>
</template>
