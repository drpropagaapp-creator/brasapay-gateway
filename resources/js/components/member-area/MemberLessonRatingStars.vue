<script setup>
import { computed, ref } from 'vue';
import { Star } from 'lucide-vue-next';
import { rateMemberLesson } from '@/composables/useMemberAreaHref';

const props = defineProps({
    modelValue: { type: Number, default: null },
    url: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const hover = ref(0);
const saving = ref(false);
const error = ref('');

const stars = [1, 2, 3, 4, 5];
const current = computed(() => {
    const n = Number(props.modelValue);
    return Number.isInteger(n) && n >= 1 && n <= 5 ? n : null;
});
const preview = computed(() => hover.value || current.value || 0);
const heading = computed(() => (current.value ? 'Sua avaliação' : 'Avalie esta aula'));

async function select(value) {
    if (saving.value || !props.url || value === current.value) return;
    const previous = current.value;
    emit('update:modelValue', value);
    saving.value = true;
    error.value = '';
    try {
        await rateMemberLesson(props.url, value);
    } catch (e) {
        emit('update:modelValue', previous);
        error.value = e?.response?.data?.errors?.rating?.[0]
            || e?.response?.data?.message
            || 'Não foi possível salvar sua avaliação.';
    } finally {
        saving.value = false;
    }
}

function starLabel(value) {
    return value === 1 ? '1 estrela' : `${value} estrelas`;
}
</script>

<template>
    <div class="xl:max-w-4xl">
        <p class="text-sm font-medium text-zinc-300">{{ heading }}</p>
        <div
            class="mt-1.5 flex items-center gap-0.5"
            role="radiogroup"
            aria-label="Avaliação da aula de 1 a 5 estrelas"
        >
            <button
                v-for="value in stars"
                :key="value"
                type="button"
                role="radio"
                class="rounded-md p-0.5 text-zinc-500 transition hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/70 disabled:opacity-60"
                :class="value <= preview ? 'text-amber-400' : 'text-zinc-600'"
                :aria-checked="current === value"
                :aria-label="starLabel(value)"
                :disabled="saving"
                @mouseenter="hover = value"
                @mouseleave="hover = 0"
                @focus="hover = value"
                @blur="hover = 0"
                @click="select(value)"
            >
                <Star
                    class="h-7 w-7"
                    :class="value <= preview ? 'fill-amber-400' : 'fill-transparent'"
                />
            </button>
        </div>
        <p v-if="current" class="mt-1 text-xs text-zinc-500">Clique nas estrelas para alterar.</p>
        <p v-if="error" class="mt-1 text-xs text-red-300">{{ error }}</p>
    </div>
</template>
