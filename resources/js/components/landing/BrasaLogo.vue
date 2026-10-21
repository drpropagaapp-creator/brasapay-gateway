<script setup>
/**
 * Logo oficial do BrasaPay (lockup 361×88, fundo transparente).
 * `branca` = texto "Brasa" branco, para fundos escuros (padrão da landing);
 * `preta` = texto escuro, para fundos claros.
 */
defineProps({
    size: { type: Number, default: 30 },
    variant: { type: String, default: 'branca' },
});
</script>

<template>
    <img
        :src="`/images/brasapay/logo-${variant}.png`"
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
