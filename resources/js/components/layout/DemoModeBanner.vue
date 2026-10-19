<script setup>
import PnBanner from '@/components/paynext/PnBanner.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';

const page = usePage();
const enabled = computed(() => !!page.props.demo_mode?.enabled);
</script>

<template>
    <PnBanner v-if="enabled" tone="warning" :icon="AlertTriangle" title="Modo demonstração" text="Dados ilustrativos. Alterações desabilitadas." />
</template>
