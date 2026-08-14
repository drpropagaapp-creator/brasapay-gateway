import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const DEMO_TEMPLATE_KEY = 'demo_template_preview';

const TEMPLATE_IDS = ['default', 'aurora', 'kawaii', 'prime', 'studio'];

/** Estado compartilhado entre todas as instâncias do composable. */
const sharedPreviewOverride = ref(null);
let previewListenerAttached = false;

function readPreviewFromStorage() {
    if (typeof window === 'undefined') {
        return null;
    }
    const raw = localStorage.getItem(DEMO_TEMPLATE_KEY);
    if (TEMPLATE_IDS.includes(raw)) {
        return raw;
    }
    return null;
}

function syncSharedPreview(demoEnabled) {
    if (!demoEnabled) {
        sharedPreviewOverride.value = null;
        return;
    }
    sharedPreviewOverride.value = readPreviewFromStorage();
}

function attachPreviewListener(getDemoEnabled) {
    if (previewListenerAttached || typeof window === 'undefined') {
        return;
    }
    previewListenerAttached = true;
    window.addEventListener('demo-template-preview-changed', () => {
        if (getDemoEnabled()) {
            sharedPreviewOverride.value = readPreviewFromStorage();
        }
    });
}

export function useSellerDashboardTemplate() {
    const page = usePage();

    const demoEnabled = () => !!page.props.demo_mode?.enabled;

    onMounted(() => {
        syncSharedPreview(demoEnabled());
        attachPreviewListener(demoEnabled);
    });

    const templateId = computed(() => {
        if (page.props.customer_panel) {
            return 'default';
        }

        if (demoEnabled()) {
            const preview = sharedPreviewOverride.value ?? readPreviewFromStorage();
            if (TEMPLATE_IDS.includes(preview)) {
                return preview;
            }
        }

        const raw = page.props.seller_dashboard_template;
        if (TEMPLATE_IDS.includes(raw)) {
            return raw;
        }
        return 'default';
    });

    const isAurora = computed(() => templateId.value === 'aurora');
    const isKawaii = computed(() => templateId.value === 'kawaii');
    const isPrime = computed(() => templateId.value === 'prime');
    const isStudio = computed(() => templateId.value === 'studio');
    const isDefault = computed(() => templateId.value === 'default');
    const isThemedShell = computed(() => !isDefault.value);
    const themePrefix = computed(() => (isDefault.value ? null : templateId.value));
    const pageWrapperClass = computed(() =>
        isDefault.value ? 'space-y-6' : `${templateId.value}-page`
    );

    return {
        templateId,
        isDefault,
        isAurora,
        isKawaii,
        isPrime,
        isStudio,
        isThemedShell,
        themePrefix,
        pageWrapperClass,
    };
}
