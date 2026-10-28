import { ref } from 'vue';

/**
 * Grupos recolhíveis da sidebar. Por padrão só abre o grupo da página atual; o que a pessoa
 * abrir ou fechar fica lembrado neste navegador. Grupo sem título (atalhos do topo) fica sempre aberto.
 */
export function useNavGroups(storageKey) {
    const read = () => {
        try {
            return JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
        } catch {
            return {};
        }
    };
    const state = ref(read());

    function hasActive(group, isActive) {
        return group.items.some((i) => i.href && isActive(i.href));
    }

    function isOpen(group, isActive) {
        if (!group.label) return true;
        const saved = state.value[group.id];
        return saved === undefined ? hasActive(group, isActive) : saved || hasActive(group, isActive);
    }

    function toggle(group, isActive) {
        state.value = { ...state.value, [group.id]: !isOpen(group, isActive) };
        try {
            localStorage.setItem(storageKey, JSON.stringify(state.value));
        } catch {
            // navegação privada sem storage: só não lembra
        }
    }

    return { isOpen, toggle, hasActive };
}
