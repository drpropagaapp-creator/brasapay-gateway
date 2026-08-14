import { computed } from 'vue';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const defaultBtnSecondary =
    'inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700';

const defaultIconBtn =
    'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200';

export function usePanelThemeClasses() {
    const { isAurora, isKawaii, isPrime, isStudio, isThemedShell, themePrefix } = useSellerDashboardTemplate();

    function themed(className) {
        return computed(() => {
            if (themePrefix.value) return `${themePrefix.value}-${className}`;
            return '';
        });
    }

    const btnSecondary = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-btn-secondary`;
        return defaultBtnSecondary;
    });

    const iconBtn = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-icon-btn`;
        return defaultIconBtn;
    });

    const tablePanel = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-table-panel`;
        return 'overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 dark:bg-zinc-800/80';
    });

    const statCard = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-stat-card`;
        return 'rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50';
    });

    const statCardLabel = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-stat-card-label`;
        return 'flex items-center gap-2 text-zinc-600 dark:text-zinc-400';
    });

    const statCardValue = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-stat-card-value`;
        return 'mt-2 text-xl font-bold text-zinc-900 dark:text-white';
    });

    const pageClass = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-page`;
        return 'space-y-6';
    });

    const stackClass = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-stack`;
        return 'space-y-4';
    });

    const filterPanelClass = computed(() => {
        if (isAurora.value || isPrime.value || isStudio.value) return '';
        if (isKawaii.value) return 'kawaii-filter-panel';
        return 'rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-800/40';
    });

    const innerPanelClass = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-inner-panel`;
        return 'rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50';
    });

    const mobileCardClass = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-mobile-card`;
        return 'rounded-xl border border-zinc-200 bg-white shadow-sm hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800';
    });

    const subnavClass = computed(() => {
        if (themePrefix.value) return `${themePrefix.value}-subnav`;
        return '';
    });

    function subnavItemClasses(active, defaultActiveClass, defaultInactiveClass) {
        if (themePrefix.value) {
            return [
                `${themePrefix.value}-subnav-item`,
                active && `${themePrefix.value}-subnav-item-active`,
            ];
        }
        return [
            defaultActiveClass ?? 'rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
            active ? (defaultInactiveClass?.active ?? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]') : (defaultInactiveClass?.inactive ?? 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'),
        ];
    }

    return {
        isAurora,
        isKawaii,
        isPrime,
        isStudio,
        isThemedShell,
        themePrefix,
        themed,
        btnSecondary,
        iconBtn,
        tablePanel,
        statCard,
        statCardLabel,
        statCardValue,
        pageClass,
        stackClass,
        filterPanelClass,
        innerPanelClass,
        mobileCardClass,
        subnavClass,
        subnavItemClasses,
    };
}
