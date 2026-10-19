<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CircleDollarSign, Handshake } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const props = defineProps({
    tab: { type: String, default: 'painel' },
});

const panelHref = '/coproducao';
const participacoesHref = '/coproducao?tab=participacoes';

const activeTab = computed(() => (props.tab === 'participacoes' ? 'participacoes' : 'painel'));

/** Abas no padrão Paynext DS: barra preta em pílula, ativa em lime. */
const navClass = 'pn-tabs max-w-full';

function linkClass(active) {
    return ['pn-tab pn-keep-shape inline-flex items-center gap-2 no-underline hover:no-underline', active ? 'pn-tab--active' : ''];
}
</script>

<template>
    <nav :class="navClass" :aria-label="t('sidebar.coproduction', 'Co-produção')">
        <Link :href="panelHref" :class="linkClass(activeTab === 'painel')">
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('coproduction.tab_panel', 'Painel') }}
        </Link>
        <Link :href="participacoesHref" :class="linkClass(activeTab === 'participacoes')">
            <Handshake class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ t('coproduction.tab_participations', 'Suas participações') }}
        </Link>
    </nav>
</template>
