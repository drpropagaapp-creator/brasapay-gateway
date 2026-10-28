<script setup>
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: null });

defineProps({ ok: { type: Boolean, default: false } });
</script>

<template>
    <Head title="Descadastro" />
    <div class="flex min-h-screen items-center justify-center bg-zinc-50 p-6 dark:bg-zinc-950">
        <div class="max-w-md rounded-2xl border border-zinc-200 bg-white p-6 text-center dark:border-zinc-800 dark:bg-zinc-900">
            <template v-if="ok">
                <h1 class="text-lg font-semibold text-zinc-900 dark:text-white">Pronto, você foi descadastrado</h1>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Você não vai mais receber lembretes de compra deste vendedor por e-mail.</p>
            </template>
            <template v-else>
                <h1 class="text-lg font-semibold text-zinc-900 dark:text-white">Link inválido</h1>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Não conseguimos identificar o e-mail deste link.</p>
            </template>
        </div>
    </div>
</template>
