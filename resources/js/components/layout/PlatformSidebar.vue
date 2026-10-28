<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Users,
    UserCircle2,
    Shield,
    PanelRightOpen,
    X,
    Settings,
    Mail,
    MessageCircle,
    MessageSquare,
    Smartphone,
    Wallet,
    Landmark,
    Activity,
    ArrowLeftRight,
    AlertTriangle,
    Banknote,
    CircleDollarSign,
    FileSpreadsheet,
    Code2,
    Trophy,
    BadgeCheck,
    Package,
    Puzzle,
    Plug,
    Gift,
    ScrollText,
    Logs,
    ContactRound,
    ChartNoAxesCombined,
    Webhook,
    Store,
} from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';

const page = usePage();
const { isExpanded, isMobileOpen, toggleSidebar, isMobile, closeMobileSidebarIfOpen } = useSidebar();

const showText = () => isExpanded.value || isMobileOpen.value;

const iconMap = {
    Puzzle,
    Plug,
};

const sidebarBadges = computed(() => page.props.platform_admin_sidebar_badges ?? {});

function badgeCount(key) {
    const n = Number(sidebarBadges.value?.[key] ?? 0);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function badgeLabel(count) {
    return count > 99 ? '99+' : String(count);
}

/**
 * Ordem pensada no fluxo do operador:
 * visão geral → pessoas → vendas/risco → dinheiro → crescimento → sistema (ops/saúde).
 */
const navGroupsCore = [
    {
        id: 'geral',
        label: null,
        items: [
            { name: 'Dashboard', href: '/plataforma/dashboard', icon: LayoutDashboard },
        ],
    },
    {
        id: 'pessoas',
        label: 'Pessoas',
        items: [
            { name: 'Infoprodutores', href: '/plataforma/usuarios', icon: Users },
            { name: 'Gerentes de Conta', href: '/plataforma/gerentes-conta', icon: ContactRound },
            { name: 'Clientes', href: '/plataforma/clientes', icon: UserCircle2 },
            { name: 'Verificações KYC', href: '/plataforma/verificacoes-kyc', icon: BadgeCheck, badgeKey: 'kyc' },
        ],
    },
    {
        id: 'operacoes',
        label: 'Operações',
        items: [
            { name: 'Transações', href: '/plataforma/transacoes', icon: ArrowLeftRight, badgeKey: 'transacoes' },
            { name: 'Transações API', href: '/plataforma/transacoes-api', icon: Code2 },
            { name: 'Disputas MED', href: '/plataforma/disputas', icon: AlertTriangle, badgeKey: 'disputas' },
            { name: 'Produtos', href: '/plataforma/produtos?filter=pending', icon: Package, badgeKey: 'produtos' },
            { name: 'Marketplace', href: '/plataforma/marketplace', icon: Store },
        ],
    },
    {
        id: 'financeiro',
        label: 'Financeiro',
        items: [
            { name: 'Saques', href: '/plataforma/saques', icon: Banknote, badgeKey: 'saques' },
            { name: 'Saldo', href: '/plataforma/saldo', icon: CircleDollarSign },
            { name: 'Financeiro', href: '/plataforma/financeiro', icon: Wallet, badgeKey: 'financeiro' },
            { name: 'Fiscal', href: '/plataforma/fiscal', icon: FileSpreadsheet },
            { name: 'Tesouraria', href: '/plataforma/tesouraria', icon: Landmark },
        ],
    },
    {
        id: 'crescimento',
        label: 'Crescimento',
        items: [
            { name: 'Métricas e Tracking', href: '/plataforma/metricas', icon: ChartNoAxesCombined },
            { name: 'Conquistas', href: '/plataforma/conquistas', icon: Trophy },
            { name: 'Indique e Ganhe', href: '/plataforma/indique-e-ganhe', icon: Gift },
            { name: 'Central de e-mails', href: '/plataforma/email-marketing', icon: Mail },
            { name: 'IntegraX SMS', href: '/plataforma/integrax', icon: MessageSquare },
            { name: 'WhatsApp', href: '/plataforma/uazapi', icon: MessageCircle },
            { name: 'App', href: '/plataforma/app', icon: Smartphone },
        ],
    },
    {
        id: 'sistema',
        label: 'Sistema',
        items: [
            { name: 'Configurações', href: '/plataforma/configuracoes', icon: Settings },
            { name: 'Saúde de Pagamentos', href: '/plataforma/ops/saude-pagamentos', icon: Activity },
            { name: 'Saúde UTMify', href: '/plataforma/ops/saude-utmify', icon: Activity },
            { name: 'Webhooks', href: '/plataforma/webhooks', icon: Webhook },
            { name: 'Log Infoprodutor', href: '/plataforma/log-infoprodutor', icon: ScrollText },
            { name: 'Log sistema', href: '/plataforma/log-sistema', icon: Logs },
            { name: 'Plugins', href: '/plataforma/gerenciar-plugins', icon: Puzzle },
        ],
    },
];

const pluginNavItems = computed(() => {
    const raw = page.props.pluginNavItems ?? [];
    return raw.map((item) => ({
        name: item.name,
        href: item.href,
        icon: item.icon && iconMap[item.icon] ? iconMap[item.icon] : Puzzle,
    }));
});

const navGroups = computed(() => {
    const groups = navGroupsCore.map((g) => ({ ...g, items: [...g.items] }));
    const plugins = pluginNavItems.value;
    if (plugins.length) {
        const sistema = groups.find((g) => g.id === 'sistema');
        if (sistema) {
            sistema.items = [...sistema.items, ...plugins];
        } else {
            groups.push({ id: 'plugins', label: 'Plugins', items: plugins });
        }
    }
    return groups;
});

function isNavItemActive(href) {
    if (href.includes('?')) {
        const pathOnly = href.split('?')[0];
        if (pathOnly === '/plataforma/produtos') {
            return isActive('/plataforma/produtos');
        }

        const url = page.url.split('#')[0];

        return url === href;
    }

    return isActive(href);
}

function isActive(href) {
    const url = page.url.split('?')[0];
    if (href === '/plataforma/dashboard') {
        return url === '/plataforma/dashboard';
    }
    if (href === '/plataforma/transacoes-api') {
        return url === '/plataforma/transacoes-api' || url.startsWith('/plataforma/transacoes-api/');
    }
    if (href === '/plataforma/transacoes') {
        return (url === '/plataforma/transacoes' || url.startsWith('/plataforma/transacoes/'))
            && !url.startsWith('/plataforma/transacoes-api');
    }
    if (href === '/plataforma/disputas') {
        return url === '/plataforma/disputas' || url.startsWith('/plataforma/disputas/');
    }
    if (href === '/plataforma/clientes') {
        return url === '/plataforma/clientes' || url.startsWith('/plataforma/clientes/');
    }
    if (href === '/plataforma/produtos') {
        return url === '/plataforma/produtos' || url.startsWith('/plataforma/produtos/');
    }
    if (href === '/plataforma/marketplace') {
        return url === '/plataforma/marketplace' || url.startsWith('/plataforma/marketplace/');
    }
    if (href === '/plataforma/verificacoes-kyc') {
        return url === '/plataforma/verificacoes-kyc' || url.startsWith('/plataforma/verificacoes-kyc/');
    }
    if (href === '/plataforma/saques') {
        return url === '/plataforma/saques' || url.startsWith('/plataforma/saques/');
    }
    if (href === '/plataforma/saldo') {
        return url === '/plataforma/saldo' || url.startsWith('/plataforma/saldo/');
    }
    if (href === '/plataforma/usuarios') {
        return url === '/plataforma/usuarios' || (url.startsWith('/plataforma/usuarios/') && !url.startsWith('/plataforma/usuarios/create'));
    }
    if (href === '/plataforma/gerentes-conta') {
        return url === '/plataforma/gerentes-conta' || url.startsWith('/plataforma/gerentes-conta/');
    }
    if (href === '/plataforma/financeiro') {
        return url === '/plataforma/financeiro' || url.startsWith('/plataforma/financeiro/');
    }
    if (href === '/plataforma/fiscal') {
        return url === '/plataforma/fiscal' || url.startsWith('/plataforma/fiscal/');
    }
    if (href === '/plataforma/indique-e-ganhe') {
        return url === '/plataforma/indique-e-ganhe' || url.startsWith('/plataforma/indique-e-ganhe/');
    }
    if (href === '/plataforma/ops/saude-pagamentos') {
        return url === '/plataforma/ops/saude-pagamentos' || url.startsWith('/plataforma/ops/saude-pagamentos/');
    }
    if (href === '/plataforma/ops/saude-utmify') {
        return url === '/plataforma/ops/saude-utmify' || url.startsWith('/plataforma/ops/saude-utmify/');
    }
    if (href === '/plataforma/log-infoprodutor') {
        return url === '/plataforma/log-infoprodutor' || url.startsWith('/plataforma/log-infoprodutor/');
    }
    if (href === '/plataforma/log-sistema') {
        return url === '/plataforma/log-sistema' || url.startsWith('/plataforma/log-sistema/');
    }
    if (href === '/plataforma/webhooks') {
        return url === '/plataforma/webhooks' || url.startsWith('/plataforma/webhooks/');
    }
    if (href === '/plataforma/metricas') {
        return url === '/plataforma/metricas' || url.startsWith('/plataforma/metricas/');
    }
    if (href === '/plataforma/configuracoes') {
        return url === '/plataforma/configuracoes' || url.startsWith('/plataforma/configuracoes/');
    }
    if (href === '/plataforma/gerenciar-plugins') {
        return url === '/plataforma/gerenciar-plugins' || url.startsWith('/plataforma/gerenciar-plugins/');
    }
    if (href === '/plataforma/app') {
        return url === '/plataforma/app' || url.startsWith('/plataforma/app/');
    }
    if (href === '/plataforma/conquistas') {
        return url === '/plataforma/conquistas' || url.startsWith('/plataforma/conquistas/');
    }
    if (href === '/plataforma/email-marketing') {
        return url === '/plataforma/email-marketing' || url.startsWith('/plataforma/email-marketing/');
    }
    if (href === '/plataforma/integrax') {
        return url === '/plataforma/integrax' || url.startsWith('/plataforma/integrax/');
    }
    if (href === '/plataforma/uazapi') {
        return url === '/plataforma/uazapi' || url.startsWith('/plataforma/uazapi/');
    }
    return url === href || url.startsWith(`${href}/`);
}

import PnWordmark from '@/components/paynext/PnWordmark.vue';
</script>

<template>
    <aside
        class="pn-platform-sidebar pn-glass pn-glass--strong z-[45] flex flex-col gap-1"
        :class="[
            {
                'w-[280px] translate-x-0': isMobileOpen,
                '-translate-x-full': !isMobileOpen,
                'pointer-events-none': isMobile && !isMobileOpen,
                'lg:pointer-events-auto lg:translate-x-0': true,
                'lg:w-[264px]': isExpanded || isMobileOpen,
                'lg:w-[88px]': !isExpanded && !isMobileOpen,
            },
        ]"
    >
        <div :class="['flex items-center px-5 pb-2 pt-6', showText() ? 'justify-between gap-2' : 'lg:justify-center lg:px-0']">
            <Link
                href="/plataforma/dashboard"
                class="flex min-w-0 flex-1 items-center no-underline hover:no-underline"
                :class="showText() ? '' : 'justify-center'"
                @click="closeMobileSidebarIfOpen"
            >
                <PnWordmark :variant="showText() ? 'full' : 'icon'" />
            </Link>
            <button
                v-if="showText()"
                type="button"
                class="pn-iconbtn pn-keep-shape shrink-0"
                style="width: 38px; height: 38px"
                :aria-label="isMobile ? 'Fechar menu' : 'Recolher menu'"
                @click="toggleSidebar"
            >
                <X v-if="isMobile" class="h-[18px] w-[18px]" />
                <PanelRightOpen v-else class="h-[18px] w-[18px]" />
            </button>
        </div>
        <div v-if="!showText()" class="mb-1 hidden justify-center lg:flex">
            <button type="button" class="pn-iconbtn pn-keep-shape" style="width: 38px; height: 38px" aria-label="Expandir menu" @click="toggleSidebar">
                <PanelRightOpen class="h-[18px] w-[18px]" style="transform: scaleX(-1)" />
            </button>
        </div>

        <nav class="no-scrollbar flex-1 overflow-y-auto px-3 pb-3" :class="showText() ? '' : 'lg:px-[18px]'">
            <div v-for="group in navGroups" :key="group.id">
                <p v-if="group.label && showText()" class="pn-sidebar__sec">{{ group.label }}</p>
                <div v-else-if="group.label" class="mx-auto my-3 h-px w-7" style="background: var(--border-default)" aria-hidden="true" />
                <div class="flex flex-col gap-1">
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="pn-nav no-underline hover:no-underline"
                        :class="[isNavItemActive(item.href) ? 'pn-nav--active' : '', showText() ? '' : 'pn-nav--rail-lg']"
                        :title="!showText() ? item.name : undefined"
                        @click="closeMobileSidebarIfOpen"
                    >
                        <span class="relative shrink-0">
                            <component :is="item.icon" class="h-5 w-5" :weight="isNavItemActive(item.href) ? 'fill' : 'duotone'" />
                            <span
                                v-if="item.badgeKey && badgeCount(item.badgeKey) > 0 && !showText()"
                                class="pn-iconbtn__count"
                                style="top: -9px; right: -11px; min-width: 18px; height: 18px; line-height: 18px; font-size: 9.5px"
                                :aria-label="`${badgeCount(item.badgeKey)} pendente(s)`"
                            >
                                {{ badgeCount(item.badgeKey) > 9 ? '9+' : badgeLabel(badgeCount(item.badgeKey)) }}
                            </span>
                        </span>
                        <span v-show="showText()" class="pn-nav__label truncate">{{ item.name }}</span>
                        <span
                            v-if="item.badgeKey && badgeCount(item.badgeKey) > 0 && showText()"
                            class="pn-nav__count"
                            :aria-label="`${badgeCount(item.badgeKey)} pendente(s)`"
                        >
                            {{ badgeLabel(badgeCount(item.badgeKey)) }}
                        </span>
                    </Link>
                </div>
            </div>
        </nav>

        <div v-show="showText()" class="mx-4 mb-5 border-t pt-3" style="border-color: var(--border-default)">
            <span class="pn-eyebrow">Operador do gateway</span>
        </div>
    </aside>
</template>

<style scoped>
.pn-platform-sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    border-radius: 0 28px 28px 0;
    box-shadow: var(--shadow-md);
    transition:
        width var(--dur-base) var(--ease-out),
        transform var(--dur-base) var(--ease-out);
}
@media (min-width: 1024px) {
    .pn-platform-sidebar {
        inset: 16px auto 16px 16px;
        border-radius: var(--radius-2xl);
    }
    .pn-nav--rail-lg {
        width: 52px;
        height: 52px;
        padding: 0;
        justify-content: center;
        border-radius: 18px;
    }
}
</style>
