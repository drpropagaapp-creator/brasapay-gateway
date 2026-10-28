import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    CircleDollarSign,
    Wallet,
    Package,
    Users,
    BarChart3,
    Puzzle,
    Cable,
    Settings,
    Plug,
    Wrench,
    FileCode,
    Box,
    Mail,
    CodeXml,
    Store,
    TicketPercent,
    GraduationCap,
    UserPlus,
    Handshake,
    Gift,
    Truck,
    Zap,
    Activity,
    Code2,
    MailCheck,
    Contact,
    Lightbulb,
    Terminal,
    LibraryBig,
    Globe,
    Sparkles,
    MonitorPlay,
    Repeat,
    FlaskConical,
} from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const iconMap = {
    Puzzle,
    Plug,
    Wrench,
    Settings,
    FileCode,
    Box,
    LayoutDashboard,
    Package,
    Users,
    BarChart3,
    Mail,
    CodeXml,
    Code2,
};

/** Hover only no desktop — no mobile o primeiro toque vira hover e compete com a navegação. */
export const panelNavPrefetch = 'hover';

export function navPrefetch(isMobile) {
    return isMobile ? false : panelNavPrefetch;
}

export function useAppSidebarNav() {
    const page = usePage();
    const { t } = useI18n();

    const homeHref = computed(() => (page.props.customer_panel ? '/painel-cliente' : '/dashboard'));

    const appSettings = () => page.props.appSettings ?? {};
    const appName = () => appSettings().app_name || 'Infoprodutor';
    const hasLogoFull = () => !!(appSettings().app_logo || appSettings().app_logo_dark);
    const hasLogoIcon = () => !!(appSettings().app_logo_icon || appSettings().app_logo_icon_dark);

    const pluginNavItems = computed(() => {
        const raw = page.props.pluginNavItems ?? [];
        return raw.map((item) => ({
            name: item.name,
            href: item.href,
            icon: item.icon && iconMap[item.icon] ? iconMap[item.icon] : Puzzle,
        }));
    });

    const perms = computed(() => page.props.auth?.permissions ?? {});
    const canView = (key) => {
        const role = page.props.auth?.user?.role;
        if (role === 'admin' || role === 'infoprodutor') return true;
        return !!perms.value?.[key];
    };

    /**
     * Menu em grupos: no topo só o uso diário; o resto em grupos com título, recolhíveis.
     * Cada item continua respeitando as permissões da equipe e os itens escondidos pelo white-label.
     */
    const navGroups = computed(() => {
        if (page.props.customer_panel) {
            return [{ id: 'cliente', label: null, items: [
                { name: t('customer.sidebar.purchases', 'Minhas compras'), href: '/painel-cliente', icon: Package },
                { name: t('customer.sidebar.marketplace', 'Marketplace'), href: '/painel-cliente/marketplace', icon: Store },
                { name: t('customer.sidebar.subscriptions', 'Minhas assinaturas'), href: '/painel-cliente/assinaturas', icon: Repeat },
            ] }];
        }
        const role = page.props.auth?.user?.role;
        const owner = role === 'admin' || role === 'infoprodutor';
        const api = canView('api_pagamentos.view') && page.props.api_pix_enabled_effective;
        const item = (name, href, icon, extra = {}) => ({ name, href, icon, ...extra });

        const groups = [
            { id: 'principal', label: null, items: [
                canView('dashboard.view') && item(t('sidebar.dashboard', 'Dashboard'), '/dashboard', LayoutDashboard),
                canView('vendas.view') && item(t('sidebar.sales', 'Vendas'), '/vendas', CircleDollarSign),
                canView('vendas.view') && item(t('sidebar.crm', 'Clientes'), '/crm', Contact),
                canView('financeiro.view') && item(t('sidebar.finance', 'Financeiro'), '/financeiro', Wallet),
            ] },
            { id: 'produtos', label: t('sidebar.group_products', 'Produtos'), items: canView('produtos.view') ? [
                item(t('sidebar.products', 'Meus produtos'), '/produtos', Package),
                item(t('sidebar.member_areas', 'Áreas de membros'), '/areas-de-membros', MonitorPlay),
                item(t('sidebar.students', 'Alunos'), '/produtos/alunos', GraduationCap),
                item(t('sidebar.coupons', 'Cupons'), '/produtos/cupons', TicketPercent),
                item(t('sidebar.affiliate_showcase', 'Vitrine'), '/produtos/vitrine-afiliacao', Store),
                page.props.physical_products_enabled_effective && item(t('sidebar.shipping', 'Taxas e frete'), '/frete', Truck),
                page.props.pixgo_enabled_effective && canView('pixgo.view') && item(page.props.pixgo_sidebar_label || 'PixGO', '/pixgo', Zap),
            ] : [] },
            { id: 'marketing', label: t('sidebar.group_marketing', 'Vender mais'), items: [
                canView('integracoes.view') && item(t('sidebar.recovery', 'Recuperação de vendas'), '/recuperacao', MailCheck),
                canView('produtos.view') && item(t('sidebar.experiments', 'Testes A/B'), '/experimentos', FlaskConical),
                canView('produtos.view') && item(t('sidebar.affiliates_menu', 'Afiliados'), '/afiliados', UserPlus),
                canView('produtos.view') && item(t('sidebar.coproduction', 'Co-produção'), '/coproducao', Handshake),
                canView('produtos.view') && item(t('sidebar.library', 'Biblioteca de templates'), '/biblioteca', LibraryBig),
                page.props.referral_program?.enabled && item(t('sidebar.referral', 'Indique e Ganhe'), '/indique-e-ganhe', Gift),
            ] },
            { id: 'analises', label: t('sidebar.group_insights', 'Análises'), items: [
                canView('relatorios.view') && item(t('sidebar.reports', 'Relatórios'), '/relatorios', BarChart3),
                canView('relatorios.view') && item(t('sidebar.intelligence', 'Inteligência'), '/inteligencia', Lightbulb),
                canView('metrics.view') && item(t('sidebar.metrics', 'Métricas e Tracking'), '/metricas', Activity),
                canView('relatorios.view') && item(t('sidebar.assistant', 'Assistente'), '/assistente', Sparkles),
            ] },
            { id: 'integracoes', label: t('sidebar.group_integrations', 'Integrações e API'), items: [
                canView('integracoes.view') && page.props.seller_integrations_any_visible !== false && item(t('sidebar.integrations', 'Integrações'), '/integracoes', Cable),
                ...(owner ? pluginNavItems.value : []),
                api && item('API PIX', '/aplicacoes-api', CodeXml, { badge: 'pix-in-out' }),
                api && item(t('sidebar.developers', 'Desenvolvedores'), '/desenvolvedores', Terminal),
                canView('vendas.view') && page.props.api_pix_enabled_effective && item(t('sidebar.api_transactions', 'Transações API'), '/transacoes-api', Code2),
            ] },
            { id: 'conta', label: t('sidebar.group_account', 'Conta'), items: [
                (role === 'infoprodutor' || canView('equipe.manage')) && item(t('sidebar.team', 'Equipe'), '/usuarios/equipe', Users),
                page.props.enterprise?.active && item(t('sidebar.enterprise', 'Marca e domínio'), '/marca-e-dominio', Globe),
            ] },
        ];

        // Brasa Enterprise: o admin pode esconder módulos do menu desta conta.
        const hidden = Array.isArray(page.props.enterprise?.hidden_nav) ? page.props.enterprise.hidden_nav : [];
        return groups
            .map((g) => ({ ...g, items: g.items.filter((i) => i && (!i.href || !hidden.includes(i.href))) }))
            .filter((g) => g.items.length);
    });

    /** Lista plana (menu recolhido em ícones): grupos separados por linha + instalar app no mobile. */
    const navItems = computed(() => {
        const items = [];
        navGroups.value.forEach((g, i) => {
            if (i > 0) items.push({ separator: true });
            items.push(...g.items);
        });
        if (!page.props.customer_panel) {
            items.push({ separator: true }, { pwaInstall: true });
        }

        return items;
    });

    function isActive(href) {
        const url = page.url.split('?')[0];
        if (href === '/painel-cliente') return url === '/painel-cliente';
        if (href === '/frete') return url === '/frete' || url.startsWith('/frete/');
        if (href === '/dashboard') return url === '/dashboard' || url === '/';
        if (href === '/vendas') {
            return url === '/vendas' || url.startsWith('/vendas/');
        }
        if (href === '/transacoes-api') {
            return url === '/transacoes-api' || url.startsWith('/transacoes-api/');
        }
        if (href === '/produtos/vitrine-afiliacao') {
            return url === '/produtos/vitrine-afiliacao' || url.startsWith('/produtos/vitrine-afiliacao/');
        }
        if (href === '/produtos/cupons') {
            return url.startsWith('/produtos/cupons');
        }
        if (href === '/produtos/alunos') {
            return url.startsWith('/produtos/alunos');
        }
        if (href === '/afiliados') {
            return url === '/afiliados' || url.startsWith('/afiliados/');
        }
        if (href === '/coproducao') {
            return url === '/coproducao' || url.startsWith('/coproducao/') || url.startsWith('/produtos/coproducao');
        }
        if (href === '/produtos') {
            if (
                url.startsWith('/produtos/cupons') ||
                url.startsWith('/produtos/alunos') ||
                url.startsWith('/produtos/coproducao') ||
                url.startsWith('/produtos/vitrine-afiliacao') ||
                url.startsWith('/produtos/afiliados') ||
                /\/painel-afiliado/.test(url)
            ) {
                return false;
            }
            return url === '/produtos' || url.startsWith('/produtos/');
        }
        if (href === '/aplicacoes-api') {
            return url === '/aplicacoes-api' || url.startsWith('/aplicacoes-api/');
        }
        if (href === '/pixgo') {
            return url === '/pixgo' || url.startsWith('/pixgo/');
        }
        return url === href || url.startsWith(href + '/');
    }

    return {
        page,
        homeHref,
        appSettings,
        appName,
        hasLogoFull,
        hasLogoIcon,
        navItems,
        navGroups,
        isActive,
    };
}
