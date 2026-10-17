<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const configuredIds = new Set();
let scriptInserted = false;
let lastSignature = '';

const BUYER_COMPONENTS = new Set([
    'Checkout/Show',
    'Checkout/Pix',
    'Checkout/Boleto',
    'Checkout/ThankYou',
    'Checkout/Upsell',
    'Checkout/Downsell',
    'Checkout/OpenFinance',
]);

function isBuyerSurface() {
    const name = String(page.component || '');
    if (BUYER_COMPONENTS.has(name) || name.startsWith('MemberArea')) {
        return true;
    }
    const path = String(page.url || '').split('?')[0];

    return path.startsWith('/c/')
        || path.startsWith('/checkout')
        || path.startsWith('/api-checkout')
        || path.startsWith('/m/');
}

function consentAllows() {
    if (!page.props.legal?.cookie_banner_enabled) {
        return true;
    }
    try {
        const choice = localStorage.getItem('cookie_consent_v1');
        if (choice === 'essential_only') {
            return false;
        }
        if (choice === 'accepted') {
            return true;
        }
    } catch {
        /* sem storage: checkout segue a coleta, como os pixels do produto */
    }

    return isBuyerSurface();
}

function measurementId() {
    const cfg = page.props.platform_ga4;
    if (!cfg?.enabled) {
        return '';
    }
    const id = String(cfg.measurement_id || '').trim();

    return /^G-[A-Za-z0-9]+$/.test(id) ? id : '';
}

function ensureGtag(id) {
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
        window.gtag = function gtag() {
            window.dataLayer.push(arguments);
        };
    }
    if (!scriptInserted) {
        const script = document.createElement('script');
        script.async = true;
        script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
        document.head.appendChild(script);
        window.gtag('js', new Date());
        scriptInserted = true;
    }
    if (!configuredIds.has(id)) {
        window.gtag('config', id, { send_page_view: false });
        configuredIds.add(id);
    }
}

function trackPage() {
    if (typeof document === 'undefined') {
        return;
    }
    const id = measurementId();
    if (!id || !isBuyerSurface() || !consentAllows()) {
        return;
    }
    const path = String(page.url || window.location.pathname).split('#')[0];
    const signature = `${id}|${path}`;
    if (signature === lastSignature) {
        return;
    }
    ensureGtag(id);
    window.gtag('event', 'page_view', {
        send_to: id,
        page_path: path.split('?')[0],
        page_location: `${window.location.origin}${path}`,
    });
    lastSignature = signature;
}

watch(
    () => [page.url, page.component, page.props.platform_ga4?.measurement_id, page.props.platform_ga4?.enabled],
    () => trackPage(),
    { immediate: true },
);

if (typeof window !== 'undefined') {
    window.addEventListener('cookie-consent-changed', () => {
        lastSignature = '';
        trackPage();
    });
}
</script>

<template>
    <span class="hidden" aria-hidden="true" data-platform-ga4 />
</template>
