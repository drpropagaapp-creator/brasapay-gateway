<script setup>
/** Medidor do Paynext DS: preenchimento lime com hachura em deriva e marcador ▼. */
defineProps({
    value: { type: Number, default: 50 },
    showMarker: { type: Boolean, default: true },
});
</script>

<template>
    <div class="pn-meter" :style="{ '--w': `${Math.max(0, Math.min(100, value))}%` }" role="progressbar" :aria-valuenow="value" aria-valuemin="0" aria-valuemax="100">
        <div class="pn-meter__fill" />
        <i v-if="showMarker" class="pn-meter__mark" />
    </div>
</template>
