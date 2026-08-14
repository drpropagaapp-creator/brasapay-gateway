<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';

/**
 * Player VTurb (converteai.net) no checkout.
 *
 * Segurança: o código colado pelo vendedor NUNCA é executado diretamente.
 * O componente extrai apenas (1) o placeholder do player (div/vturb-smartplayer,
 * só atributos id e style) e (2) URLs de script hospedadas em converteai.net.
 * Qualquer outro conteúdo/JS inline é descartado.
 */
const props = defineProps({
    embedCode: { type: String, default: '' },
});

const container = ref(null);
const loadedScriptSrcs = [];

const ALLOWED_SCRIPT_RE = /^https:\/\/([a-z0-9-]+\.)*converteai\.net\/[^\s"']*$/i;
const SCRIPT_URL_EXTRACT_RE = /https:\/\/[a-z0-9.-]*converteai\.net\/[^\s"'<>)]+\.js[^\s"'<>)]*/gi;
const ALLOWED_PLACEHOLDER_TAGS = new Set(['div', 'vturb-smartplayer']);

const parsed = computed(() => {
    const code = String(props.embedCode || '').trim();
    if (!code || typeof window === 'undefined') {
        return null;
    }

    let doc;
    try {
        doc = new DOMParser().parseFromString(code, 'text/html');
    } catch (_) {
        return null;
    }

    // Placeholders permitidos (só id + style são copiados)
    const placeholders = [];
    doc.body.querySelectorAll('div[id], vturb-smartplayer[id]').forEach((el) => {
        const tag = el.tagName.toLowerCase();
        if (!ALLOWED_PLACEHOLDER_TAGS.has(tag)) return;
        const id = el.getAttribute('id') || '';
        if (!/^[\w-]+$/.test(id)) return;
        const style = (el.getAttribute('style') || '').replace(/expression\s*\(|javascript\s*:/gi, '');
        placeholders.push({ tag, id, style });
    });

    // URLs de script do player (src explícito OU dentro do JS inline do embed)
    const scriptSrcs = new Set();
    doc.querySelectorAll('script[src]').forEach((s) => {
        const src = s.getAttribute('src') || '';
        if (ALLOWED_SCRIPT_RE.test(src)) scriptSrcs.add(src);
    });
    const inlineMatches = code.match(SCRIPT_URL_EXTRACT_RE) || [];
    inlineMatches.forEach((url) => {
        if (ALLOWED_SCRIPT_RE.test(url)) scriptSrcs.add(url);
    });

    if (!placeholders.length || !scriptSrcs.size) {
        return null;
    }

    return { placeholders, scriptSrcs: [...scriptSrcs] };
});

function mount() {
    if (!container.value || !parsed.value) return;

    container.value.innerHTML = '';
    parsed.value.placeholders.forEach(({ tag, id, style }) => {
        const el = document.createElement(tag);
        el.id = id;
        if (style) el.setAttribute('style', style);
        container.value.appendChild(el);
    });

    parsed.value.scriptSrcs.forEach((src) => {
        if (document.querySelector(`script[src="${CSS.escape(src)}"]`)) return;
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        document.head.appendChild(script);
        loadedScriptSrcs.push(src);
    });
}

onMounted(mount);
watch(parsed, () => mount());

onBeforeUnmount(() => {
    if (container.value) container.value.innerHTML = '';
});
</script>

<template>
    <div v-if="parsed" class="mb-8" data-checkout="vturb">
        <div ref="container" class="overflow-hidden rounded-2xl shadow-xl [&>*]:!mx-auto [&>*]:!w-full" />
    </div>
</template>
