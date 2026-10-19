<script setup>
import { onUnmounted, watch } from 'vue';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';

/**
 * Cabeçalho de página do Paynext DS: título e subtítulo vão para o topbar
 * (h1 grande do layout), em vez de um segundo título dentro do conteúdo.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});

const { setHeading, clearHeading } = useThemedPageHeading();

watch(
    () => [props.title, props.subtitle],
    () => setHeading({ title: props.title, subtitle: props.subtitle }),
    { immediate: true },
);

onUnmounted(() => clearHeading());
</script>

<template>
    <span class="hidden" aria-hidden="true" />
</template>
