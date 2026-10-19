<script setup>
/**
 * Aurora do Paynext DS: três orbes desfocados (lime, sky, lilás) em deriva lenta,
 * fixos atrás do conteúdo. A grade de 64px e o gradiente ficam no body (overrides.css).
 */
</script>

<template>
    <div class="pn-aurora-orbs" aria-hidden="true">
        <span class="pn-orb" style="width: 46vw; height: 46vw; left: -12vw; top: -18vw; background: var(--orb-1); animation-delay: 0s" />
        <span class="pn-orb" style="width: 40vw; height: 40vw; right: -14vw; top: -8vw; background: var(--orb-2); animation-delay: -7s" />
        <span class="pn-orb" style="width: 38vw; height: 38vw; left: 30vw; bottom: -26vw; background: var(--orb-3); animation-delay: -14s" />
    </div>
</template>

<style scoped>
.pn-aurora-orbs {
    position: fixed;
    inset: 0;
    z-index: -1;
    overflow: hidden;
    pointer-events: none;
    opacity: 0.55;
}
:global(html:not(.dark) .pn-aurora-orbs) {
    opacity: 0.45;
}
</style>
