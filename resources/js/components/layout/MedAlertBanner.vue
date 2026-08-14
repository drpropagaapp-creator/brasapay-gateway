<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ShieldAlert, ChevronRight } from 'lucide-vue-next';

const page = usePage();

const openCount = computed(() => Number(page.props.med_open_count ?? 0));

// Não repete o alerta dentro da própria área de disputas.
const onDisputesPage = computed(() => (page.url.split('?')[0] || '').startsWith('/vendas/disputas'));

const show = computed(() => openCount.value > 0 && !onDisputesPage.value);
</script>

<template>
    <div
        v-if="show"
        class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900/60 dark:bg-red-950/40"
        role="alert"
    >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-2.5">
                <ShieldAlert class="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" aria-hidden="true" />
                <div class="text-sm text-red-900 dark:text-red-100">
                    <p class="font-semibold">
                        {{ openCount === 1 ? 'Você tem 1 disputa MED aberta' : `Você tem ${openCount} disputas MED abertas` }}
                    </p>
                    <p class="mt-0.5 text-red-800/90 dark:text-red-200/80">
                        Um cliente contestou o pagamento PIX de uma venda sua junto ao banco (MED — Mecanismo Especial de Devolução).
                        O valor da(s) venda(s) contestada(s) está retido na sua carteira até a resolução. Envie sua defesa com comprovantes de entrega.
                    </p>
                </div>
            </div>
            <Link
                href="/vendas/disputas"
                class="inline-flex shrink-0 items-center gap-1 self-start rounded-lg bg-red-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-red-700 sm:self-center"
            >
                Ver disputas
                <ChevronRight class="h-4 w-4" aria-hidden="true" />
            </Link>
        </div>
    </div>
</template>
