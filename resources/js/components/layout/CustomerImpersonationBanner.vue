<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const impersonation = computed(() => page.props.customer_impersonation ?? null);

watch(
    () => impersonation.value?.active === true,
    (active) => {
        document.documentElement.style.paddingBottom = active ? '4.5rem' : '';
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    document.documentElement.style.paddingBottom = '';
});
</script>

<template>
    <div
        v-if="impersonation?.active"
        class="fixed inset-x-0 bottom-0 z-[80] border-t border-amber-600/30 bg-amber-500 px-4 py-2.5 text-amber-950 shadow-lg print:hidden"
        role="status"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="min-w-0 text-sm leading-snug">
                <span class="font-semibold">Acessando como cliente</span>
                <span v-if="impersonation.name"> — {{ impersonation.name }}</span>
                <span v-if="impersonation.email" class="opacity-80"> ({{ impersonation.email }})</span>
                <span class="mt-0.5 block text-xs sm:ml-2 sm:mt-0 sm:inline">Somente leitura. Nada é alterado na conta.</span>
            </p>
            <Link
                :href="impersonation.leave_url"
                method="post"
                as="button"
                class="inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-950 px-3 py-1.5 text-sm font-medium text-amber-50 hover:bg-amber-900"
            >
                Voltar ao admin
            </Link>
        </div>
    </div>
</template>
