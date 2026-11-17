<script setup>
import { Link } from '@inertiajs/vue3';
import { MoreVertical, Pencil, Copy, Trash2, ExternalLink } from 'lucide-vue-next';

defineProps({
    product: { type: Object, required: true },
    open: { type: Boolean, default: false },
    actions: { type: Array, default: () => [] },
});

const emit = defineEmits(['toggle', 'close', 'duplicate', 'resubmit', 'delete']);
</script>

<template>
    <div class="relative shrink-0" :data-product-menu="product.id">
        <button
            type="button"
            class="flex h-7 w-7 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
            aria-label="Abrir menu"
            :aria-expanded="open"
            @click="emit('toggle')"
        >
            <MoreVertical class="h-3.5 w-3.5" />
        </button>
        <div
            v-show="open"
            class="absolute right-0 top-full z-50 mt-1 w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
        >
            <Link
                :href="`/produtos/${product.id}/edit`"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="emit('close')"
            >
                <Pencil class="h-4 w-4 shrink-0" />
                {{ product.is_coproduction ? 'Ver produto' : 'Editar' }}
            </Link>
            <button
                v-if="!product.is_coproduction"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="emit('duplicate')"
            >
                <Copy class="h-4 w-4 shrink-0" />
                Duplicar
            </button>
            <button
                v-if="product.approval?.can_resubmit && !product.is_coproduction"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-amber-800 hover:bg-amber-50 dark:text-amber-200 dark:hover:bg-amber-950/30"
                @click="emit('resubmit')"
            >
                Reenviar para análise
            </button>
            <button
                v-if="!product.is_coproduction"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                @click="emit('delete')"
            >
                <Trash2 class="h-4 w-4 shrink-0" />
                Excluir
            </button>
            <template v-for="(action, actIdx) in actions" :key="`plugin-${product.id}-${actIdx}`">
                <a
                    v-if="action.href"
                    :href="action.href"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="emit('close')"
                >
                    <ExternalLink v-if="!action.icon" class="h-4 w-4 shrink-0" />
                    <component v-else :is="action.icon" class="h-4 w-4 shrink-0" />
                    {{ action.label }}
                </a>
                <span v-else class="block border-t border-zinc-100 px-3 py-1 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    {{ action.label }}
                </span>
            </template>
        </div>
    </div>
</template>
