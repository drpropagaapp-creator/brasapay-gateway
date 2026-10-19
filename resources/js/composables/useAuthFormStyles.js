import { computed } from 'vue';
import { useAuthBranding } from '@/composables/useAuthBranding';

/** Classes dos formulários de autenticação no padrão Paynext DS (campos em pílula de vidro, ação lime). */
const CLASSES = {
    inputClass:
        'mt-2 block h-[50px] w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-5 text-sm font-light text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] transition focus:border-[var(--border-brand)] focus:outline-none focus:ring-4 focus:ring-[var(--focus-ring)]',
    labelClass: 'block text-[13px] font-normal text-[var(--text-secondary)]',
    linkClass: 'font-normal text-[var(--text-brand)] underline-offset-4 hover:underline',
    mutedTextClass: 'text-sm font-light text-[var(--text-secondary)]',
    alertErrorClass: 'rounded-[20px] bg-[var(--status-warning-bg)] px-4 py-3 text-sm font-light text-[var(--status-warning-fg)]',
    submitButtonClass: 'pn-btn pn-btn--primary pn-btn--block pn-keep-shape',
    checkboxClass: 'h-[18px] w-[18px] rounded-md',
    demoDividerClass: 'border-[var(--border-default)]',
};

export function useAuthFormStyles() {
    const { primary } = useAuthBranding();
    const fixed = (key) => computed(() => CLASSES[key]);
    const no = computed(() => false);

    return {
        authSkin: computed(() => 'default'),
        isSpotlight: no,
        isImmersive: no,
        primary,
        inputClass: fixed('inputClass'),
        labelClass: fixed('labelClass'),
        linkClass: fixed('linkClass'),
        mutedTextClass: fixed('mutedTextClass'),
        alertErrorClass: fixed('alertErrorClass'),
        submitButtonClass: fixed('submitButtonClass'),
        checkboxClass: fixed('checkboxClass'),
        demoDividerClass: fixed('demoDividerClass'),
    };
}
