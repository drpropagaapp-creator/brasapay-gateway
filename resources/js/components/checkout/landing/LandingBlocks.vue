<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CheckoutYoutube from '@/components/checkout/CheckoutYoutube.vue';
import CheckoutVturb from '@/components/checkout/CheckoutVturb.vue';

/**
 * Renderiza os blocos da landing do checkout. Conteúdo vindo do servidor já é
 * sanitizado (HTML, URLs, CSS escopado). Ações de CTA são declaradas no
 * schema — nenhum JavaScript do seller roda aqui.
 */
const props = defineProps({
    blocks: { type: Array, default: () => [] },
    vturbEmbed: { type: String, default: '' },
    productName: { type: String, default: '' },
});

const now = ref(Date.now());
let ticker = null;
const evergreenStart = ref(Date.now());

onMounted(() => {
    try {
        const key = 'lpb-countdown-start';
        const saved = Number(sessionStorage.getItem(key));
        if (saved > 0) evergreenStart.value = saved;
        else sessionStorage.setItem(key, String(evergreenStart.value));
    } catch (_) {}
    if (props.blocks.some((b) => b.type === 'countdown')) {
        ticker = setInterval(() => (now.value = Date.now()), 1000);
    }
});
onBeforeUnmount(() => clearInterval(ticker));

function blockStyle(block) {
    const s = block.style || {};
    const pad = Number.isFinite(s.pad_y) ? s.pad_y : 24;
    const padMobile = Number.isFinite(s.pad_y_mobile) ? s.pad_y_mobile : pad;
    const scale = (s.font_scale || 100) / 100;
    const scaleMobile = ((s.font_scale_mobile ?? s.font_scale) || 100) / 100;
    return {
        '--lpb-pad': `${pad}px`,
        '--lpb-pad-m': `${padMobile}px`,
        '--lpb-scale': scale,
        '--lpb-scale-m': scaleMobile,
        '--lpb-radius': `${s.radius || 0}px`,
        background: s.bg || undefined,
        color: s.text || undefined,
        textAlign: s.align || 'center',
    };
}

function blockClass(block) {
    const s = block.style || {};
    return [
        'lpb',
        `lpb-${block.id}`,
        `lpb-w-${s.width || 'normal'}`,
        { 'lpb-hide-m': s.hide_mobile, 'lpb-hide-d': s.hide_desktop, 'lpb-has-bg': !!s.bg },
    ];
}

/** Escopo de CSS para a prévia do Builder (no público o servidor já entrega `css_scoped`). */
function previewScopedCss(block) {
    if (typeof block.props?.css_scoped === 'string') return block.props.css_scoped;
    // Mesmas regras do servidor (só prévia): sem aspas/escapes, só descendentes do bloco.
    const raw = String(block.props?.css || '').replace(/<\/?|["'\\]/g, '').replace(/@import[^;]*;?/gi, '');
    let depth = 0;
    for (const ch of raw) {
        if (ch === '{') depth += 1;
        else if (ch === '}' && --depth < 0) return '';
    }
    if (depth !== 0) return '';
    const scope = `.lpb-${block.id}`;
    return raw.replace(/([^{}@]+)\{/g, (m, sel) => {
        const scoped = sel
            .split(',')
            .map((x) => x.trim())
            .filter((x) => x && !/^(html|body|:root)\b/i.test(x) && !/^[~+>]/.test(x) && !x.includes('&'))
            .map((x) => `${scope} ${x}`)
            .join(',');
        return `${scoped || `${scope} .lpb-noop`}{`;
    }).replace(/position\s*:\s*(fixed|sticky)/gi, 'position:static');
}

function ctaHref(action, target) {
    if (action === 'url' && /^https?:\/\//i.test(target || '')) return target;
    if (action === 'scroll_block' && target) return `#lpb-${target}`;
    return '#checkout-purchase-area';
}

function onCta(event, action, target) {
    if (action === 'url') return;
    event.preventDefault();
    const el = document.getElementById(action === 'scroll_block' && target ? `lpb-${target}` : 'checkout-purchase-area');
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function lines(text) {
    return String(text || '').split('\n').map((l) => l.trim()).filter(Boolean);
}

function countdownParts(block) {
    const p = block.props || {};
    let end;
    if (p.mode === 'fixed' && p.ends_at) end = new Date(p.ends_at).getTime();
    else end = evergreenStart.value + (Number(p.minutes) || 15) * 60000;
    const diff = Math.max(0, end - now.value);
    const d = Math.floor(diff / 86400000);
    const h = Math.floor((diff % 86400000) / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    return { d, h, m, s, done: diff === 0 };
}

const pad2 = (n) => String(n).padStart(2, '0');
const visibleBlocks = computed(() => props.blocks.filter((b) => b && b.type !== 'checkout'));
</script>

<template>
    <div class="lpb-root">
        <section
            v-for="block in visibleBlocks"
            :id="`lpb-${block.id}`"
            :key="block.id"
            :class="blockClass(block)"
            :style="blockStyle(block)"
            :data-block-type="block.type"
        >
            <div class="lpb-inner">
                <!-- Hero -->
                <div v-if="block.type === 'hero'" :class="block.props.layout === 'split' ? 'lpb-hero-split' : 'lpb-hero'">
                    <div>
                        <h1 v-if="block.props.title" class="lpb-h1">{{ block.props.title }}</h1>
                        <p v-if="block.props.subtitle" class="lpb-lead">{{ block.props.subtitle }}</p>
                        <a
                            v-if="block.props.button_text"
                            class="lpb-btn"
                            :href="ctaHref(block.props.button_action, block.props.button_target)"
                            :rel="block.props.button_action === 'url' ? 'noopener noreferrer' : undefined"
                            @click="onCta($event, block.props.button_action, block.props.button_target)"
                        >{{ block.props.button_text }}</a>
                    </div>
                    <img v-if="block.props.image" :src="block.props.image" :alt="block.props.title || productName" class="lpb-img" loading="lazy" decoding="async" />
                </div>

                <template v-else-if="block.type === 'heading'">
                    <h1 v-if="block.props.level === 'h1'" class="lpb-h1">{{ block.props.text }}</h1>
                    <h3 v-else-if="block.props.level === 'h3'" class="lpb-h3">{{ block.props.text }}</h3>
                    <h2 v-else class="lpb-h2">{{ block.props.text }}</h2>
                </template>

                <p v-else-if="block.type === 'subheading'" class="lpb-lead">{{ block.props.text }}</p>

                <!-- eslint-disable-next-line vue/no-v-html -->
                <div v-else-if="block.type === 'text'" class="lpb-rich" v-html="block.props.html" />

                <template v-else-if="block.type === 'image'">
                    <a v-if="block.props.link" :href="block.props.link" rel="noopener noreferrer" target="_blank">
                        <img :src="block.props.url" :alt="block.props.alt" class="lpb-img" loading="lazy" decoding="async" />
                    </a>
                    <img v-else-if="block.props.url" :src="block.props.url" :alt="block.props.alt" class="lpb-img" loading="lazy" decoding="async" />
                </template>

                <div v-else-if="block.type === 'gallery'" class="lpb-gallery" :style="{ '--cols': block.props.columns || 3 }">
                    <img v-for="(it, i) in block.props.items" :key="i" :src="it.url" :alt="it.alt" class="lpb-img" loading="lazy" decoding="async" />
                </div>

                <template v-else-if="block.type === 'video'">
                    <CheckoutVturb v-if="block.props.provider === 'vturb' && vturbEmbed" :embed-code="vturbEmbed" />
                    <CheckoutYoutube v-else-if="block.props.url" :url="block.props.url" />
                </template>

                <a
                    v-else-if="block.type === 'button'"
                    :class="['lpb-btn', { 'lpb-btn-outline': block.props.variant === 'outline' }]"
                    :href="ctaHref(block.props.action, block.props.target)"
                    :rel="block.props.action === 'url' ? 'noopener noreferrer' : undefined"
                    @click="onCta($event, block.props.action, block.props.target)"
                >{{ block.props.text }}</a>

                <div v-else-if="block.type === 'benefits'">
                    <h2 v-if="block.props.title" class="lpb-h2">{{ block.props.title }}</h2>
                    <ul class="lpb-checklist">
                        <li v-for="(it, i) in block.props.items" :key="i">{{ it.text }}</li>
                    </ul>
                </div>

                <div v-else-if="['features', 'cards', 'bonus'].includes(block.type)">
                    <h2 v-if="block.props.title" class="lpb-h2">{{ block.props.title }}</h2>
                    <div class="lpb-grid">
                        <article v-for="(it, i) in block.props.items" :key="i" class="lpb-card">
                            <img v-if="it.image" :src="it.image" :alt="it.title" class="lpb-card-img" loading="lazy" decoding="async" />
                            <h3 class="lpb-h3">{{ it.title }}</h3>
                            <p v-for="(l, j) in lines(it.text)" :key="j" class="lpb-p">{{ l }}</p>
                            <p v-if="it.value" class="lpb-bonus-value">{{ it.value }}</p>
                        </article>
                    </div>
                </div>

                <div v-else-if="block.type === 'testimonials'">
                    <h2 v-if="block.props.title" class="lpb-h2">{{ block.props.title }}</h2>
                    <div class="lpb-grid">
                        <figure v-for="(it, i) in block.props.items" :key="i" class="lpb-card">
                            <p v-if="it.rating" class="lpb-stars" :aria-label="`${it.rating} de 5 estrelas`">{{ '★'.repeat(it.rating) }}</p>
                            <blockquote class="lpb-p">“{{ it.text }}”</blockquote>
                            <figcaption class="lpb-author">
                                <img v-if="it.image" :src="it.image" :alt="it.name" class="lpb-avatar" loading="lazy" />
                                {{ it.name }}
                            </figcaption>
                        </figure>
                    </div>
                </div>

                <div v-else-if="block.type === 'faq'">
                    <h2 v-if="block.props.title" class="lpb-h2">{{ block.props.title }}</h2>
                    <details v-for="(it, i) in block.props.items" :key="i" class="lpb-faq">
                        <summary>{{ it.q }}</summary>
                        <p v-for="(l, j) in lines(it.a)" :key="j" class="lpb-p">{{ l }}</p>
                    </details>
                </div>

                <div v-else-if="block.type === 'plans'">
                    <h2 v-if="block.props.title" class="lpb-h2">{{ block.props.title }}</h2>
                    <div class="lpb-grid">
                        <article v-for="(it, i) in block.props.items" :key="i" :class="['lpb-card', { 'lpb-card-highlight': it.highlight }]">
                            <h3 class="lpb-h3">{{ it.name }}</h3>
                            <p class="lpb-price">{{ it.price }}</p>
                            <ul class="lpb-checklist">
                                <li v-for="(l, j) in lines(it.features)" :key="j">{{ l }}</li>
                            </ul>
                            <a v-if="it.button_text" class="lpb-btn" href="#checkout-purchase-area" @click="onCta($event, 'scroll_checkout')">{{ it.button_text }}</a>
                        </article>
                    </div>
                </div>

                <div v-else-if="block.type === 'pricing'" class="lpb-pricing">
                    <h2 v-if="block.props.title" class="lpb-h2">{{ block.props.title }}</h2>
                    <p v-if="block.props.old_price" class="lpb-old-price">{{ block.props.old_price }}</p>
                    <p class="lpb-price">{{ block.props.price }}</p>
                    <p v-if="block.props.note" class="lpb-p">{{ block.props.note }}</p>
                    <a v-if="block.props.button_text" class="lpb-btn" href="#checkout-purchase-area" @click="onCta($event, 'scroll_checkout')">{{ block.props.button_text }}</a>
                </div>

                <div v-else-if="block.type === 'countdown'" class="lpb-countdown">
                    <p v-if="block.props.title" class="lpb-h3">{{ block.props.title }}</p>
                    <div class="lpb-countdown-digits" role="timer">
                        <template v-for="(v, label) in { dias: countdownParts(block).d, horas: countdownParts(block).h, min: countdownParts(block).m, seg: countdownParts(block).s }" :key="label">
                            <span v-if="label !== 'dias' || v > 0" class="lpb-digit"><b>{{ pad2(v) }}</b><small>{{ label }}</small></span>
                        </template>
                    </div>
                </div>

                <div v-else-if="block.type === 'guarantee'" class="lpb-guarantee">
                    <img v-if="block.props.image" :src="block.props.image" alt="" class="lpb-seal" loading="lazy" />
                    <div v-else-if="block.props.days" class="lpb-seal-days"><b>{{ block.props.days }}</b><small>dias</small></div>
                    <div>
                        <h3 class="lpb-h3">{{ block.props.title }}</h3>
                        <p v-for="(l, j) in lines(block.props.text)" :key="j" class="lpb-p">{{ l }}</p>
                    </div>
                </div>

                <div v-else-if="block.type === 'logos'">
                    <p v-if="block.props.title" class="lpb-muted">{{ block.props.title }}</p>
                    <div class="lpb-logos">
                        <img v-for="(it, i) in block.props.items" :key="i" :src="it.url" :alt="it.alt" loading="lazy" decoding="async" />
                    </div>
                </div>

                <hr v-else-if="block.type === 'divider'" class="lpb-divider" />
                <div v-else-if="block.type === 'spacer'" :style="{ height: `${block.props.height || 32}px` }" aria-hidden="true" />

                <footer v-else-if="block.type === 'footer'" class="lpb-muted">
                    <p v-for="(l, j) in lines(block.props.text)" :key="j">{{ l }}</p>
                </footer>

                <template v-else-if="block.type === 'html'">
                    <component :is="'style'" v-if="previewScopedCss(block)">{{ previewScopedCss(block) }}</component>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div class="lpb-rich" v-html="block.props.html" />
                </template>
            </div>
        </section>
    </div>
</template>

<style>
.lpb {
    padding-block: var(--lpb-pad, 24px);
    border-radius: var(--lpb-radius, 0);
    font-size: calc(1rem * var(--lpb-scale, 1));
    /* Nada do bloco (nem fixed/absolute do HTML do seller) sai dele para cobrir o checkout. */
    contain: paint !important;
    isolation: isolate !important;
    position: relative !important;
}
.lpb-has-bg { padding-inline: 1.25rem; }
.lpb-inner { margin-inline: auto; max-width: 48rem; }
.lpb-w-narrow .lpb-inner { max-width: 36rem; }
.lpb-w-wide .lpb-inner { max-width: 64rem; }
.lpb-w-full .lpb-inner { max-width: none; }
@media (max-width: 640px) {
    .lpb { padding-block: var(--lpb-pad-m, 24px); font-size: calc(1rem * var(--lpb-scale-m, 1)); }
    .lpb-hide-m { display: none !important; }
}
@media (min-width: 641px) {
    .lpb-hide-d { display: none !important; }
}
.lpb-h1 { font-size: 2.2em; font-weight: 800; line-height: 1.15; letter-spacing: -0.02em; margin: 0 0 0.5em; }
.lpb-h2 { font-size: 1.6em; font-weight: 700; line-height: 1.2; margin: 0 0 0.75em; }
.lpb-h3 { font-size: 1.15em; font-weight: 700; margin: 0 0 0.4em; }
.lpb-lead { font-size: 1.15em; line-height: 1.6; opacity: 0.85; margin: 0 0 1.25em; }
.lpb-p { line-height: 1.6; margin: 0.35em 0; }
.lpb-muted { font-size: 0.875em; opacity: 0.7; }
.lpb-img { display: block; width: 100%; height: auto; border-radius: 1rem; margin-inline: auto; }
.lpb-btn {
    display: inline-block; margin-top: 0.5em; padding: 0.9em 2em; border-radius: 999px; font-weight: 700;
    background: var(--color-primary, #0a986e); color: #fff; text-decoration: none; transition: transform 0.15s ease;
}
.lpb-btn:hover { transform: translateY(-1px); }
.lpb-btn-outline { background: transparent; color: var(--color-primary, #0a986e); border: 2px solid currentColor; }
.lpb-hero-split { display: grid; gap: 2rem; align-items: center; text-align: left; }
@media (min-width: 768px) { .lpb-hero-split { grid-template-columns: 1fr 1fr; } }
.lpb-gallery { display: grid; gap: 0.75rem; grid-template-columns: repeat(var(--cols, 3), minmax(0, 1fr)); }
@media (max-width: 640px) { .lpb-gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.lpb-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); text-align: left; }
.lpb-card { border: 1px solid rgba(0, 0, 0, 0.08); border-radius: 1rem; padding: 1.25rem; background: rgba(255, 255, 255, 0.7); margin: 0; }
.lpb-card-highlight { border-color: var(--color-primary, #0a986e); box-shadow: 0 10px 30px -12px rgba(10, 152, 110, 0.45); }
.lpb-card-img { width: 100%; border-radius: 0.75rem; margin-bottom: 0.75rem; }
.lpb-checklist { list-style: none; padding: 0; margin: 0; text-align: left; display: grid; gap: 0.5em; }
.lpb-checklist li::before { content: '✓'; color: var(--color-primary, #0a986e); font-weight: 800; margin-right: 0.5em; }
.lpb-stars { color: #e8a914; letter-spacing: 2px; }
.lpb-author { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; margin-top: 0.75rem; }
.lpb-avatar { width: 2rem; height: 2rem; border-radius: 999px; object-fit: cover; }
.lpb-faq { border-bottom: 1px solid rgba(0, 0, 0, 0.08); padding: 0.9rem 0; text-align: left; }
.lpb-faq summary { cursor: pointer; font-weight: 600; }
.lpb-price { font-size: 2em; font-weight: 800; margin: 0.25em 0; }
.lpb-old-price { text-decoration: line-through; opacity: 0.6; margin: 0; }
.lpb-bonus-value { font-weight: 700; color: var(--color-primary, #0a986e); }
.lpb-countdown-digits { display: flex; justify-content: center; gap: 0.75rem; }
.lpb-digit { display: grid; min-width: 3.5rem; padding: 0.6rem; border-radius: 0.75rem; background: rgba(0, 0, 0, 0.06); }
.lpb-digit b { font-size: 1.6em; font-variant-numeric: tabular-nums; }
.lpb-digit small { font-size: 0.7em; text-transform: uppercase; opacity: 0.7; }
.lpb-guarantee { display: flex; align-items: center; gap: 1.25rem; text-align: left; }
.lpb-seal { width: 6rem; height: 6rem; object-fit: contain; }
.lpb-seal-days { display: grid; place-items: center; width: 6rem; height: 6rem; border-radius: 999px; border: 3px solid var(--color-primary, #0a986e); flex: none; }
.lpb-seal-days b { font-size: 1.8em; line-height: 1; }
.lpb-logos { display: flex; flex-wrap: wrap; justify-content: center; gap: 1.5rem; align-items: center; }
.lpb-logos img { max-height: 2.5rem; width: auto; opacity: 0.75; }
.lpb-divider { border: 0; border-top: 1px solid rgba(0, 0, 0, 0.1); }
.lpb-rich { text-align: left; line-height: 1.6; }
.lpb-rich img { max-width: 100%; height: auto; }
</style>
