<script setup>
import { computed, inject } from 'vue';
import { Bell, Menu } from 'lucide-vue-next';
import { usePage } from '@inertiajs/vue3';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import UserMenu from '@/components/layout/UserMenu.vue';
import LocaleThemeControls from '@/components/layout/LocaleThemeControls.vue';
import AccountManagerCard from '@/components/dashboard/AccountManagerCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';

/** Topbar do Paynext DS: título grande, ferramentas em botões circulares de vidro. */
const props = defineProps({
    pageTitle: { type: String, default: null },
    pageTitleBadge: { type: String, default: null },
});

const page = usePage();
const { heading } = useThemedPageHeading();
const customerPanel = computed(() => !!page.props.customer_panel);
const isDashboard = computed(() => page.url === '/dashboard' || page.url.startsWith('/dashboard?'));

const firstName = computed(() => {
    const name = String(page.props.auth?.user?.name || '').trim();
    return name ? name.split(/\s+/)[0] : '';
});
const title = computed(() => heading.value.title || props.pageTitle || '');
const subtitle = computed(() => heading.value.subtitle || '');

const { toggleSidebar, isMobileOpen, isMobile } = useSidebar();
const openNotificationsPanel = inject('openNotificationsPanel', () => {});
const notificationsUnreadCount = inject('notificationsUnreadCount', { value: 0 });
const unreadBadge = computed(() => Math.max(0, notificationsUnreadCount?.value ?? 0));
const { t } = useI18n();
</script>

<template>
    <header
        class="pn-topbar relative z-[40] w-full shrink-0 px-1 pb-2 pt-2 lg:pb-4 lg:pt-1"
        :class="isMobile && isMobileOpen ? 'pointer-events-none' : ''"
    >
        <div class="pn-topbar__title min-w-0 flex-1">
            <button
                v-if="isMobile && !isMobileOpen"
                type="button"
                class="pn-iconbtn pn-keep-shape shrink-0"
                aria-label="Abrir menu"
                @click="toggleSidebar"
            >
                <Menu class="h-5 w-5" />
            </button>
            <div v-if="isDashboard && !customerPanel" class="min-w-0">
                <h1 class="pn-page-title truncate">
                    Olá{{ firstName ? `, ${firstName}` : '' }}<span class="pn-dot">.</span>
                </h1>
                <p>Aqui está o resumo do seu negócio hoje.</p>
            </div>
            <div v-else-if="title" class="min-w-0">
                <div class="flex min-w-0 items-center gap-3">
                    <h1 class="pn-page-title truncate">{{ title }}</h1>
                    <span v-if="pageTitleBadge" class="pn-badge pn-badge--brand max-w-[220px] truncate" :title="pageTitleBadge">
                        {{ pageTitleBadge }}
                    </span>
                </div>
                <p v-if="subtitle" class="truncate">{{ subtitle }}</p>
            </div>
        </div>
        <div class="pn-topbar__tools shrink-0">
            <ConquistasWidget v-if="!customerPanel && (!isDashboard || !isMobile)" />
            <AccountManagerCard v-if="!customerPanel" variant="default" />
            <LocaleThemeControls v-if="!customerPanel" language-only size="md" />
            <LocaleThemeControls theme-only class="hidden lg:flex" size="md" />
            <button
                v-if="!customerPanel"
                type="button"
                class="pn-iconbtn pn-keep-shape"
                :aria-label="t('header.notifications', 'Notificações')"
                @click="openNotificationsPanel()"
            >
                <Bell class="h-5 w-5" />
                <span v-if="unreadBadge > 0" class="pn-iconbtn__count">{{ unreadBadge > 99 ? '99+' : unreadBadge }}</span>
            </button>
            <UserMenu />
        </div>
    </header>
</template>

<style scoped>
.pn-page-title {
    font: 500 26px/32px var(--font-display);
    letter-spacing: var(--tracking-heading);
}
@media (min-width: 768px) {
    .pn-page-title {
        font: var(--text-h2);
        letter-spacing: var(--tracking-heading);
    }
}
@media (max-width: 639px) {
    .pn-topbar {
        flex-wrap: wrap;
    }
    .pn-topbar__tools {
        gap: 8px;
    }
}
</style>
