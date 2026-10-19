<script setup>
/** Seção de página com a entrada do Paynext DS (só opacidade: pode conter modais); os cartões internos já são vidro. */
defineProps({
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="pn-anim-soft min-w-0">
        <slot />
    </section>
</template>
