<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Landing pública do BrasaPay (rota / para visitantes).
 * Conceito: "brasa acesa" — carvão + acento incandescente; a assinatura é a
 * simulação de uma venda PIX confirmando na hora, em loop, no hero.
 * Autossuficiente: estilos escopados em .lp-*, sem depender do shell do painel.
 */

const prefersReducedMotion = ref(false);

// — Simulação de venda PIX (hero) —
const SIM_SALES = [
    { buyer: 'Mariana P.', product: 'Mentoria Turbo', amount: 'R$ 197,00', secs: '1,8s' },
    { buyer: 'Carlos E.', product: 'Curso Tráfego Pro', amount: 'R$ 497,00', secs: '2,1s' },
    { buyer: 'Juliana S.', product: 'E-book Receitas', amount: 'R$ 47,90', secs: '1,4s' },
    { buyer: 'Rafael M.', product: 'Comunidade VIP', amount: 'R$ 97,00', secs: '2,4s' },
];
const simPhase = ref('aguardando'); // gerando | aguardando | pago
const simIndex = ref(0);
const simPulse = ref(false);
const sale = computed(() => SIM_SALES[simIndex.value % SIM_SALES.length]);
let simTimers = [];

function clearSimTimers() {
    simTimers.forEach((t) => clearTimeout(t));
    simTimers = [];
}

function runSimCycle() {
    clearSimTimers();
    simPhase.value = 'gerando';
    simTimers.push(setTimeout(() => {
        simPhase.value = 'aguardando';
    }, 700));
    simTimers.push(setTimeout(() => {
        simPhase.value = 'pago';
        simPulse.value = true;
        simTimers.push(setTimeout(() => (simPulse.value = false), 1200));
    }, 3100));
    simTimers.push(setTimeout(() => {
        simIndex.value += 1;
        runSimCycle();
    }, 6400));
}

// — Revelação ao rolar —
let observer = null;

onMounted(() => {
    prefersReducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion.value) {
        simPhase.value = 'pago';
    } else {
        runSimCycle();
    }

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lp-revealed');
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.15 }
    );
    document.querySelectorAll('.lp-reveal').forEach((el) => observer.observe(el));
});

onBeforeUnmount(() => {
    clearSimTimers();
    observer?.disconnect();
});

// — Conteúdo —
const acquirers = [
    { name: 'PIX', src: '/images/gateways/pix.svg' },
    { name: 'CajuPay', src: '/images/gateways/cajupay.png' },
    { name: 'Woovi', src: '/images/gateways/woovi.png' },
    { name: 'Mercado Pago', src: '/images/gateways/mercado-pago.webp' },
    { name: 'Pagar.me', src: '/images/gateways/pagarme.png' },
    { name: 'Efí', src: '/images/gateways/efi.png' },
    { name: 'Asaas', src: '/images/gateways/asaas.png' },
    { name: 'AssetPay', src: '/images/gateways/assetpay.svg' },
    { name: 'Reven', src: '/images/gateways/reven.svg' },
    { name: 'XPag', src: '/images/gateways/xpag_logo.svg' },
    { name: 'Okto', src: '/images/gateways/okto_payments.svg' },
    { name: 'Versell', src: '/images/gateways/versell.svg' },
    { name: 'Xflow', src: '/images/gateways/xflow_logo1.svg' },
];

const pillars = [
    {
        title: 'Dinheiro na conta na hora',
        text: 'O comprador paga o PIX e o pedido é confirmado em segundos — webhook recebido, status reconferido direto na adquirente e acesso liberado. Nada de esperar lote, nada de conferir manualmente.',
        tag: 'Aprovação instantânea',
    },
    {
        title: 'Uma adquirente caiu? Nem percebe.',
        text: 'Mais de dez adquirentes integradas com redundância automática: se uma recusa ou sai do ar, a cobrança é gerada na próxima da fila. Sua página de pagamento nunca fica fora do ar.',
        tag: 'Redundância automática',
    },
    {
        title: 'Blindagem contra MED',
        text: 'Disputa aberta no PIX? O sistema identifica a venda, trava o valor, avisa o vendedor responsável e mostra tudo para o administrador — com o histórico completo para responder a tempo.',
        tag: 'Proteção contra disputas',
    },
];

const checkoutFeatures = [
    'Modelos prontos de checkout — incluindo landing page completa dentro do próprio checkout',
    'Seções arrastáveis: título, vídeo, imagens, benefícios, avaliações e HTML livre',
    'Vídeo do YouTube ou VTurb embutido onde você quiser',
    'Order bump, cupons e provas sociais no mesmo fluxo',
];

const panelFeatures = [
    { title: '5 temas de painel', text: 'Default, Aurora, Kawaii, Prime e Studio — o visual que combina com a sua operação, no painel do vendedor e do admin.' },
    { title: 'Venda com som', text: 'Notificação push no celular com som de venda realizada. Você ouve o caixa tocar de onde estiver.' },
    { title: 'Saque automático', text: 'Saques enviados por PIX direto da adquirente, com reconciliação automática e comprovante.' },
    { title: 'E-mails transacionais', text: 'Acesso, recuperação e confirmação de compra enviados pelo seu provedor — Resend, SMTP ou o padrão da plataforma.' },
];

const year = new Date().getFullYear();
</script>

<template>
    <Head title="BrasaPay — Gateway de pagamentos PIX">
        <meta
            name="description"
            content="Gateway de pagamentos PIX para quem vende produtos digitais: aprovação em segundos, redundância de adquirentes, proteção contra MED e checkout que converte."
        />
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700;800&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div class="lp-root">
        <!-- Navegação -->
        <header class="lp-nav">
            <div class="lp-container lp-nav-inner">
                <a href="#" class="lp-wordmark" aria-label="BrasaPay — início">
                    <span class="lp-wordmark-flame" aria-hidden="true"></span>
                    Brasa<em>Pay</em>
                </a>
                <nav class="lp-nav-links" aria-label="Seções da página">
                    <a href="#recursos">Recursos</a>
                    <a href="#checkout">Checkout</a>
                    <a href="#painel">Painel</a>
                </nav>
                <div class="lp-nav-actions">
                    <Link href="/login" class="lp-btn lp-btn-ghost">Entrar</Link>
                    <Link href="/cadastro" class="lp-btn lp-btn-ember">Criar conta</Link>
                </div>
            </div>
        </header>

        <main>
            <!-- Hero -->
            <section class="lp-hero">
                <div class="lp-hero-glow" :class="{ 'lp-hero-glow-pulse': simPulse }" aria-hidden="true"></div>
                <div v-if="!prefersReducedMotion" class="lp-embers" aria-hidden="true">
                    <span v-for="n in 18" :key="n" class="lp-ember" :style="{ '--i': n }"></span>
                </div>

                <div class="lp-container lp-hero-grid">
                    <div class="lp-hero-copy">
                        <p class="lp-eyebrow">Gateway de pagamentos PIX</p>
                        <h1 class="lp-h1">
                            Vendeu.<br />
                            Caiu. <span class="lp-ember-text">Na&nbsp;hora.</span>
                        </h1>
                        <p class="lp-lead">
                            O gateway para quem vende produto digital no Brasil: PIX aprovado em segundos,
                            mais de dez adquirentes com redundância automática e proteção contra MED —
                            tudo num painel só.
                        </p>
                        <div class="lp-hero-ctas">
                            <Link href="/cadastro" class="lp-btn lp-btn-ember lp-btn-lg">Começar a vender</Link>
                            <a href="#recursos" class="lp-btn lp-btn-ghost lp-btn-lg">Conhecer os recursos</a>
                        </div>
                        <ul class="lp-hero-facts" aria-label="Destaques">
                            <li>Confirmação reconferida na adquirente</li>
                            <li>Checkout com landing page embutida</li>
                            <li>Saque automático por PIX</li>
                        </ul>
                    </div>

                    <!-- Assinatura: simulação de venda PIX -->
                    <div class="lp-sim" role="img" :aria-label="`Demonstração: cobrança PIX de ${sale.amount} confirmada em ${sale.secs}`">
                        <div class="lp-sim-card" :class="{ 'lp-sim-paid': simPhase === 'pago' }">
                            <div class="lp-sim-head">
                                <span class="lp-sim-dot" aria-hidden="true"></span>
                                <span class="lp-sim-product">{{ sale.product }}</span>
                                <span class="lp-sim-amount">{{ sale.amount }}</span>
                            </div>

                            <div class="lp-sim-body">
                                <div v-if="simPhase !== 'pago'" class="lp-sim-qr-wrap">
                                    <svg class="lp-sim-qr" :class="{ 'lp-sim-qr-loading': simPhase === 'gerando' }" viewBox="0 0 29 29" aria-hidden="true">
                                        <rect x="0" y="0" width="29" height="29" fill="none" />
                                        <path
                                            fill="currentColor"
                                            d="M0 0h7v7H0zM2 2h3v3H2zM22 0h7v7h-7zM24 2h3v3h-3zM0 22h7v7H0zM2 24h3v3H2zM9 0h2v2H9zM13 1h2v3h-2zM17 0h2v4h-2zM20 9h3v2h-3zM9 4h3v2H9zM10 9h2v3h-2zM13 6h4v2h-4zM9 13h2v4H9zM12 14h3v2h-3zM16 12h2v3h-2zM19 14h3v3h-3zM24 10h3v2h-3zM26 14h3v3h-3zM23 18h3v2h-3zM13 18h3v3h-3zM9 20h2v3H9zM17 21h2v4h-2zM20 23h3v2h-3zM24 21h2v3h-2zM13 24h2v3h-2zM10 26h2v2h-2zM20 27h4v2h-4zM26 24h3v3h-3zM4 9h2v4H4zM0 10h3v2H0zM1 15h4v2H1zM5 17h2v3H5zM0 18h3v2H0z"
                                        />
                                    </svg>
                                    <p class="lp-sim-status">
                                        <template v-if="simPhase === 'gerando'">Gerando cobrança PIX…</template>
                                        <template v-else>Aguardando pagamento</template>
                                    </p>
                                </div>

                                <div v-else class="lp-sim-paid-box">
                                    <span class="lp-sim-check" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 6 9 17l-5-5" />
                                        </svg>
                                    </span>
                                    <p class="lp-sim-paid-title">Pago · {{ sale.amount }}</p>
                                    <p class="lp-sim-paid-sub">confirmado em {{ sale.secs }}</p>
                                </div>
                            </div>

                            <div class="lp-sim-foot">
                                <span>{{ sale.buyer }}</span>
                                <span :class="simPhase === 'pago' ? 'lp-sim-foot-paid' : ''">
                                    {{ simPhase === 'pago' ? 'Pedido liberado' : 'PIX copia e cola' }}
                                </span>
                            </div>
                        </div>
                        <p class="lp-sim-caption">Assim que o PIX compensa, o pedido confirma — sem você fazer nada.</p>
                    </div>
                </div>
            </section>

            <!-- Adquirentes -->
            <section class="lp-acquirers" aria-label="Adquirentes integradas">
                <div class="lp-container">
                    <p class="lp-acquirers-label">Redundância real, nas adquirentes que o Brasil usa</p>
                </div>
                <div class="lp-marquee" :class="{ 'lp-marquee-static': prefersReducedMotion }">
                    <div class="lp-marquee-track">
                        <div v-for="dup in 2" :key="dup" class="lp-marquee-group" :aria-hidden="dup === 2">
                            <span v-for="a in acquirers" :key="dup + a.name" class="lp-acquirer">
                                <img :src="a.src" :alt="dup === 1 ? a.name : ''" loading="lazy" />
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Pilares -->
            <section id="recursos" class="lp-section">
                <div class="lp-container">
                    <p class="lp-eyebrow lp-reveal">Por que BrasaPay</p>
                    <h2 class="lp-h2 lp-reveal">Feito para o dinheiro <span class="lp-ember-text">não esfriar</span></h2>
                    <div class="lp-pillars">
                        <article v-for="p in pillars" :key="p.title" class="lp-card lp-reveal">
                            <p class="lp-card-tag">{{ p.tag }}</p>
                            <h3 class="lp-h3">{{ p.title }}</h3>
                            <p class="lp-card-text">{{ p.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Checkout -->
            <section id="checkout" class="lp-section lp-section-alt">
                <div class="lp-container lp-split">
                    <div class="lp-split-copy lp-reveal">
                        <p class="lp-eyebrow">Checkout</p>
                        <h2 class="lp-h2">Um checkout que é a sua <span class="lp-ember-text">página de vendas</span></h2>
                        <p class="lp-lead-sm">
                            Escolha um modelo pronto ou monte uma landing page inteira dentro do checkout:
                            vídeo, imagens, benefícios e avaliações na ordem que você quiser — o botão de
                            compra está sempre a um toque.
                        </p>
                        <ul class="lp-checklist">
                            <li v-for="f in checkoutFeatures" :key="f">{{ f }}</li>
                        </ul>
                        <Link href="/cadastro" class="lp-btn lp-btn-ember">Montar meu checkout</Link>
                    </div>
                    <div class="lp-split-visual lp-reveal" aria-hidden="true">
                        <div class="lp-mock">
                            <div class="lp-mock-bar"><span></span><span></span><span></span></div>
                            <div class="lp-mock-hero"></div>
                            <div class="lp-mock-row">
                                <div class="lp-mock-col">
                                    <div class="lp-mock-line w-80"></div>
                                    <div class="lp-mock-line w-60"></div>
                                    <div class="lp-mock-video"></div>
                                    <div class="lp-mock-line w-90"></div>
                                    <div class="lp-mock-line w-70"></div>
                                </div>
                                <div class="lp-mock-pay">
                                    <div class="lp-mock-line w-60"></div>
                                    <div class="lp-mock-input"></div>
                                    <div class="lp-mock-input"></div>
                                    <div class="lp-mock-btn">Pagar com PIX</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Painel -->
            <section id="painel" class="lp-section">
                <div class="lp-container">
                    <p class="lp-eyebrow lp-reveal">Painel</p>
                    <h2 class="lp-h2 lp-reveal">A operação inteira, <span class="lp-ember-text">do seu jeito</span></h2>
                    <div class="lp-panel-grid">
                        <article v-for="f in panelFeatures" :key="f.title" class="lp-card lp-card-sm lp-reveal">
                            <h3 class="lp-h3-sm">{{ f.title }}</h3>
                            <p class="lp-card-text">{{ f.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- CTA final -->
            <section class="lp-final">
                <div class="lp-final-glow" aria-hidden="true"></div>
                <div class="lp-container lp-final-inner lp-reveal">
                    <h2 class="lp-h2">Acenda a sua operação</h2>
                    <p class="lp-lead-sm">Crie a conta, conecte uma adquirente e gere a primeira cobrança PIX hoje.</p>
                    <div class="lp-hero-ctas lp-final-ctas">
                        <Link href="/cadastro" class="lp-btn lp-btn-ember lp-btn-lg">Criar conta</Link>
                        <Link href="/login" class="lp-btn lp-btn-ghost lp-btn-lg">Já tenho conta</Link>
                    </div>
                </div>
            </section>
        </main>

        <footer class="lp-footer">
            <div class="lp-container lp-footer-inner">
                <span class="lp-wordmark lp-wordmark-sm">Brasa<em>Pay</em></span>
                <nav class="lp-footer-links" aria-label="Legal">
                    <Link href="/termos-de-uso">Termos de uso</Link>
                    <Link href="/politica-privacidade">Política de privacidade</Link>
                </nav>
                <span class="lp-footer-copy">© {{ year }} BrasaPay</span>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* — Tokens da landing (independentes do painel) — */
.lp-root {
    --lp-carvao: #0b0908;
    --lp-fuligem: #171210;
    --lp-borda: #2b221c;
    --lp-brasa: #ff4a1a;
    --lp-ambar: #ffb348;
    --lp-osso: #faf5f0;
    --lp-cinza: #a89a8f;
    --lp-display: 'Unbounded', 'Lexend', ui-sans-serif, system-ui, sans-serif;
    --lp-body: 'Lexend', ui-sans-serif, system-ui, sans-serif;

    background: var(--lp-carvao);
    color: var(--lp-osso);
    font-family: var(--lp-body);
    font-weight: 300;
    min-height: 100vh;
    overflow-x: hidden;
}

.lp-container {
    width: min(1120px, 100% - 2.5rem);
    margin-inline: auto;
}

/* — Tipografia — */
.lp-h1 {
    font-family: var(--lp-display);
    font-weight: 800;
    font-size: clamp(2.3rem, 5.2vw, 4.1rem);
    line-height: 1.04;
    letter-spacing: -0.01em;
    margin: 0 0 1.25rem;
}
.lp-h2 {
    font-family: var(--lp-display);
    font-weight: 700;
    font-size: clamp(1.6rem, 3.4vw, 2.5rem);
    line-height: 1.12;
    margin: 0 0 1rem;
}
.lp-h3 {
    font-size: 1.22rem;
    font-weight: 500;
    margin: 0 0 0.6rem;
}
.lp-h3-sm {
    font-size: 1.05rem;
    font-weight: 500;
    margin: 0 0 0.45rem;
}
.lp-eyebrow {
    font-family: var(--lp-display);
    font-weight: 500;
    font-size: 0.72rem;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--lp-brasa);
    margin: 0 0 0.9rem;
}
.lp-lead {
    font-size: 1.12rem;
    line-height: 1.65;
    color: var(--lp-cinza);
    max-width: 34rem;
    margin: 0 0 2rem;
}
.lp-lead-sm {
    font-size: 1.02rem;
    line-height: 1.65;
    color: var(--lp-cinza);
    max-width: 32rem;
    margin: 0 0 1.5rem;
}
.lp-ember-text {
    background: linear-gradient(100deg, var(--lp-brasa) 10%, var(--lp-ambar) 90%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}

/* — Botões — */
.lp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 0.62rem 1.35rem;
    font-size: 0.92rem;
    font-weight: 500;
    text-decoration: none;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, border-color 0.18s ease;
    border: 1px solid transparent;
    cursor: pointer;
}
.lp-btn-lg {
    padding: 0.85rem 1.8rem;
    font-size: 1rem;
}
.lp-btn-ember {
    background: linear-gradient(110deg, var(--lp-brasa), #ff6a2b 55%, var(--lp-ambar));
    color: #1a0c05;
    box-shadow: 0 8px 28px -8px rgba(255, 74, 26, 0.55);
}
.lp-btn-ember:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 34px -8px rgba(255, 94, 26, 0.7);
}
.lp-btn-ghost {
    color: var(--lp-osso);
    border-color: var(--lp-borda);
    background: rgba(250, 245, 240, 0.03);
}
.lp-btn-ghost:hover {
    border-color: var(--lp-brasa);
    background: rgba(255, 74, 26, 0.08);
}
.lp-btn:focus-visible {
    outline: 2px solid var(--lp-ambar);
    outline-offset: 3px;
}

/* — Navegação — */
.lp-nav {
    position: sticky;
    top: 0;
    z-index: 40;
    backdrop-filter: blur(14px);
    background: rgba(11, 9, 8, 0.78);
    border-bottom: 1px solid rgba(43, 34, 28, 0.6);
}
.lp-nav-inner {
    display: flex;
    align-items: center;
    gap: 2rem;
    height: 64px;
}
.lp-wordmark {
    font-family: var(--lp-display);
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--lp-osso);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    letter-spacing: 0.01em;
}
.lp-wordmark em {
    font-style: normal;
    background: linear-gradient(100deg, var(--lp-brasa), var(--lp-ambar));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.lp-wordmark-flame {
    width: 10px;
    height: 10px;
    border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    background: linear-gradient(135deg, var(--lp-ambar), var(--lp-brasa));
    box-shadow: 0 0 14px 2px rgba(255, 94, 26, 0.6);
}
.lp-nav-links {
    display: flex;
    gap: 1.6rem;
    margin-left: auto;
}
.lp-nav-links a {
    color: var(--lp-cinza);
    text-decoration: none;
    font-size: 0.9rem;
    transition: color 0.15s ease;
}
.lp-nav-links a:hover {
    color: var(--lp-osso);
}
.lp-nav-actions {
    display: flex;
    gap: 0.6rem;
}
@media (max-width: 720px) {
    .lp-nav-links {
        display: none;
    }
    .lp-nav-actions {
        margin-left: auto;
    }
}

/* — Hero — */
.lp-hero {
    position: relative;
    padding: clamp(4rem, 9vh, 7rem) 0 clamp(3.5rem, 8vh, 6rem);
    isolation: isolate;
}
.lp-hero-glow {
    position: absolute;
    inset: -20% -10% auto;
    height: 130%;
    background:
        radial-gradient(42% 46% at 72% 42%, rgba(255, 74, 26, 0.22), transparent 70%),
        radial-gradient(30% 34% at 78% 55%, rgba(255, 179, 72, 0.12), transparent 70%);
    z-index: -1;
    transition: opacity 0.6s ease;
    pointer-events: none;
}
.lp-hero-glow-pulse {
    animation: lp-glow-pulse 1.1s ease;
}
@keyframes lp-glow-pulse {
    40% {
        opacity: 1.8;
        filter: saturate(1.4);
    }
}
.lp-hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
    gap: clamp(2rem, 5vw, 4.5rem);
    align-items: center;
}
@media (max-width: 900px) {
    .lp-hero-grid {
        grid-template-columns: 1fr;
    }
}
.lp-hero-ctas {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
    margin-bottom: 1.8rem;
}
.lp-hero-facts {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 1.4rem;
    padding: 0;
    margin: 0;
    color: var(--lp-cinza);
    font-size: 0.86rem;
}
.lp-hero-facts li {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}
.lp-hero-facts li::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--lp-brasa);
    box-shadow: 0 0 8px rgba(255, 74, 26, 0.8);
}

/* — Partículas de brasa — */
.lp-embers {
    position: absolute;
    inset: 0;
    z-index: -1;
    overflow: hidden;
    pointer-events: none;
}
.lp-ember {
    position: absolute;
    bottom: -8px;
    left: calc((var(--i) * 53%) % 100%);
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--lp-ambar);
    opacity: 0;
    filter: blur(0.5px);
    animation: lp-ember-rise calc(6s + (var(--i) * 0.7s)) linear infinite;
    animation-delay: calc(var(--i) * -1.3s);
}
@keyframes lp-ember-rise {
    0% {
        transform: translateY(0) translateX(0) scale(1);
        opacity: 0;
    }
    8% {
        opacity: 0.7;
    }
    60% {
        opacity: 0.35;
    }
    100% {
        transform: translateY(-78vh) translateX(3.5rem) scale(0.4);
        opacity: 0;
    }
}

/* — Simulação PIX — */
.lp-sim {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.9rem;
}
.lp-sim-card {
    width: min(380px, 100%);
    background: linear-gradient(170deg, rgba(34, 25, 20, 0.92), rgba(18, 13, 11, 0.96));
    border: 1px solid var(--lp-borda);
    border-radius: 20px;
    padding: 1.1rem 1.2rem 1rem;
    box-shadow: 0 28px 70px -30px rgba(0, 0, 0, 0.85);
    transition: border-color 0.4s ease, box-shadow 0.4s ease;
}
.lp-sim-paid {
    border-color: rgba(255, 110, 40, 0.55);
    box-shadow:
        0 28px 70px -30px rgba(0, 0, 0, 0.85),
        0 0 44px -6px rgba(255, 74, 26, 0.35);
}
.lp-sim-head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid rgba(43, 34, 28, 0.8);
}
.lp-sim-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--lp-brasa);
    box-shadow: 0 0 10px rgba(255, 74, 26, 0.9);
}
.lp-sim-product {
    font-size: 0.88rem;
    color: var(--lp-osso);
    font-weight: 400;
}
.lp-sim-amount {
    margin-left: auto;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
}
.lp-sim-body {
    min-height: 196px;
    display: grid;
    place-items: center;
    padding: 1rem 0;
}
.lp-sim-qr-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.8rem;
}
.lp-sim-qr {
    width: 128px;
    height: 128px;
    color: var(--lp-osso);
    background: #fff;
    border-radius: 10px;
    padding: 8px;
    color: #14100d;
    transition: opacity 0.3s ease, filter 0.3s ease;
}
.lp-sim-qr-loading {
    opacity: 0.25;
    filter: blur(2px);
}
.lp-sim-status {
    margin: 0;
    font-size: 0.85rem;
    color: var(--lp-cinza);
}
.lp-sim-paid-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.35rem;
    animation: lp-pop 0.45s cubic-bezier(0.2, 1.4, 0.4, 1);
}
@keyframes lp-pop {
    from {
        transform: scale(0.82);
        opacity: 0;
    }
}
.lp-sim-check {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, var(--lp-brasa), var(--lp-ambar));
    color: #1a0c05;
    margin-bottom: 0.4rem;
    box-shadow: 0 0 30px -4px rgba(255, 94, 26, 0.65);
}
.lp-sim-check svg {
    width: 28px;
    height: 28px;
}
.lp-sim-paid-title {
    margin: 0;
    font-weight: 500;
    font-size: 1.12rem;
}
.lp-sim-paid-sub {
    margin: 0;
    font-size: 0.85rem;
    color: var(--lp-cinza);
}
.lp-sim-foot {
    display: flex;
    justify-content: space-between;
    padding-top: 0.85rem;
    border-top: 1px solid rgba(43, 34, 28, 0.8);
    font-size: 0.8rem;
    color: var(--lp-cinza);
}
.lp-sim-foot-paid {
    color: var(--lp-ambar);
}
.lp-sim-caption {
    margin: 0;
    font-size: 0.82rem;
    color: var(--lp-cinza);
    text-align: center;
    max-width: 22rem;
}

/* — Adquirentes / marquee — */
.lp-acquirers {
    padding: 1.5rem 0 0.5rem;
    border-block: 1px solid rgba(43, 34, 28, 0.5);
    background: rgba(23, 18, 16, 0.4);
}
.lp-acquirers-label {
    text-align: center;
    font-size: 0.78rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--lp-cinza);
    margin: 0 0 1rem;
}
.lp-marquee {
    overflow: hidden;
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
}
.lp-marquee-track {
    display: flex;
    width: max-content;
    animation: lp-marquee 42s linear infinite;
}
.lp-marquee-static .lp-marquee-track {
    animation: none;
    flex-wrap: wrap;
    width: 100%;
    justify-content: center;
}
@keyframes lp-marquee {
    to {
        transform: translateX(-50%);
    }
}
.lp-marquee-group {
    display: flex;
    align-items: center;
}
.lp-acquirer {
    display: grid;
    place-items: center;
    padding: 0.9rem 1.9rem 1.4rem;
}
.lp-acquirer img {
    height: 26px;
    width: auto;
    max-width: 120px;
    object-fit: contain;
    filter: grayscale(1) brightness(1.6) opacity(0.55);
    transition: filter 0.25s ease;
}
.lp-acquirer:hover img {
    filter: none;
}

/* — Seções — */
.lp-section {
    padding: clamp(3.5rem, 9vh, 6.5rem) 0;
}
.lp-section-alt {
    background: linear-gradient(180deg, rgba(23, 18, 16, 0.55), transparent);
    border-block: 1px solid rgba(43, 34, 28, 0.4);
}
.lp-pillars {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.1rem;
    margin-top: 2.2rem;
}
@media (max-width: 900px) {
    .lp-pillars {
        grid-template-columns: 1fr;
    }
}
.lp-card {
    background: var(--lp-fuligem);
    border: 1px solid var(--lp-borda);
    border-radius: 18px;
    padding: 1.6rem 1.5rem;
    transition: border-color 0.25s ease, transform 0.25s ease;
}
.lp-card:hover {
    border-color: rgba(255, 94, 26, 0.45);
    transform: translateY(-3px);
}
.lp-card-sm {
    padding: 1.25rem 1.3rem;
}
.lp-card-tag {
    font-size: 0.7rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--lp-ambar);
    margin: 0 0 0.8rem;
}
.lp-card-text {
    margin: 0;
    font-size: 0.93rem;
    line-height: 1.6;
    color: var(--lp-cinza);
}

/* — Split (checkout) — */
.lp-split {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: clamp(2rem, 5vw, 4rem);
    align-items: center;
}
@media (max-width: 900px) {
    .lp-split {
        grid-template-columns: 1fr;
    }
}
.lp-checklist {
    list-style: none;
    padding: 0;
    margin: 0 0 1.8rem;
    display: grid;
    gap: 0.65rem;
}
.lp-checklist li {
    display: flex;
    gap: 0.6rem;
    font-size: 0.93rem;
    color: var(--lp-osso);
    line-height: 1.5;
}
.lp-checklist li::before {
    content: '';
    flex: none;
    width: 16px;
    height: 16px;
    margin-top: 2px;
    border-radius: 50%;
    background:
        linear-gradient(135deg, var(--lp-brasa), var(--lp-ambar)) padding-box;
    -webkit-mask: radial-gradient(circle at center, #000 52%, transparent 56%);
    mask: radial-gradient(circle at center, #000 52%, transparent 56%);
}

/* Mock de checkout */
.lp-mock {
    background: var(--lp-fuligem);
    border: 1px solid var(--lp-borda);
    border-radius: 18px;
    padding: 0.9rem;
    box-shadow: 0 30px 70px -36px rgba(0, 0, 0, 0.9);
}
.lp-mock-bar {
    display: flex;
    gap: 5px;
    margin-bottom: 0.8rem;
}
.lp-mock-bar span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--lp-borda);
}
.lp-mock-hero {
    height: 64px;
    border-radius: 10px;
    background: linear-gradient(110deg, rgba(255, 74, 26, 0.35), rgba(255, 179, 72, 0.2));
    margin-bottom: 0.8rem;
}
.lp-mock-row {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 0.8rem;
}
.lp-mock-col,
.lp-mock-pay {
    display: grid;
    gap: 0.55rem;
    align-content: start;
}
.lp-mock-pay {
    background: rgba(11, 9, 8, 0.6);
    border: 1px solid var(--lp-borda);
    border-radius: 12px;
    padding: 0.8rem;
}
.lp-mock-line {
    height: 9px;
    border-radius: 5px;
    background: rgba(168, 154, 143, 0.22);
}
.lp-mock-line.w-60 { width: 60%; }
.lp-mock-line.w-70 { width: 70%; }
.lp-mock-line.w-80 { width: 80%; }
.lp-mock-line.w-90 { width: 90%; }
.lp-mock-video {
    height: 86px;
    border-radius: 10px;
    background: rgba(168, 154, 143, 0.1);
    display: grid;
    place-items: center;
}
.lp-mock-video::before {
    content: '';
    width: 0;
    height: 0;
    border-left: 14px solid rgba(250, 245, 240, 0.55);
    border-block: 9px solid transparent;
}
.lp-mock-input {
    height: 26px;
    border-radius: 7px;
    border: 1px solid var(--lp-borda);
    background: rgba(168, 154, 143, 0.07);
}
.lp-mock-btn {
    margin-top: 0.2rem;
    height: 34px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    font-size: 0.78rem;
    font-weight: 500;
    color: #1a0c05;
    background: linear-gradient(110deg, var(--lp-brasa), var(--lp-ambar));
}

/* — Painel — */
.lp-panel-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 2.2rem;
}
@media (max-width: 1000px) {
    .lp-panel-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 580px) {
    .lp-panel-grid {
        grid-template-columns: 1fr;
    }
}

/* — CTA final — */
.lp-final {
    position: relative;
    padding: clamp(4rem, 10vh, 7rem) 0;
    text-align: center;
    isolation: isolate;
    overflow: hidden;
}
.lp-final-glow {
    position: absolute;
    inset: auto -20% -60%;
    height: 120%;
    background: radial-gradient(50% 55% at 50% 100%, rgba(255, 74, 26, 0.3), transparent 70%);
    z-index: -1;
}
.lp-final-ctas {
    justify-content: center;
    margin-top: 1.6rem;
    margin-bottom: 0;
}

/* — Footer — */
.lp-footer {
    border-top: 1px solid rgba(43, 34, 28, 0.6);
    padding: 1.6rem 0;
}
.lp-footer-inner {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.lp-wordmark-sm {
    font-size: 0.95rem;
}
.lp-footer-links {
    display: flex;
    gap: 1.2rem;
    margin-left: auto;
}
.lp-footer-links a {
    color: var(--lp-cinza);
    font-size: 0.84rem;
    text-decoration: none;
}
.lp-footer-links a:hover {
    color: var(--lp-osso);
}
.lp-footer-copy {
    color: var(--lp-cinza);
    font-size: 0.84rem;
}
@media (max-width: 640px) {
    .lp-footer-inner {
        flex-direction: column;
        gap: 0.8rem;
    }
    .lp-footer-links {
        margin-left: 0;
    }
}

/* — Revelação ao rolar — */
.lp-reveal {
    opacity: 0;
    transform: translateY(18px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}
.lp-revealed {
    opacity: 1;
    transform: none;
}

@media (prefers-reduced-motion: reduce) {
    .lp-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
    .lp-marquee-track,
    .lp-ember,
    .lp-hero-glow-pulse,
    .lp-sim-paid-box {
        animation: none !important;
    }
    .lp-btn,
    .lp-card {
        transition: none;
    }
}
</style>
