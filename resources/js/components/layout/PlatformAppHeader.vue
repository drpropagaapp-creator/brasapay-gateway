<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';
import UserMenu from '@/components/layout/UserMenu.vue';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';

const page = usePage();
const { heading } = useThemedPageHeading();
const pageTitle = computed(() => heading.value.title || page.props.pageTitle || null);
const pageSubtitle = computed(() => heading.value.subtitle || '');
const urlPath = computed(() => page.url.split('?')[0] || '');
const isPlatformArea = computed(() => urlPath.value.startsWith('/plataforma'));

const { toggleSidebar, isMobileOpen, isMobile } = useSidebar();

/** Atalhos no topo para rotas frequentes (reforço além do sidebar). */
const quickLinks = [
    { label: 'Clientes', href: '/plataforma/clientes', match: (u) => u === '/plataforma/clientes' || u.startsWith('/plataforma/clientes/') },
    { label: 'Transações', href: '/plataforma/transacoes', match: (u) => (u === '/plataforma/transacoes' || u.startsWith('/plataforma/transacoes/')) && !u.startsWith('/plataforma/transacoes-api') },
    { label: 'Transações API', href: '/plataforma/transacoes-api', match: (u) => u === '/plataforma/transacoes-api' || u.startsWith('/plataforma/transacoes-api/') },
    { label: 'Infoprodutores', href: '/plataforma/usuarios', match: (u) => u === '/plataforma/usuarios' || u.startsWith('/plataforma/usuarios/') },
];
</script>

<template>
    <header
        class="relative z-[99998] flex w-full shrink-0 flex-col gap-4 px-1 pb-2 pt-2 lg:pb-4 lg:pt-1"
        :class="isMobile && isMobileOpen ? 'pointer-events-none' : ''"
    >
        <div class="pn-topbar">
            <div class="pn-topbar__title min-w-0 flex-1">
                <button v-if="isMobile && !isMobileOpen" type="button" class="pn-iconbtn pn-keep-shape shrink-0" aria-label="Abrir menu" @click="toggleSidebar">
                    <Menu class="h-5 w-5" />
                </button>
                <div v-if="pageTitle" class="min-w-0">
                    <h1 class="pn-page-title truncate">{{ pageTitle }}</h1>
                    <p v-if="pageSubtitle" class="truncate">{{ pageSubtitle }}</p>
                </div>
            </div>
            <div class="pn-topbar__tools shrink-0">
                <ThemeToggler />
                <UserMenu />
            </div>
        </div>
        <nav v-if="isPlatformArea" class="pn-tabs self-start" aria-label="Atalhos da plataforma">
            <Link
                v-for="item in quickLinks"
                :key="item.href"
                :href="item.href"
                class="pn-tab inline-flex items-center no-underline hover:no-underline"
                :class="item.match(urlPath) ? 'pn-tab--active' : ''"
            >
                {{ item.label }}
            </Link>
        </nav>
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
</style>
