<script setup>
import { X } from 'lucide-vue-next';

/** Faixa de aviso do painel no padrão Paynext: vidro, ícone circular no tom do status, ações em pílula. */
defineProps({
    tone: { type: String, default: 'info' }, // info | warning | error | brand
    icon: { type: [Object, Function], default: null },
    title: { type: String, default: '' },
    text: { type: String, default: '' },
    dismissible: { type: Boolean, default: false },
    role: { type: String, default: 'status' },
});

const emit = defineEmits(['dismiss']);
</script>

<template>
    <div class="pn-banner" :class="`pn-banner--${tone}`" :role="role">
        <span v-if="icon" class="pn-banner__icon"><component :is="icon" class="h-5 w-5" weight="fill" /></span>
        <div class="min-w-0 flex-1">
            <p v-if="title" class="pn-banner__title">{{ title }}</p>
            <div v-if="text || $slots.default" class="pn-banner__text">
                <slot>{{ text }}</slot>
            </div>
        </div>
        <div v-if="$slots.actions" class="pn-banner__actions flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="pn-iconbtn pn-keep-shape shrink-0"
            style="width: 34px; height: 34px"
            aria-label="Fechar aviso"
            @click="emit('dismiss')"
        >
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
