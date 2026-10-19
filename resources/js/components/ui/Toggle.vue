<script setup>
/** Toggle do Paynext DS: trilho em pílula, lime com brilho quando ligado, botão com mola. */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    name: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

function toggle() {
    if (props.disabled) return;
    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <div class="flex items-center gap-3" :class="{ 'cursor-not-allowed opacity-60': disabled }">
        <button
            type="button"
            role="switch"
            class="pn-toggle pn-keep-shape"
            :disabled="disabled"
            :aria-checked="modelValue ? 'true' : 'false'"
            :aria-label="label || (modelValue ? 'Ativo' : 'Inativo')"
            @click="toggle"
        />
        <label v-if="label" class="cursor-pointer text-sm font-normal" style="color: var(--text-primary)" @click="toggle">
            {{ label }}
        </label>
    </div>
</template>
