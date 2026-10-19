<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { formatCompactCurrency } from '@/lib/utils';
import { navPrefetch } from '@/composables/useAppSidebarNav';
import PnMeter from '@/components/paynext/PnMeter.vue';
import { useSidebar } from '@/composables/useSidebar';

const props = defineProps({
    variant: { type: String, default: 'header' }, // 'header' | 'sidebar' | 'dashboard'
});

const { closeMobileSidebarIfOpen, isMobile } = useSidebar();

const page = usePage();
const progress = computed(() => page.props.achievementsProgress ?? null);

const iconUrl = computed(() => {
    if (!progress.value) return null;
    const curr = progress.value.current_achievement;
    const achievements = progress.value.achievements ?? [];
    const first = achievements[0];
    if (curr?.image) return curr.image;
    if (first?.image) return first.image;
    return null;
});

const isLocked = computed(() => {
    if (!progress.value) return true;
    return progress.value.current_achievement === null;
});

const progressPercent = computed(() => {
    return progress.value?.progress_percent ?? 0;
});

const nextLabel = computed(() => {
    const next = progress.value?.next_achievement;
    if (!next) return null;
    return formatCompactCurrency(next.threshold);
});

const remainingLabel = computed(() => {
    const remaining = progress.value?.remaining;
    if (remaining == null || remaining <= 0) return null;
    if (!progress.value?.next_achievement) return null;
    return formatCompactCurrency(remaining);
});

const totalLabel = computed(() => {
    const total = progress.value?.total_valid_sales ?? 0;
    return formatCompactCurrency(total);
});
</script>

<template>
    <Link
        v-if="progress"
        href="/conquistas"
        :prefetch="navPrefetch(isMobile)"
        class="pn-conquistas group flex shrink-0 cursor-pointer touch-manipulation items-center gap-3 no-underline hover:no-underline"
        :class="{
            'pn-conquistas--header': props.variant === 'header',
            'pn-glass w-full px-4 py-3': props.variant === 'dashboard',
            'pn-conquistas--sidebar w-full flex-col items-stretch gap-2.5': props.variant === 'sidebar',
        }"
        title="Conquistas"
        @click="closeMobileSidebarIfOpen"
    >
        <span
            class="pn-kpi__icon shrink-0 overflow-hidden"
            :class="{ 'opacity-60 grayscale': isLocked }"
            :style="props.variant === 'sidebar' ? 'width:48px;height:48px' : ''"
        >
            <img v-if="iconUrl" :src="iconUrl" alt="" class="h-7 w-7 object-contain" />
        </span>
        <div class="min-w-0 flex-1" :class="props.variant === 'header' ? 'hidden w-[140px] sm:block' : 'w-full'">
            <p class="pn-eyebrow mb-2">Faturamento</p>
            <PnMeter :value="progressPercent" :show-marker="props.variant !== 'header'" style="height: 8px" />
            <p class="mt-1.5 truncate text-[11px] font-light" style="color: var(--text-secondary)">
                {{ totalLabel }}<span v-if="nextLabel"> → {{ nextLabel }}</span>
            </p>
            <p v-if="remainingLabel && props.variant !== 'header'" class="truncate text-[10.5px] font-light" style="color: var(--text-tertiary)">
                Faltam {{ remainingLabel }}
            </p>
        </div>
    </Link>
</template>

<style scoped>
.pn-conquistas--header {
    height: 46px;
    padding: 0 16px 0 6px;
    border-radius: var(--radius-full);
    border: 1px solid var(--border-strong);
    background: var(--glass-bg);
    -webkit-backdrop-filter: var(--glass-blur);
    backdrop-filter: var(--glass-blur);
    transition: background var(--dur-fast);
}
.pn-conquistas--header:hover {
    background: var(--glass-bg-strong);
}
.pn-conquistas--header .pn-kpi__icon {
    width: 34px;
    height: 34px;
}
.pn-conquistas--sidebar {
    padding: 14px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-default);
    background: var(--bg-subtle);
}
</style>
