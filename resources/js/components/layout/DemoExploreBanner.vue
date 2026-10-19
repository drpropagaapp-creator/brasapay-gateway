<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Sparkles, Sun } from 'lucide-vue-next';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';

const page = usePage();
const enabled = computed(() => !!page.props.demo_mode?.enabled);
</script>

<template>
    <div v-if="enabled" class="pn-glass pn-anim flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between" role="region" aria-label="Explorar visual do painel">
        <div class="flex items-start gap-3">
            <span class="pn-kpi__icon shrink-0" style="background: var(--lime-300); color: var(--ink-900); border-color: var(--lime-300)">
                <Sparkles class="h-5 w-5" weight="fill" />
            </span>
            <div>
                <p class="text-sm font-normal">Explore o visual do painel</p>
                <p class="mt-0.5 text-xs font-light" style="color: var(--text-secondary)">
                    Alterne entre o vidro escuro e o modo claro pelo botão
                    <Sun class="inline h-3.5 w-3.5 align-text-bottom" /> no topo da tela.
                </p>
            </div>
        </div>
        <ThemeToggler />
    </div>
</template>
