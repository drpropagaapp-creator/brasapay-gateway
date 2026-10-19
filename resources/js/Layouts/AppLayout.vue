<script setup>
import { computed, ref, watch, provide, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useSidebarProvider } from '@/composables/useSidebar';
import { usePanelPushSubscribe } from '@/composables/usePanelPushSubscribe';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';
import AppSidebar from '@/components/layout/AppSidebar.vue';
import PnAurora from '@/components/paynext/PnAurora.vue';
import AppHeader from '@/components/layout/AppHeader.vue';
import MobileBottomNav from '@/components/layout/MobileBottomNav.vue';
import PwaInstallPrompt from '@/components/layout/PwaInstallPrompt.vue';
import PushNotificationsBanner from '@/components/layout/PushNotificationsBanner.vue';
import NotificationsPanel from '@/components/layout/NotificationsPanel.vue';
import Backdrop from '@/components/layout/Backdrop.vue';
import FlashToast from '@/components/layout/FlashToast.vue';
import SellerSupportFab from '@/components/layout/SellerSupportFab.vue';
import CloudBillingBanner from '@/components/layout/CloudBillingBanner.vue';
import KycBanner from '@/components/layout/KycBanner.vue';
import TotpPromptBanner from '@/components/layout/TotpPromptBanner.vue';
import DemoExploreBanner from '@/components/layout/DemoExploreBanner.vue';
import DemoModeBanner from '@/components/layout/DemoModeBanner.vue';
import MedAlertBanner from '@/components/layout/MedAlertBanner.vue';
import CustomerImpersonationBanner from '@/components/layout/CustomerImpersonationBanner.vue';

const { isExpanded } = useSidebarProvider();
usePanelPushSubscribe();
const { clearHeading } = useThemedPageHeading();
const page = usePage();

watch(
    () => page.url,
    () => {
        clearHeading();
    },
);
const customerPanel = computed(() => !!page.props.customer_panel);
const sellerPanelSupport = computed(() => page.props.seller_panel_support ?? null);
const showSellerSupportFab = computed(() => {
    const config = sellerPanelSupport.value;
    if (!config || customerPanel.value || isPixGoRoute.value) {
        return false;
    }
    const enabled = config.enabled === true || config.enabled === 1 || config.enabled === '1' || config.enabled === 'true';

    return enabled && !!config.href;
});
const isPixGoRoute = computed(() => {
    const path = (page.url ?? '').split('?')[0];
    return path === '/pixgo' || path.startsWith('/pixgo/');
});
const showAppHeader = computed(() => !isPixGoRoute.value);
const showMobileBottomNav = computed(() => !customerPanel.value && !isPixGoRoute.value);
const pageTitle = computed(() => page.props.pageTitle ?? null);
const pageTitleBadge = computed(() => page.props.pageTitleBadge ?? null);
const contentMaxWidth = computed(() => (page.props.layoutFullWidth ? 'max-w-[1600px]' : 'max-w-7xl'));
const layoutContentFlushLeft = computed(() => !!page.props.layoutContentFlushLeft);
const isSellerDashboard = computed(() => page.url === '/dashboard' || page.url.startsWith('/dashboard?'));
const dashboardBanners = computed(() => (Array.isArray(page.props.dashboard_banners) ? page.props.dashboard_banners : []));
const dashboardCarouselIndex = ref(0);
let dashboardCarouselTimer = null;

const showNotificationsPanel = ref(false);
const notificationsUnreadCount = ref(page.props.notifications_unread_count ?? 0);
watch(
    () => page.props.notifications_unread_count,
    (v) => {
        notificationsUnreadCount.value = v ?? 0;
    }
);
provide('openNotificationsPanel', () => {
    showNotificationsPanel.value = true;
});
provide('notificationsUnreadCount', notificationsUnreadCount);

function onNotificationsUnreadCountUpdate(count) {
    notificationsUnreadCount.value = count;
}

/* Sidebar flutuante: 16px de margem + 264px (ou rail de 88px) + 16px de respiro. */
const mainOffsetClass = computed(() => (isExpanded.value ? 'lg:pl-[296px]' : 'lg:pl-[120px]'));

const contentShellClass = computed(() =>
    isPixGoRoute.value ? 'flex min-h-[100dvh] flex-1 flex-col' : 'flex min-h-0 flex-1 flex-col',
);

const mainAreaPaddingClass = computed(() => (isPixGoRoute.value ? 'p-0' : 'px-3 pt-3 md:px-5 md:pt-5 lg:pr-8 lg:pt-6'));

const mainContentPaddingClass = computed(() => (isPixGoRoute.value ? 'flex-1 p-0' : 'flex-1 px-1 pb-28 pt-2 md:pt-3 lg:pb-10'));

const dashboardCurrentBanner = computed(() => {
    if (!dashboardBanners.value.length) return null;
    const idx = dashboardCarouselIndex.value % dashboardBanners.value.length;
    return dashboardBanners.value[idx];
});

function dashboardBannerUrl(item) {
    if (!item) return '';
    const isMobile = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
    if (isMobile) return item.mobile_url || item.desktop_url || '';
    return item.desktop_url || item.mobile_url || '';
}

function stopDashboardCarousel() {
    if (dashboardCarouselTimer) {
        clearInterval(dashboardCarouselTimer);
        dashboardCarouselTimer = null;
    }
}

function startDashboardCarousel() {
    stopDashboardCarousel();
    if (!isSellerDashboard.value || dashboardBanners.value.length <= 1) return;
    dashboardCarouselTimer = setInterval(() => {
        dashboardCarouselIndex.value = (dashboardCarouselIndex.value + 1) % dashboardBanners.value.length;
    }, 5000);
}

watch([isSellerDashboard, dashboardBanners], () => {
    dashboardCarouselIndex.value = 0;
    startDashboardCarousel();
}, { immediate: true });

onBeforeUnmount(() => {
    stopDashboardCarousel();
});
</script>

<template>
    <div class="pn-shell min-h-screen">
        <PnAurora />
        <CustomerImpersonationBanner />
        <AppSidebar />
        <slot name="sidebar-after-nav" />
        <Backdrop />
        <div
            class="flex min-h-screen flex-col transition-[padding] duration-300 ease-[cubic-bezier(.16,1,.3,1)]"
            :class="[mainOffsetClass, mainAreaPaddingClass]"
        >
            <div class="flex w-full shrink-0 flex-col gap-2">
                <div v-if="!customerPanel && !isPixGoRoute" class="flex flex-col gap-2">
                    <DemoModeBanner />
                    <TotpPromptBanner />
                    <DemoExploreBanner />
                    <MedAlertBanner />
                    <CloudBillingBanner />
                    <KycBanner />
                </div>
                <AppHeader v-if="showAppHeader" :page-title="pageTitle" :page-title-badge="pageTitleBadge" />
                <slot name="header-actions" />
            </div>
            <div v-if="isSellerDashboard && dashboardBanners.length" class="pn-glass mb-4 overflow-hidden">
                <div class="relative aspect-[1200/420] w-full overflow-hidden md:aspect-[1600/320]">
                    <img
                        v-if="dashboardCurrentBanner"
                        :key="dashboardCurrentBanner.id"
                        :src="dashboardBannerUrl(dashboardCurrentBanner)"
                        :alt="dashboardCurrentBanner.title || 'Banner da dashboard'"
                        class="h-full w-full object-cover"
                    />
                    <div v-if="dashboardBanners.length > 1" class="pointer-events-none absolute inset-x-0 bottom-3 flex items-center justify-center gap-2 px-4">
                        <button
                            v-for="(item, idx) in dashboardBanners"
                            :key="item.id"
                            type="button"
                            class="pointer-events-auto h-2.5 w-2.5 rounded-full transition"
                            :class="idx === dashboardCarouselIndex ? 'bg-[var(--color-primary)]' : 'bg-white/80'"
                            :aria-label="`Ir para banner ${idx + 1}`"
                            @click="dashboardCarouselIndex = idx"
                        />
                    </div>
                </div>
            </div>
            <FlashToast />
            <SellerSupportFab
                v-if="showSellerSupportFab"
                :config="sellerPanelSupport"
            />
            <div v-if="!customerPanel && !isPixGoRoute" class="pt-2">
                <PushNotificationsBanner />
            </div>
            <PwaInstallPrompt v-if="!isPixGoRoute" />
            <NotificationsPanel
                v-if="!customerPanel"
                :open="showNotificationsPanel"
                @update:open="showNotificationsPanel = $event"
                @unread-count-update="onNotificationsUnreadCountUpdate"
            />
            <MobileBottomNav v-if="showMobileBottomNav" />
            <div :class="contentShellClass">
                <main :class="mainContentPaddingClass">
                    <div
                        class="w-full"
                        :class="[
                            isPixGoRoute || layoutContentFlushLeft ? 'max-w-none' : 'mx-auto',
                            layoutContentFlushLeft && !isPixGoRoute ? 'lg:-ml-6' : '',
                            !isPixGoRoute && !layoutContentFlushLeft && contentMaxWidth,
                        ]"
                    >
                        <slot />
                        <slot name="content-footer" />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
