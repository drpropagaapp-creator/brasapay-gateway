<script setup>
import { ref, computed, watch, onMounted, onUnmounted, toRef, provide } from 'vue';
import { getMetaEntries } from '@/lib/metaTracking/browserPixel.js';
import { Head } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { useCheckoutLocale } from '@/composables/useCheckoutLocale';
import CheckoutTimer from '@/components/checkout/CheckoutTimer.vue';
import CheckoutBanners from '@/components/checkout/CheckoutBanners.vue';
import CheckoutYoutube from '@/components/checkout/CheckoutYoutube.vue';
import CheckoutSummary from '@/components/checkout/CheckoutSummary.vue';
import CheckoutForm from '@/components/checkout/CheckoutForm.vue';
import CheckoutSidebar from '@/components/checkout/CheckoutSidebar.vue';
import CheckoutReviews from '@/components/checkout/CheckoutReviews.vue';
import SalesNotification from '@/components/checkout/SalesNotification.vue';
import SupportButton from '@/components/checkout/SupportButton.vue';
import ExitPopup from '@/components/checkout/ExitPopup.vue';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { runCheckoutMetaTracking } from '@/composables/useMetaCheckoutTracking';
import { trackMetricsEvent } from '@/lib/metricsTracking.js';

defineOptions({ layout: null });

const PREVIEW_MESSAGE_TYPE = 'checkout-builder-preview-config';
const PREVIEW_READY_TYPE = 'checkout-builder-preview-ready';
const PREVIEW_STORAGE_KEY = 'checkout-builder-live-preview-v1';

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    checkout_session_token: { type: String, default: '' },
    available_payment_methods: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    exit_popup_coupon: { type: Object, default: null },
    suggested_locale: { type: String, default: 'pt_BR' },
    suggested_currency: { type: String, default: 'BRL' },
    suggested_country_code: { type: String, default: null },
    checkout_translations: { type: Object, default: () => ({}) },
    currencies: { type: Array, default: () => [] },
    order_bumps: { type: Array, default: () => [] },
    conversion_pixels: { type: Object, default: () => ({}) },
    /** Payee code Efí para tokenização de cartão (quando método card está disponível com gateway efi). */
    card_payee_code: { type: String, default: '' },
    /** Se o gateway Efí está em homologação (token deve ser gerado com setEnvironment('sandbox')). */
    card_efi_sandbox: { type: Boolean, default: false },
    /** Publishable Key Stripe (quando método cartão está disponível com gateway stripe). */
    card_stripe_publishable_key: { type: String, default: '' },
    /** Se o gateway Stripe está em ambiente de teste. */
    card_stripe_sandbox: { type: Boolean, default: false },
    /** Se o Stripe Link está habilitado no Card Element. */
    card_stripe_link_enabled: { type: Boolean, default: true },
    card_installments_enabled: { type: Boolean, default: false },
    card_max_installments: { type: Number, default: 1 },
    /** Public Key Mercado Pago (quando método cartão está disponível com gateway mercadopago). */
    card_mercadopago_public_key: { type: String, default: '' },
    /** Se o gateway Mercado Pago está em sandbox. */
    card_mercadopago_sandbox: { type: Boolean, default: false },
    /** Chaves por gateway slug para gateways de plugin (checkout_payload_keys na definição). Ex.: { 'meu-gateway': { publishable_key: '...' } } */
    card_gateway_keys: { type: Object, default: () => ({}) },
    subscription_plan: { type: Object, default: null },
    /** Definido no servidor quando a URL traz `?preview=1` (preview no iframe do Builder). */
    checkout_builder_preview: { type: Boolean, default: false },
    turnstile: { type: Object, default: () => ({ enabled: false, site_key: '', mode: 'pix_boleto' }) },
    /** Código de afiliado (`?ref=`) propagado ao checkout. */
    affiliate_ref: { type: String, default: '' },
    /** Quando true, loga motivos de tracking Meta no console do browser. */
    meta_tracking_debug: { type: Boolean, default: false },
    /** Aviso legal da plataforma no final do checkout (já interpolado). Vazio = não exibe. */
    platform_checkout_notice: { type: String, default: '' },
});

const previewConfig = ref(null);
const previewEpoch = ref(0);
const conversionPixelsRef = ref(null);
provide('checkoutConversionPixelsRef', conversionPixelsRef);

const isBuilderPreview = computed(() => {
    if (props.checkout_builder_preview) return true;
    if (typeof window === 'undefined') return false;
    try {
        return new URLSearchParams(window.location.search).get('preview') === '1';
    } catch (_) {
        return false;
    }
});

function applyPreviewConfig(config) {
    if (config == null || typeof config !== 'object') return;
    try {
        const next = JSON.stringify(config);
        const prev = previewConfig.value != null ? JSON.stringify(previewConfig.value) : '';
        if (next === prev) return;
    } catch (_) {}
    previewConfig.value = config;
    previewEpoch.value += 1;
}

function announcePreviewReady() {
    if (!isBuilderPreview.value || typeof window === 'undefined') return;
    if (window.parent === window) return;
    try {
        window.parent.postMessage({ type: PREVIEW_READY_TYPE }, '*');
    } catch (_) {}
}

function onPreviewMessage(event) {
    if (!isBuilderPreview.value) return;
    const fromParent = event.source === window.parent;
    const sameOrigin = event.origin === window.location.origin;
    if (!fromParent && !sameOrigin) return;
    if (event?.data?.type !== PREVIEW_MESSAGE_TYPE || event.data.config == null) return;
    applyPreviewConfig(event.data.config);
}

function readPreviewFromStorage() {
    try {
        const raw = localStorage.getItem(PREVIEW_STORAGE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (parsed?.config == null) return;
        applyPreviewConfig(parsed.config);
    } catch (_) {}
}

let previewPollTimer = null;

/** Listener no setup (não só no onMounted) para não perder postMessage se o parent disparar no @load do iframe antes do mount. */
if (typeof window !== 'undefined') {
    if (isBuilderPreview.value) {
        window.addEventListener('message', onPreviewMessage);
        window.__applyCheckoutBuilderPreview = applyPreviewConfig;
        readPreviewFromStorage();
    }
    if (props.meta_tracking_debug) {
        window.__GETFY_META_TRACKING_DEBUG__ = true;
    }
}

/** Config ao vivo do Builder (postMessage / localStorage / bridge); antes da primeira mensagem usa o config do servidor. */
const effectiveConfig = computed(() => {
    if (previewConfig.value != null) {
        return previewConfig.value;
    }
    return props.config;
});

/** Chave visual: força remount controlado só no modo preview quando o config muda. */
const previewRemountKey = computed(() => {
    if (!isBuilderPreview.value) return 'checkout';
    try {
        return `p-${previewEpoch.value}-${JSON.stringify({
            a: effectiveConfig.value?.appearance,
            t: effectiveConfig.value?.timer,
            n: effectiveConfig.value?.sales_notification,
            f: effectiveConfig.value?.customer_fields,
            s: effectiveConfig.value?.summary,
            y: effectiveConfig.value?.youtube_url,
            yp: effectiveConfig.value?.youtube_position,
            sb: effectiveConfig.value?.support_button,
            ft: effectiveConfig.value?.footer,
            ep: effectiveConfig.value?.exit_popup?.enabled,
            rv: effectiveConfig.value?.reviews,
            tp: effectiveConfig.value?.template,
            lp: effectiveConfig.value?.landing,
        })}`;
    } catch (_) {
        return `p-${previewEpoch.value}`;
    }
});

onMounted(() => {
    if (!isBuilderPreview.value) return;
    announcePreviewReady();
    readPreviewFromStorage();
    previewPollTimer = window.setInterval(readPreviewFromStorage, 200);
    [40, 160, 400].forEach((ms) => setTimeout(() => announcePreviewReady(), ms));
});
onUnmounted(() => {
    if (typeof window !== 'undefined' && isBuilderPreview.value) {
        window.removeEventListener('message', onPreviewMessage);
        if (window.__applyCheckoutBuilderPreview === applyPreviewConfig) {
            delete window.__applyCheckoutBuilderPreview;
        }
    }
    if (previewPollTimer) {
        clearInterval(previewPollTimer);
        previewPollTimer = null;
    }
});

const {
    locale,
    setLocale,
    currency: displayCurrency,
    setCurrency,
    t,
    currencies: currencyList,
    priceInCurrency,
    formatPrice,
    supportedLocales,
} = useCheckoutLocale({
    translations: toRef(props, 'checkout_translations'),
    currencies: toRef(props, 'currencies'),
    suggestedLocale: toRef(props, 'suggested_locale'),
    suggestedCurrency: toRef(props, 'suggested_currency'),
    suggestedCountryCode: toRef(props, 'suggested_country_code'),
    storageKey: props.product?.checkout_slug || 'default',
});

const localeLabels = { pt_BR: 'PT', en: 'EN', es: 'ES' };
const appearance = computed(() => effectiveConfig.value?.appearance ?? {});
const backgroundColor = computed(() => appearance.value.background_color || '#E3E3E3');
const primaryColor = computed(() => appearance.value.primary_color || '#0ea5e9');
const banners = computed(() => appearance.value.banners ?? []);
const sideBannersFiltered = computed(() => (appearance.value.side_banners ?? []).filter(Boolean));
const timerConfig = computed(() => effectiveConfig.value?.timer ?? {});
const salesNotificationConfig = computed(() => effectiveConfig.value?.sales_notification ?? {});
const storageKey = computed(() => props.product?.checkout_slug || 'default');

/** Template do checkout: original (2 colunas), focus (coluna única) ou landing (página de vendas + checkout). */
const activeTemplate = computed(() => {
    const t = effectiveConfig.value?.template;
    return t === 'focus' || t === 'landing' ? t : 'original';
});
const isLandingTemplate = computed(() => activeTemplate.value === 'landing');
const isSingleColumn = computed(() => activeTemplate.value !== 'original');

const landingConfig = computed(() => effectiveConfig.value?.landing ?? {});
const landingHeadline = computed(() => (landingConfig.value.headline || '').trim() || props.product?.name || '');
const landingSubheadline = computed(() => (landingConfig.value.subheadline || '').trim());
const landingCtaText = computed(() => (landingConfig.value.cta_text || '').trim() || 'Quero garantir o meu');
const landingHeroImage = computed(() => landingConfig.value.hero_image || null);
const landingBenefitsTitle = computed(() => (landingConfig.value.benefits_title || '').trim() || 'O que você vai receber');
const landingImages = computed(() => (Array.isArray(landingConfig.value.images) ? landingConfig.value.images : []).filter(Boolean));
const landingCustomHtml = computed(() => String(landingConfig.value.custom_html || '').trim());
const landingBenefits = computed(() =>
    String(landingConfig.value.benefits || '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
);
const showLandingReviews = computed(
    () => isLandingTemplate.value
        && landingConfig.value.show_reviews !== false
        && (effectiveConfig.value?.reviews ?? []).length > 0
);

/** Na landing as avaliações ficam na seção de vendas; evita duplicar na sidebar abaixo do formulário. */
const sidebarConfig = computed(() => {
    if (showLandingReviews.value) {
        return { ...effectiveConfig.value, reviews: [] };
    }
    return effectiveConfig.value;
});

function scrollToCheckout() {
    if (typeof document === 'undefined') return;
    document.getElementById('checkout-purchase-area')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const seo = computed(() => effectiveConfig.value?.seo ?? {});
/** Título da aba do navegador e para compartilhamento (Open Graph). Vem do "Título para compartilhamento" no Builder. */
const pageTitle = computed(() => (seo.value.title || '').trim() || props.product?.name || 'Checkout');

watch(pageTitle, (title) => {
    if (typeof document !== 'undefined' && title) {
        document.title = title;
    }
}, { immediate: true });

const pageDescription = computed(() => seo.value.description || props.product?.description || '');
const ogImage = computed(() => {
    const url = seo.value.og_image || props.product?.image_url;
    if (!url) return null;
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});
const faviconHref = computed(() => seo.value.favicon || '/favicon.ico');

const productImageUrlForNotification = computed(() => {
    const url = props.product?.image_url;
    if (!url) return '';
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});

const exitPopupAcceptedCoupon = ref('');
function onExitPopupAccept(code) {
    exitPopupAcceptedCoupon.value = code || '';
}

const appliedCoupon = ref(null);
function onCouponApplied(data) {
    appliedCoupon.value = data;
}
function onCouponCleared() {
    appliedCoupon.value = null;
}

const selectedOrderBumpIds = ref([]);
const selectedOrderBumpsList = computed(() => {
    const ids = new Set(selectedOrderBumpIds.value);
    return (props.order_bumps || []).filter((b) => ids.has(b.id));
});
const orderBumpsTotalBrl = computed(() =>
    selectedOrderBumpsList.value.reduce((sum, b) => sum + (Number(b.amount_brl) || 0), 0)
);

const shippingAmountBrl = ref(0);
function onShippingAmountUpdate(amount) {
    shippingAmountBrl.value = Number(amount) || 0;
}
const requiresShipping = computed(() => Boolean(props.product?.requires_shipping));
watch(
    requiresShipping,
    (needs) => {
        if (needs && displayCurrency.value !== 'BRL') {
            setCurrency('BRL');
        }
    },
    { immediate: true }
);
const checkoutTotalBrl = computed(() => {
    const base = appliedCoupon.value?.final_price ?? props.product?.price_brl ?? props.product?.price ?? 0;
    return Number(base) + orderBumpsTotalBrl.value + (requiresShipping.value ? shippingAmountBrl.value : 0);
});

const conversionPixels = computed(() => props.conversion_pixels || {});

const checkoutTotalInCurrency = computed(() => priceInCurrency(checkoutTotalBrl.value));

let checkoutMetaTrackingDone = false;

async function startCheckoutMetaTracking() {
    if (checkoutMetaTrackingDone || props.checkout_builder_preview) return;
    if (!props.checkout_session_token) return;

    // Tracking interno (paralelo; nunca bloqueia Meta/UTMify).
    try {
        trackMetricsEvent({
            event_name: 'checkout_view',
            product_id: props.product?.id,
            tenant_id: props.product?.tenant_id,
            offer_id: props.offer?.id,
            plan_id: props.subscription_plan?.id,
            affiliate_ref: props.affiliate_ref || undefined,
            properties: { checkout_session_token: props.checkout_session_token },
        });
    } catch (_) {}

    const result = await runCheckoutMetaTracking({
        pixels: conversionPixels.value,
        checkoutSessionToken: props.checkout_session_token,
        value: checkoutTotalInCurrency.value,
        currency: displayCurrency.value,
        contentKey: props.product?.checkout_slug || '',
        contentName: props.product?.name || '',
    });

    if (result?.ok) {
        checkoutMetaTrackingDone = true;
    }
}

function onConversionPixelsMetaReady() {
    startCheckoutMetaTracking();
}

function onConversionPixelsReady() {
    if (getMetaEntries(conversionPixels.value).length === 0) {
        startCheckoutMetaTracking();
    }
}
</script>

<template>
    <ConversionPixels
        ref="conversionPixelsRef"
        :pixels="conversionPixels"
        @ready="onConversionPixelsReady"
        @meta-ready="onConversionPixelsMetaReady"
    />
    <Head>
        <title>{{ pageTitle }}</title>
        <meta v-if="pageDescription" name="description" :content="pageDescription" />
        <meta property="og:title" :content="pageTitle" />
        <meta v-if="pageDescription" property="og:description" :content="pageDescription" />
        <meta v-if="ogImage" property="og:image" :content="ogImage" />
        <link rel="icon" :href="faviconHref" />
    </Head>
    <div
        id="getfy-checkout-root"
        :key="previewRemountKey"
        data-checkout="page"
        class="min-h-screen transition-colors duration-300"
        :style="{ backgroundColor }"
    >
        <CheckoutTimer :config="timerConfig" :storage-key="storageKey" :t="t" />

        <div class="mx-auto max-w-6xl px-4 pb-6 pt-10 sm:px-6 sm:pb-8 sm:pt-12 lg:pb-10 lg:pt-14" data-checkout="layout-inner">
            <!-- Flash -->
            <div
                v-if="flash?.error"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-red-200/80 bg-red-50/95 px-4 py-3.5 text-sm font-medium text-red-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-error"
                role="alert"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <AlertCircle class="h-4 w-4" />
                </span>
                {{ flash.error }}
            </div>
            <div
                v-if="flash?.success"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-emerald-200/80 bg-emerald-50/95 px-4 py-3.5 text-sm font-medium text-emerald-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-success"
                role="status"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <CheckCircle2 class="h-4 w-4" />
                </span>
                {{ flash.success }}
            </div>
            <div
                v-if="flash?.info"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-sky-200/80 bg-sky-50/95 px-4 py-3.5 text-sm font-medium text-sky-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-info"
                role="status"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-600">
                    <CheckCircle2 class="h-4 w-4" />
                </span>
                {{ flash.info }}
            </div>

            <CheckoutBanners v-if="banners.length" :urls="banners" />
            <CheckoutYoutube
                v-if="!isLandingTemplate && (effectiveConfig?.youtube_position ?? 'top') !== 'bottom'"
                :url="effectiveConfig?.youtube_url"
            />

            <!-- Template Landing Page: seção de vendas antes do checkout -->
            <section v-if="isLandingTemplate" class="mb-12" data-checkout="landing-hero">
                <div class="mx-auto max-w-3xl text-center">
                    <h1 class="text-3xl font-extrabold leading-tight tracking-tight text-gray-900 sm:text-4xl lg:text-[44px]">
                        {{ landingHeadline }}
                    </h1>
                    <p v-if="landingSubheadline" class="mx-auto mt-4 max-w-2xl text-lg leading-relaxed text-gray-600">
                        {{ landingSubheadline }}
                    </p>
                    <button
                        type="button"
                        class="mt-8 inline-flex items-center justify-center rounded-2xl px-8 py-4 text-lg font-bold text-white shadow-xl transition hover:opacity-90 focus:outline-none focus:ring-4 focus:ring-black/10"
                        :style="{ backgroundColor: primaryColor }"
                        data-checkout="landing-cta"
                        @click="scrollToCheckout"
                    >
                        {{ landingCtaText }}
                    </button>
                    <p class="mt-3 text-xs text-gray-500">Pagamento 100% seguro · Acesso imediato após a confirmação</p>
                </div>

                <CheckoutYoutube
                    v-if="effectiveConfig?.youtube_url"
                    :url="effectiveConfig?.youtube_url"
                    class="mx-auto mt-10 max-w-4xl"
                />

                <img
                    v-if="landingHeroImage"
                    :src="landingHeroImage"
                    :alt="landingHeadline"
                    class="mx-auto mt-10 w-full max-w-4xl rounded-3xl object-cover shadow-2xl"
                    data-checkout="landing-hero-image"
                    @error="(e) => e?.target && (e.target.style.display = 'none')"
                />

                <!-- Imagens adicionais da landing, empilhadas em sequência -->
                <div v-if="landingImages.length" class="mx-auto mt-8 max-w-4xl space-y-6" data-checkout="landing-images">
                    <img
                        v-for="(img, i) in landingImages"
                        :key="i"
                        :src="img"
                        alt=""
                        class="w-full rounded-3xl object-cover shadow-xl"
                        @error="(e) => e?.target && (e.target.style.display = 'none')"
                    />
                </div>

                <!-- Bloco de HTML personalizado (sanitizado no servidor) -->
                <div
                    v-if="landingCustomHtml"
                    class="checkout-landing-html mx-auto mt-12 max-w-3xl rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-8"
                    data-checkout="landing-custom-html"
                    v-html="landingCustomHtml"
                />

                <div
                    v-if="landingBenefits.length"
                    class="mx-auto mt-12 max-w-3xl rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-8"
                    data-checkout="landing-benefits"
                >
                    <h2 class="text-xl font-bold tracking-tight text-gray-900">{{ landingBenefitsTitle }}</h2>
                    <ul class="mt-5 grid gap-3 sm:grid-cols-2">
                        <li
                            v-for="(benefit, i) in landingBenefits"
                            :key="i"
                            class="flex items-start gap-2.5"
                        >
                            <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0" :style="{ color: primaryColor }" aria-hidden="true" />
                            <span class="text-sm leading-relaxed text-gray-700">{{ benefit }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="showLandingReviews" class="mx-auto mt-10 max-w-3xl" data-checkout="landing-reviews">
                    <CheckoutReviews :reviews="effectiveConfig?.reviews || []" :primary-color="primaryColor" />
                </div>

                <div class="mt-12 text-center">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-2xl px-8 py-4 text-lg font-bold text-white shadow-xl transition hover:opacity-90 focus:outline-none focus:ring-4 focus:ring-black/10"
                        :style="{ backgroundColor: primaryColor }"
                        @click="scrollToCheckout"
                    >
                        {{ landingCtaText }}
                    </button>
                </div>
            </section>

            <div
                id="checkout-purchase-area"
                :class="isSingleColumn
                    ? 'mx-auto flex w-full max-w-2xl scroll-mt-8 flex-col gap-8'
                    : 'flex flex-col gap-8 lg:flex-row lg:gap-10'"
                data-checkout="layout-columns"
            >
                <!-- Coluna principal -->
                <div :class="isSingleColumn ? 'w-full' : 'w-full lg:w-2/3'" data-checkout="column-primary">
                    <div
                        class="overflow-visible rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-8"
                        data-checkout="card-main"
                    >
                        <CheckoutSummary
                            :product="product"
                            :subscription-plan="subscription_plan"
                            :config="effectiveConfig"
                            :primary-color="primaryColor"
                            :applied-coupon="appliedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :price-in-currency="priceInCurrency"
                            :format-price="formatPrice"
                            :locale="locale"
                            :supported-locales="supportedLocales"
                            :currency-list="currencyList"
                            :locale-labels="localeLabels"
                            @set-locale="setLocale"
                            @set-currency="setCurrency"
                        />
                        <hr class="my-8 border-0 border-t border-gray-100" data-checkout="divider-summary-form" />
                        <CheckoutForm
                            :product-id="product.id"
                            :product-offer-id="product.product_offer_id ?? null"
                            :subscription-plan-id="product.subscription_plan_id ?? null"
                            :affiliate-ref="affiliate_ref || ''"
                            :checkout-session-token="checkout_session_token || ''"
                            :turnstile="turnstile || {}"
                            :checkout-builder-preview="isBuilderPreview"
                            :order-bumps="order_bumps || []"
                            v-model:order-bump-ids="selectedOrderBumpIds"
                            :primary-color="primaryColor"
                            :config="effectiveConfig"
                            :available-payment-methods="available_payment_methods"
                            :prefill-coupon="exitPopupAcceptedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :format-price="formatPrice"
                            :suggested-country-code="props.suggested_country_code"
                            :card-payee-code="card_payee_code || ''"
                            :card-efi-sandbox="card_efi_sandbox"
                            :card-stripe-publishable-key="card_stripe_publishable_key || ''"
                            :card-stripe-sandbox="card_stripe_sandbox"
                            :card-stripe-link-enabled="card_stripe_link_enabled"
                            :card-installments-enabled="card_installments_enabled"
                            :card-max-installments="card_max_installments || 1"
                            :card-mercadopago-public-key="card_mercadopago_public_key || ''"
                            :card-mercadopago-sandbox="card_mercadopago_sandbox"
                            :card-gateway-keys="card_gateway_keys || {}"
                            :checkout-total-brl="checkoutTotalBrl"
                            :conversion-pixels="conversion_pixels"
                            :requires-shipping="requiresShipping"
                            :product-subtotal-brl="
                                appliedCoupon?.final_price ?? product?.price_brl ?? product?.price ?? 0
                            "
                            @update:shipping-amount="onShippingAmountUpdate"
                            @coupon-applied="onCouponApplied"
                            @coupon-cleared="onCouponCleared"
                        />
                    </div>
                </div>

                <!-- Coluna lateral: resumo + banners (nos templates de coluna única vira um bloco abaixo do formulário) -->
                <div :class="isSingleColumn ? 'w-full [&>div]:lg:!static [&>div]:lg:!w-full' : 'contents'">
                    <CheckoutSidebar
                        :product="product"
                        :subscription-plan="subscription_plan"
                        :config="sidebarConfig"
                        :applied-coupon="appliedCoupon"
                        :selected-order-bumps="selectedOrderBumpsList"
                        :order-bumps-total-brl="orderBumpsTotalBrl"
                        :requires-shipping="requiresShipping"
                        :shipping-amount-brl="shippingAmountBrl"
                        :t="t"
                        :display-currency="displayCurrency"
                        :price-in-currency="priceInCurrency"
                        :format-price="formatPrice"
                    />
                </div>
            </div>

            <!-- Banners laterais: no mobile aparecem no final da página -->
            <div
                v-if="sideBannersFiltered.length"
                class="mt-8 space-y-4 lg:hidden"
                data-checkout="banners-side-mobile"
            >
                <img
                    v-for="(url, i) in sideBannersFiltered"
                    :key="i"
                    :src="url"
                    alt="Banner"
                    class="w-full rounded-2xl object-cover shadow-lg"
                    @error="(e) => e?.target && (e.target.style.display = 'none')"
                />
            </div>

            <!-- Vídeo YouTube em baixo da página (quando a posição for "bottom") -->
            <CheckoutYoutube
                v-if="!isLandingTemplate && (effectiveConfig?.youtube_position ?? 'top') === 'bottom'"
                :url="effectiveConfig?.youtube_url"
                class="mt-8"
            />

            <p
                v-if="platform_checkout_notice"
                data-checkout="platform-notice"
                class="mx-auto mt-8 max-w-3xl whitespace-pre-line text-center text-xs leading-relaxed text-gray-500"
            >
                {{ platform_checkout_notice }}
            </p>
        </div>

        <SalesNotification
            :config="salesNotificationConfig"
            :product-name="product?.name"
            :product-image-url="productImageUrlForNotification"
        />

        <SupportButton :config="effectiveConfig?.support_button" :primary-color="primaryColor" />
        <ExitPopup
            :config="effectiveConfig"
            :primary-color="primaryColor"
            :exit-popup-coupon="exit_popup_coupon"
            :storage-key="storageKey"
            :t="t"
            @accept="onExitPopupAccept"
        />
    </div>
</template>

<style>
/* Tipografia do bloco de HTML personalizado da landing (conteúdo v-html sem classes) */
.checkout-landing-html {
    color: #374151;
    font-size: 0.9375rem;
    line-height: 1.7;
}
.checkout-landing-html h1,
.checkout-landing-html h2,
.checkout-landing-html h3,
.checkout-landing-html h4,
.checkout-landing-html h5 {
    color: #111827;
    font-weight: 700;
    letter-spacing: -0.01em;
    margin: 1.25em 0 0.5em;
    line-height: 1.25;
}
.checkout-landing-html h1 { font-size: 1.75rem; }
.checkout-landing-html h2 { font-size: 1.375rem; }
.checkout-landing-html h3 { font-size: 1.125rem; }
.checkout-landing-html > :first-child { margin-top: 0; }
.checkout-landing-html > :last-child { margin-bottom: 0; }
.checkout-landing-html p { margin: 0.75em 0; }
.checkout-landing-html ul,
.checkout-landing-html ol {
    margin: 0.75em 0;
    padding-left: 1.5em;
}
.checkout-landing-html ul { list-style: disc; }
.checkout-landing-html ol { list-style: decimal; }
.checkout-landing-html li { margin: 0.375em 0; }
.checkout-landing-html a {
    color: #2563eb;
    text-decoration: underline;
}
.checkout-landing-html img {
    max-width: 100%;
    height: auto;
    border-radius: 1rem;
    margin: 1em 0;
}
.checkout-landing-html blockquote {
    border-left: 3px solid #e5e7eb;
    padding-left: 1em;
    margin: 1em 0;
    color: #6b7280;
    font-style: italic;
}
.checkout-landing-html hr {
    border: 0;
    border-top: 1px solid #e5e7eb;
    margin: 1.5em 0;
}
.checkout-landing-html table {
    width: 100%;
    border-collapse: collapse;
    margin: 1em 0;
    font-size: 0.875rem;
}
.checkout-landing-html th,
.checkout-landing-html td {
    border: 1px solid #e5e7eb;
    padding: 0.5em 0.75em;
    text-align: left;
}
.checkout-landing-html th {
    background: #f9fafb;
    font-weight: 600;
}
.checkout-landing-html pre {
    background: #f3f4f6;
    border-radius: 0.75rem;
    padding: 1em;
    overflow-x: auto;
    font-size: 0.8125rem;
}
</style>
