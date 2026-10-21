<script setup>
import { MARK_PATH, MARK_STOPS, MARK_VIEWBOX } from './brasaMark';

defineProps({
    /** Altura do lockup em px. */
    size: { type: Number, default: 30 },
});

const uid = `bp-g-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <span class="bp-logo" :style="{ '--s': `${size}px` }">
        <svg :viewBox="MARK_VIEWBOX" class="bp-logo-mark" aria-hidden="true">
            <defs>
                <linearGradient :id="uid" x1="0" y1="0" x2="1" y2="1">
                    <stop v-for="[o, c] in MARK_STOPS" :key="o" :offset="o" :stop-color="c" />
                </linearGradient>
            </defs>
            <path :d="MARK_PATH" fill-rule="evenodd" :fill="`url(#${uid})`" />
        </svg>
        <span class="bp-logo-word">brasa<em>pay</em></span>
    </span>
</template>

<style scoped>
.bp-logo {
    display: inline-flex;
    align-items: center;
    gap: calc(var(--s) * 0.3);
    line-height: 1;
    user-select: none;
}
.bp-logo-mark {
    height: var(--s);
    width: auto;
    filter: drop-shadow(0 0 calc(var(--s) * 0.4) rgba(47, 213, 127, 0.45));
}
.bp-logo-word {
    font-family: 'Lexend', ui-sans-serif, system-ui, sans-serif;
    font-weight: 600;
    font-size: calc(var(--s) * 0.72);
    letter-spacing: -0.02em;
    color: #f2f7f2;
}
.bp-logo-word em {
    font-style: normal;
    background: linear-gradient(95deg, #cfeb2b, #2fd57f 70%, #19c2a0);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
</style>
