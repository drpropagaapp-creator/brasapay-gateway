<script setup>
import { computed, inject } from 'vue';

const custom = inject('dashboardCustomPeriod', null);

const visible = computed(() => custom?.period?.value === 'personalizado');
const startLabel = computed(() => custom?.startLabel?.value ?? 'Data inicial');
const endLabel = computed(() => custom?.endLabel?.value ?? 'Data final');
const applyLabel = computed(() => custom?.applyLabel?.value ?? 'Aplicar');

const fromDate = computed({
    get: () => custom?.from?.value ?? '',
    set: (value) => {
        if (custom?.from) {
            custom.from.value = value;
        }
    },
});

const toDate = computed({
    get: () => custom?.to?.value ?? '',
    set: (value) => {
        if (custom?.to) {
            custom.to.value = value;
        }
    },
});

function apply() {
    custom?.apply?.();
}
</script>

<template>
    <form v-if="visible" class="pn-glass pn-anim flex flex-wrap items-end gap-4 p-5" @submit.prevent="apply">
        <label class="pn-field">
            <span class="pn-field__label">{{ startLabel }}</span>
            <span class="pn-field__box"><input v-model="fromDate" type="date" /></span>
        </label>
        <label class="pn-field">
            <span class="pn-field__label">{{ endLabel }}</span>
            <span class="pn-field__box"><input v-model="toDate" type="date" /></span>
        </label>
        <button type="submit" class="pn-btn pn-btn--primary pn-keep-shape">{{ applyLabel }}</button>
    </form>
</template>
