import { computed } from 'vue';

/**
 * O painel tem um único visual (Paynext DS); os templates de dashboard foram removidos.
 * A interface é mantida para os componentes que ainda consultam estas flags.
 */
export function useSellerDashboardTemplate() {
    const templateId = computed(() => 'default');
    const no = computed(() => false);

    return {
        templateId,
        isDefault: computed(() => true),
        isAurora: no,
        isKawaii: no,
        isPrime: no,
        isStudio: no,
        isThemedShell: no,
        themePrefix: computed(() => null),
        pageWrapperClass: computed(() => 'space-y-6'),
    };
}
