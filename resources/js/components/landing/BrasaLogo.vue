<script setup>
/**
 * Logo oficial do BrasaPay. Por padrão usa a versão branca empacotada
 * (lockup 361×88, fundo transparente); `src` permite apontar para a logo
 * do white-label compartilhada pelo painel.
 */
defineProps({
    size: { type: Number, default: 30 },
    variant: { type: String, default: 'branca' },
    src: { type: String, default: '' },
});
</script>

<template>
    <img
        :src="src || `/images/brasapay/logo-${variant}.png`"
        alt="BrasaPay"
        class="bp-logo"
        :style="{ height: `${size}px` }"
        draggable="false"
    />
</template>

<style scoped>
.bp-logo {
    width: auto;
    display: block;
    user-select: none;
    filter: drop-shadow(0 0 14px rgba(0, 136, 148, 0.35));
}
</style>
