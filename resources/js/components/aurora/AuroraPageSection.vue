<script setup>
/** Seção de página com a entrada fade-up do Paynext DS; os cartões internos já são vidro. */
defineProps({
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="pn-anim min-w-0">
        <slot />
    </section>
</template>
