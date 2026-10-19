<script setup>
import { Check } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

/** Checkbox do Paynext DS: quadrado arredondado de vidro, lime com brilho quando marcado. */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    class: { type: [String, Object, Array], default: '' },
});

const emit = defineEmits(['update:modelValue']);

function toggle() {
    if (props.disabled) return;
    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <label
        :class="[
            'inline-flex w-auto max-w-full cursor-pointer items-center gap-3 text-sm font-light',
            disabled && 'cursor-not-allowed opacity-60',
            cn(props.class),
        ]"
        style="color: var(--text-primary)"
        @click="toggle"
    >
        <span
            role="checkbox"
            class="pn-check"
            :class="disabled && 'pointer-events-none'"
            :aria-checked="modelValue ? 'true' : 'false'"
            :aria-label="label || (modelValue ? 'Marcado' : 'Desmarcado')"
            tabindex="0"
            @keydown.enter.prevent="toggle"
            @keydown.space.prevent="toggle"
        >
            <Check v-if="modelValue" class="h-3.5 w-3.5" weight="fill" />
        </span>
        <span v-if="label" class="select-none">{{ label }}</span>
        <slot v-else />
    </label>
</template>
