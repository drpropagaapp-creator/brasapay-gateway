import { computed } from 'vue';

/**
 * As telas de autenticação têm um único layout (login imersivo do Paynext DS);
 * as variantes Spotlight e Immersive foram removidas. Interface mantida para quem consulta.
 */
export function useLoginTemplate() {
    const no = computed(() => false);

    return {
        templateId: computed(() => 'default'),
        isDefault: computed(() => true),
        isSpotlight: no,
        isImmersive: no,
        isModernLogin: no,
    };
}
