import { computed } from 'vue';

/**
 * Classes de superfície das páginas do painel no padrão Paynext DS.
 * Centraliza painéis, filtros, cartões, botões e sub-navegação usados por várias telas.
 */
const CLASSES = {
    btnSecondary: 'pn-btn pn-btn--secondary pn-btn--sm pn-keep-shape',
    iconBtn:
        'rounded-full text-[var(--text-secondary)] transition hover:bg-[var(--lime-300)] hover:text-[var(--ink-900)] hover:shadow-[0_0_20px_rgba(225,251,77,.4)]',
    tablePanel: 'pn-glass overflow-hidden',
    statCard: 'pn-glass pn-glass--lift p-5',
    statCardLabel: 'flex items-center gap-2 text-[13.5px] font-light text-[var(--text-secondary)]',
    statCardValue: 'mt-3 font-[family-name:var(--font-display)] text-[28px] leading-[34px] font-normal tracking-[-0.025em] text-[var(--text-primary)]',
    pageClass: 'space-y-6',
    stackClass: 'space-y-4',
    filterPanelClass: 'pn-glass p-5',
    innerPanelClass: 'pn-glass p-6',
    mobileCardClass: 'pn-glass pn-glass--lift',
    subnavClass: 'pn-tabs',
};

export function usePanelThemeClasses() {
    const fixed = (key) => computed(() => CLASSES[key]);
    const no = computed(() => false);

    function themed() {
        return computed(() => '');
    }

    function subnavItemClasses(active) {
        return ['pn-tab pn-keep-shape inline-flex items-center gap-2', active ? 'pn-tab--active' : ''];
    }

    return {
        isAurora: no,
        isKawaii: no,
        isPrime: no,
        isStudio: no,
        isThemedShell: no,
        themePrefix: computed(() => null),
        themed,
        btnSecondary: fixed('btnSecondary'),
        iconBtn: fixed('iconBtn'),
        tablePanel: fixed('tablePanel'),
        statCard: fixed('statCard'),
        statCardLabel: fixed('statCardLabel'),
        statCardValue: fixed('statCardValue'),
        pageClass: fixed('pageClass'),
        stackClass: fixed('stackClass'),
        filterPanelClass: fixed('filterPanelClass'),
        innerPanelClass: fixed('innerPanelClass'),
        mobileCardClass: fixed('mobileCardClass'),
        subnavClass: fixed('subnavClass'),
        subnavItemClasses,
    };
}
