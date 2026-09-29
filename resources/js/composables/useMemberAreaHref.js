import axios from 'axios';
import { resolveMemberAreaHref } from '@/utils/memberAreaHref';

export function usesMemberAreaPathPrefix(baseUrl = '') {
    if (typeof window !== 'undefined') {
        return window.location.pathname.startsWith('/m/');
    }

    return Boolean(baseUrl && String(baseUrl).includes('/m/'));
}

export function memberAreaHref(path, { slug, baseUrl = '' } = {}) {
    return resolveMemberAreaHref(path, {
        usesPathPrefix: usesMemberAreaPathPrefix(baseUrl),
        basePath: slug ? `/m/${slug}` : '',
        baseUrl: baseUrl || '',
    });
}

export function useMemberAreaHref(slug, baseUrl = '') {
    function href(path) {
        return memberAreaHref(path, { slug, baseUrl });
    }

    return { href };
}

/** Marca aula concluída sem visita Inertia — evita redirect()->back() para a tela anterior. */
export async function completeMemberLesson(url) {
    const { data } = await axios.post(url, {}, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    return data;
}
