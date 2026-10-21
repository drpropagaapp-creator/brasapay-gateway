<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Landing pública (rota / para visitantes) — estilo imersivo dark-green:
 * fundo preto-esverdeado com auroras de luz, CTAs em degradê lime→esmeralda
 * (acento do design system Paynext), logo oficial, cards flutuantes de
 * produto no hero (venda aprovada, PIX, push) e bento de recursos.
 */

const prefersReducedMotion = ref(false);

// — Simulação de venda PIX (card flutuante do hero) —
const SIM_SALES = [
    { product: 'Mentoria Turbo', amount: 'R$ 497,00', secs: '1,8s' },
    { product: 'Curso Tráfego Pro', amount: 'R$ 197,00', secs: '2,1s' },
    { product: 'Comunidade VIP', amount: 'R$ 97,00', secs: '1,4s' },
];
const simPhase = ref('pago'); // aguardando | pago
const simIndex = ref(0);
const sale = computed(() => SIM_SALES[simIndex.value % SIM_SALES.length]);
let simTimers = [];

function runSimCycle() {
    simTimers.forEach((t) => clearTimeout(t));
    simTimers = [];
    simPhase.value = 'aguardando';
    simTimers.push(setTimeout(() => {
        simPhase.value = 'pago';
    }, 2600));
    simTimers.push(setTimeout(() => {
        simIndex.value += 1;
        runSimCycle();
    }, 6200));
}

// — Parallax suave dos cards do hero —
const tiltX = ref(0);
const tiltY = ref(0);

function onHeroMouse(e) {
    if (prefersReducedMotion.value) return;
    const { innerWidth: w, innerHeight: h } = window;
    tiltX.value = (e.clientX / w - 0.5) * 2;
    tiltY.value = (e.clientY / h - 0.5) * 2;
}

function floatStyle(depth) {
    return {
        transform: `translate3d(${(tiltX.value * depth * 10).toFixed(1)}px, ${(tiltY.value * depth * 8).toFixed(1)}px, 0)`,
    };
}

// — Revelação ao rolar —
let observer = null;

onMounted(() => {
    prefersReducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!prefersReducedMotion.value) {
        runSimCycle();
        window.addEventListener('mousemove', onHeroMouse, { passive: true });
    }

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lp-in');
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.12 }
    );
    document.querySelectorAll('.lp-reveal').forEach((el) => observer.observe(el));
});

onBeforeUnmount(() => {
    simTimers.forEach((t) => clearTimeout(t));
    window.removeEventListener('mousemove', onHeroMouse);
    observer?.disconnect();
});

// — Conteúdo —
const stats = [
    { value: 'D+0', label: 'PIX na conta na hora' },
    { value: '10+', label: 'adquirentes com redundância' },
    { value: '~2s', label: 'da compra à confirmação' },
    { value: '24/7', label: 'saque automático por PIX' },
];

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
];

const checkoutFeatures = [
    'Modelos prontos — incluindo landing page completa dentro do checkout',
    'Vídeo do YouTube ou VTurb, imagens, benefícios e avaliações na ordem que você quiser',
    'Order bump, cupom e prova social no mesmo fluxo',
    'Pixels e UTMs rastreados até a conversão',
];

const year = new Date().getFullYear();
</script>

<template>
    <Head title="BrasaPay — venda no digital e receba na hora">
        <meta
            name="description"
            content="Gateway de pagamentos para quem vende no digital: PIX aprovado em segundos, redundância de adquirentes, proteção contra MED, checkout que converte e saque automático."
        />
    </Head>

    <div class="lp">
        <!-- Auroras de fundo -->
        <div class="lp-aurora lp-aurora-a" aria-hidden="true"></div>
        <div class="lp-aurora lp-aurora-b" aria-hidden="true"></div>

        <!-- Navegação -->
        <header class="lp-nav">
            <div class="lp-wrap lp-nav-in">
                <a href="#" class="lp-logo" aria-label="BrasaPay — início">
                    <img src="/images/logo-dark.png" alt="BrasaPay" />
                </a>
                <nav class="lp-nav-links" aria-label="Seções">
                    <a href="#recursos">Recursos</a>
                    <a href="#checkout">Checkout</a>
                    <a href="#adquirentes">Adquirentes</a>
                </nav>
                <div class="lp-nav-cta">
                    <Link href="/login" class="lp-btn lp-btn-ghost">Entrar</Link>
                    <Link href="/cadastro" class="lp-btn lp-btn-green">Criar conta grátis</Link>
                </div>
            </div>
        </header>

        <main>
            <!-- Hero -->
            <section class="lp-hero">
                <div class="lp-wrap">
                    <div class="lp-hero-center">
                        <p class="lp-pill">💚 Você só paga quando vende</p>
                        <h1 class="lp-h1">
                            O gateway pra vender<br />
                            no digital e <span class="lp-grad">receber na&nbsp;hora.</span>
                        </h1>
                        <p class="lp-sub">
                            PIX aprovado em segundos, mais de dez adquirentes com redundância automática,
                            blindagem contra MED e um checkout que é a sua página de vendas.
                        </p>
                        <div class="lp-hero-ctas">
                            <Link href="/cadastro" class="lp-btn lp-btn-green lp-btn-xl">
                                Começar a vender <span aria-hidden="true">→</span>
                            </Link>
                            <a href="#recursos" class="lp-btn lp-btn-ghost lp-btn-xl">Ver recursos</a>
                        </div>
                    </div>

                    <!-- Palco com cards flutuantes -->
                    <div class="lp-stage" aria-hidden="true">
                        <div class="lp-stage-glow"></div>

                        <!-- Card: venda aprovada -->
                        <div class="lp-float lp-float-sale" :style="floatStyle(1)">
                            <div class="lp-sale-badge">VENDA APROVADA!</div>
                            <div class="lp-sale-row">
                                <span class="lp-sale-check">✓</span>
                                <div>
                                    <p class="lp-sale-amount">{{ sale.amount }}</p>
                                    <p class="lp-sale-sub">{{ sale.product }} · em {{ sale.secs }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Card: PIX -->
                        <div class="lp-float lp-float-pix" :style="floatStyle(1.6)">
                            <div class="lp-pix-head">
                                <span class="lp-dot" :class="{ 'lp-dot-live': simPhase === 'aguardando' }"></span>
                                PIX copia e cola
                            </div>
                            <div class="lp-pix-qr" :class="{ 'lp-pix-qr-paid': simPhase === 'pago' }">
                                <svg viewBox="0 0 29 29">
                                    <path
                                        fill="currentColor"
                                        d="M0 0h7v7H0zM2 2h3v3H2zM22 0h7v7h-7zM24 2h3v3h-3zM0 22h7v7H0zM2 24h3v3H2zM9 0h2v2H9zM13 1h2v3h-2zM17 0h2v4h-2zM20 9h3v2h-3zM9 4h3v2H9zM10 9h2v3h-2zM13 6h4v2h-4zM9 13h2v4H9zM12 14h3v2h-3zM16 12h2v3h-2zM19 14h3v3h-3zM24 10h3v2h-3zM26 14h3v3h-3zM23 18h3v2h-3zM13 18h3v3h-3zM9 20h2v3H9zM17 21h2v4h-2zM20 23h3v2h-3zM24 21h2v3h-2zM13 24h2v3h-2zM10 26h2v2h-2zM20 27h4v2h-4zM26 24h3v3h-3zM4 9h2v4H4zM0 10h3v2H0zM1 15h4v2H1zM5 17h2v3H5zM0 18h3v2H0z"
                                    />
                                </svg>
                                <span v-if="simPhase === 'pago'" class="lp-pix-paid">✓ Pago</span>
                            </div>
                        </div>

                        <!-- Card: push de venda -->
                        <div class="lp-float lp-float-push" :style="floatStyle(2.2)">
                            <span class="lp-push-icon">🔔</span>
                            <div>
                                <p class="lp-push-title">BrasaPay</p>
                                <p class="lp-push-text">🤑 Venda realizada — {{ sale.amount }}</p>
                            </div>
                        </div>

                        <!-- Card: saldo -->
                        <div class="lp-float lp-float-balance" :style="floatStyle(1.3)">
                            <p class="lp-balance-label">Saldo disponível</p>
                            <p class="lp-balance-value">R$ 12.480,90</p>
                            <div class="lp-balance-btn">Sacar via PIX</div>
                        </div>
                    </div>

                    <!-- Banda de fatos -->
                    <dl class="lp-stats lp-reveal">
                        <div v-for="s in stats" :key="s.value" class="lp-stat">
                            <dt class="lp-stat-value">{{ s.value }}</dt>
                            <dd class="lp-stat-label">{{ s.label }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- Adquirentes -->
            <section id="adquirentes" class="lp-acq" aria-label="Adquirentes integradas">
                <p class="lp-acq-label">REDUNDÂNCIA REAL, NAS ADQUIRENTES QUE O BRASIL USA</p>
                <div class="lp-marquee" :class="{ 'lp-marquee-off': prefersReducedMotion }">
                    <div class="lp-marquee-track">
                        <div v-for="dup in 2" :key="dup" class="lp-marquee-group" :aria-hidden="dup === 2" :class="{ 'lp-marquee-dup': dup === 2 }">
                            <span v-for="a in acquirers" :key="dup + a.name" class="lp-chip">
                                <img :src="a.src" :alt="dup === 1 ? a.name : ''" loading="lazy" />
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Bento de recursos -->
            <section id="recursos" class="lp-sec">
                <div class="lp-wrap">
                    <p class="lp-eyebrow lp-reveal">TECNOLOGIA PRÓPRIA</p>
                    <h2 class="lp-h2 lp-reveal">Cada recurso existe pra uma coisa:<br /><span class="lp-grad">te fazer vender mais</span></h2>

                    <div class="lp-bento">
                        <!-- Aprovação instantânea -->
                        <article class="lp-cell lp-cell-wide lp-reveal">
                            <p class="lp-cell-tag">PIX · APROVAÇÃO INSTANTÂNEA</p>
                            <h3 class="lp-h3">Pagou, caiu, liberou</h3>
                            <p class="lp-cell-text">
                                O webhook chega, o status é reconferido direto na adquirente e o pedido confirma
                                em segundos — acesso liberado e e-mail enviado sem você tocar em nada.
                            </p>
                            <div class="lp-mini-timeline">
                                <span class="lp-step lp-step-done">PIX pago</span>
                                <span class="lp-step-line"></span>
                                <span class="lp-step lp-step-done">Reconferido na API</span>
                                <span class="lp-step-line"></span>
                                <span class="lp-step lp-step-glow">Pedido liberado · 1,8s</span>
                            </div>
                        </article>

                        <!-- MED -->
                        <article class="lp-cell lp-reveal">
                            <p class="lp-cell-tag">BLINDAGEM MED</p>
                            <h3 class="lp-h3">Disputa sob controle</h3>
                            <p class="lp-cell-text">
                                MED aberto? O sistema acha a venda, trava o valor, avisa o vendedor e mostra tudo
                                pro admin — com prazo pra responder.
                            </p>
                            <div class="lp-mini-med">
                                <span class="lp-med-badge">MED · em análise</span>
                                <span class="lp-med-amount">R$ 497,00 travados</span>
                            </div>
                        </article>

                        <!-- Push com som -->
                        <article class="lp-cell lp-reveal">
                            <p class="lp-cell-tag">PUSH COM SOM</p>
                            <h3 class="lp-h3">Você ouve o caixa tocar</h3>
                            <p class="lp-cell-text">
                                Notificação no celular com som de venda realizada. De onde estiver, você sabe
                                que vendeu.
                            </p>
                            <div class="lp-mini-push">
                                <span>🔊</span> cha-ching!
                            </div>
                        </article>

                        <!-- Redundância -->
                        <article class="lp-cell lp-reveal">
                            <p class="lp-cell-tag">REDUNDÂNCIA AUTOMÁTICA</p>
                            <h3 class="lp-h3">Adquirente caiu? Nem percebe</h3>
                            <p class="lp-cell-text">
                                Se uma adquirente recusa ou sai do ar, a cobrança nasce na próxima da fila.
                                Seu checkout nunca fica fora do ar.
                            </p>
                        </article>

                        <!-- Saque -->
                        <article class="lp-cell lp-cell-wide lp-reveal">
                            <p class="lp-cell-tag">FINANCEIRO</p>
                            <h3 class="lp-h3">Saque automático, comprovante na mão</h3>
                            <p class="lp-cell-text">
                                Saques enviados por PIX direto da adquirente, com reconciliação automática,
                                comprovante e trilha de auditoria. E-mails transacionais pelo seu provedor —
                                Resend ou SMTP.
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Checkout -->
            <section id="checkout" class="lp-sec lp-sec-glass">
                <div class="lp-wrap lp-split">
                    <div class="lp-reveal">
                        <p class="lp-eyebrow">BRASAPAY · CHECKOUT</p>
                        <h2 class="lp-h2">Um checkout que é a sua <span class="lp-grad">página de vendas</span></h2>
                        <p class="lp-sub-sm">
                            Escolha um modelo pronto ou monte uma landing inteira dentro do checkout — o botão
                            de compra está sempre a um toque.
                        </p>
                        <ul class="lp-list">
                            <li v-for="f in checkoutFeatures" :key="f">{{ f }}</li>
                        </ul>
                        <Link href="/cadastro" class="lp-btn lp-btn-green">Montar meu checkout</Link>
                    </div>
                    <div class="lp-reveal" aria-hidden="true">
                        <div class="lp-mock">
                            <div class="lp-mock-url">checkout.suamarca.com</div>
                            <div class="lp-mock-hero"></div>
                            <div class="lp-mock-grid">
                                <div class="lp-mock-left">
                                    <div class="lp-skel w-80"></div>
                                    <div class="lp-skel w-55"></div>
                                    <div class="lp-mock-video">▶</div>
                                    <div class="lp-skel w-90"></div>
                                </div>
                                <div class="lp-mock-pay">
                                    <div class="lp-skel w-60"></div>
                                    <div class="lp-mock-input"></div>
                                    <div class="lp-mock-input"></div>
                                    <div class="lp-mock-buy">Pagar com PIX</div>
                                    <div class="lp-mock-safe">🔒 Pagamento seguro</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- CTA final -->
            <section class="lp-final">
                <div class="lp-final-glow" aria-hidden="true"></div>
                <div class="lp-wrap lp-final-in lp-reveal">
                    <img src="/images/logo-dark.png" alt="" class="lp-final-logo" aria-hidden="true" />
                    <h2 class="lp-h2">Crie a conta e gere a primeira<br />cobrança <span class="lp-grad">hoje</span></h2>
                    <div class="lp-hero-ctas lp-final-ctas">
                        <Link href="/cadastro" class="lp-btn lp-btn-green lp-btn-xl">Criar conta grátis</Link>
                        <Link href="/login" class="lp-btn lp-btn-ghost lp-btn-xl">Já tenho conta</Link>
                    </div>
                </div>
            </section>
        </main>

        <footer class="lp-footer">
            <div class="lp-wrap lp-footer-in">
                <img src="/images/logo-dark.png" alt="BrasaPay" class="lp-footer-logo" />
                <nav class="lp-footer-links" aria-label="Legal">
                    <Link href="/termos-de-uso">Termos de uso</Link>
                    <Link href="/politica-privacidade">Política de privacidade</Link>
                </nav>
                <span class="lp-footer-copy">© {{ year }}</span>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* — Tokens — */
.lp {
    --bg: #070c09;
    --panel: rgba(16, 24, 19, 0.75);
    --line: rgba(120, 180, 140, 0.14);
    --green-1: #cfeb2b; /* lime do design system */
    --green-2: #2fd57f; /* esmeralda */
    --teal: #19c2a0;
    --txt: #f2f7f2;
    --mut: #93a39a;
    --dark-on-green: #06130b;

    position: relative;
    background: var(--bg);
    color: var(--txt);
    font-family: 'Lexend', ui-sans-serif, system-ui, sans-serif;
    font-weight: 300;
    min-height: 100vh;
    overflow-x: hidden;
    isolation: isolate;
}
.lp-wrap {
    width: min(1120px, 100% - 2.5rem);
    margin-inline: auto;
}

/* — Auroras — */
.lp-aurora {
    position: fixed;
    width: 70vw;
    height: 70vw;
    max-width: 900px;
    max-height: 900px;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.5;
    z-index: -1;
    pointer-events: none;
}
.lp-aurora-a {
    top: -28vw;
    right: -18vw;
    background: radial-gradient(circle, rgba(47, 213, 127, 0.28), transparent 65%);
    animation: lp-drift-a 18s ease-in-out infinite alternate;
}
.lp-aurora-b {
    bottom: -32vw;
    left: -20vw;
    background: radial-gradient(circle, rgba(207, 235, 43, 0.14), transparent 65%);
    animation: lp-drift-b 22s ease-in-out infinite alternate;
}
@keyframes lp-drift-a {
    to { transform: translate(-8vw, 10vh) scale(1.15); }
}
@keyframes lp-drift-b {
    to { transform: translate(10vw, -8vh) scale(1.1); }
}

/* — Tipografia — */
.lp-h1 {
    font-size: clamp(2.2rem, 5.6vw, 4rem);
    font-weight: 600;
    line-height: 1.08;
    letter-spacing: -0.02em;
    margin: 1.1rem 0 1.2rem;
}
.lp-h2 {
    font-size: clamp(1.6rem, 3.6vw, 2.6rem);
    font-weight: 600;
    line-height: 1.14;
    letter-spacing: -0.015em;
    margin: 0 0 1rem;
}
.lp-h3 {
    font-size: 1.18rem;
    font-weight: 500;
    margin: 0 0 0.55rem;
}
.lp-grad {
    background: linear-gradient(95deg, var(--green-1), var(--green-2) 60%, var(--teal));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.lp-eyebrow {
    font-size: 0.74rem;
    font-weight: 500;
    letter-spacing: 0.22em;
    color: var(--green-2);
    margin: 0 0 0.9rem;
}
.lp-sub {
    font-size: 1.1rem;
    line-height: 1.7;
    color: var(--mut);
    max-width: 38rem;
    margin: 0 auto 2rem;
}
.lp-sub-sm {
    font-size: 1rem;
    line-height: 1.65;
    color: var(--mut);
    max-width: 30rem;
    margin: 0 0 1.4rem;
}
.lp-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    padding: 0.4rem 0.95rem;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: var(--panel);
    font-size: 0.82rem;
    color: var(--txt);
}

/* — Botões — */
.lp-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
    border-radius: 999px;
    padding: 0.65rem 1.4rem;
    font-size: 0.93rem;
    font-weight: 500;
    text-decoration: none;
    border: 1px solid transparent;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background-color 0.18s ease;
}
.lp-btn-xl {
    padding: 0.9rem 1.9rem;
    font-size: 1.02rem;
}
.lp-btn-green {
    background: linear-gradient(95deg, var(--green-1), var(--green-2) 70%, var(--teal));
    color: var(--dark-on-green);
    box-shadow: 0 10px 34px -10px rgba(47, 213, 127, 0.55);
}
.lp-btn-green:hover {
    transform: translateY(-1px);
    box-shadow: 0 14px 40px -10px rgba(47, 213, 127, 0.75);
}
.lp-btn-ghost {
    color: var(--txt);
    border-color: var(--line);
    background: rgba(242, 247, 242, 0.04);
    backdrop-filter: blur(8px);
}
.lp-btn-ghost:hover {
    border-color: rgba(47, 213, 127, 0.5);
}
.lp-btn:focus-visible {
    outline: 2px solid var(--green-1);
    outline-offset: 3px;
}

/* — Nav — */
.lp-nav {
    position: sticky;
    top: 0;
    z-index: 50;
    background: rgba(7, 12, 9, 0.72);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--line);
}
.lp-nav-in {
    display: flex;
    align-items: center;
    gap: 2rem;
    height: 68px;
}
.lp-logo img {
    height: 30px;
    width: auto;
    display: block;
}
.lp-nav-links {
    display: flex;
    gap: 1.6rem;
    margin-left: auto;
}
.lp-nav-links a {
    color: var(--mut);
    font-size: 0.9rem;
    text-decoration: none;
    transition: color 0.15s ease;
}
.lp-nav-links a:hover {
    color: var(--txt);
}
.lp-nav-cta {
    display: flex;
    gap: 0.6rem;
}
@media (max-width: 760px) {
    .lp-nav-links { display: none; }
    .lp-nav-cta { margin-left: auto; }
}

/* — Hero — */
.lp-hero {
    padding: clamp(3.5rem, 8vh, 6rem) 0 0;
    text-align: center;
}
.lp-hero-center {
    max-width: 50rem;
    margin-inline: auto;
}
.lp-hero-ctas {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.8rem;
}

/* Palco flutuante */
.lp-stage {
    position: relative;
    height: clamp(300px, 42vw, 420px);
    margin-top: clamp(2rem, 5vh, 3.5rem);
}
.lp-stage-glow {
    position: absolute;
    inset: 12% 18%;
    background: radial-gradient(50% 55% at 50% 50%, rgba(47, 213, 127, 0.2), transparent 70%);
    filter: blur(28px);
}
.lp-float {
    position: absolute;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 18px;
    backdrop-filter: blur(14px);
    box-shadow: 0 30px 60px -30px rgba(0, 0, 0, 0.8);
    animation: lp-bob 7s ease-in-out infinite;
    transition: transform 0.25s ease-out;
}
@keyframes lp-bob {
    50% { margin-top: -10px; }
}
.lp-float-sale {
    left: 6%;
    top: 8%;
    padding: 1rem 1.2rem;
    animation-delay: -1s;
}
.lp-sale-badge {
    display: inline-block;
    font-size: 0.66rem;
    font-weight: 600;
    letter-spacing: 0.14em;
    color: var(--dark-on-green);
    background: linear-gradient(95deg, var(--green-1), var(--green-2));
    border-radius: 6px;
    padding: 0.22rem 0.5rem;
    margin-bottom: 0.7rem;
}
.lp-sale-row {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    text-align: left;
}
.lp-sale-check {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: rgba(47, 213, 127, 0.16);
    color: var(--green-2);
    font-weight: 600;
}
.lp-sale-amount {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.lp-sale-sub {
    margin: 0;
    font-size: 0.78rem;
    color: var(--mut);
}
.lp-float-pix {
    right: 8%;
    top: 0;
    padding: 0.9rem 1rem 1rem;
    animation-delay: -3s;
}
.lp-pix-head {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.78rem;
    color: var(--mut);
    margin-bottom: 0.6rem;
}
.lp-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--green-2);
}
.lp-dot-live {
    animation: lp-blink 1.1s ease-in-out infinite;
}
@keyframes lp-blink {
    50% { opacity: 0.25; }
}
.lp-pix-qr {
    position: relative;
    width: 108px;
    height: 108px;
    background: #fff;
    border-radius: 10px;
    padding: 7px;
    color: #0b140e;
    transition: opacity 0.3s ease;
}
.lp-pix-qr svg {
    width: 100%;
    height: 100%;
}
.lp-pix-qr-paid svg {
    opacity: 0.14;
}
.lp-pix-paid {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    font-weight: 600;
    color: var(--green-2) !important;
    font-size: 0.98rem;
}
.lp-float-push {
    left: 16%;
    bottom: 6%;
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.8rem 1.1rem;
    animation-delay: -5s;
    text-align: left;
}
.lp-push-icon { font-size: 1.3rem; }
.lp-push-title {
    margin: 0;
    font-size: 0.72rem;
    color: var(--mut);
}
.lp-push-text {
    margin: 0;
    font-size: 0.88rem;
    font-weight: 400;
}
.lp-float-balance {
    right: 14%;
    bottom: 0;
    padding: 1rem 1.2rem;
    text-align: left;
    animation-delay: -2s;
}
.lp-balance-label {
    margin: 0 0 0.2rem;
    font-size: 0.72rem;
    color: var(--mut);
}
.lp-balance-value {
    margin: 0 0 0.6rem;
    font-size: 1.3rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.lp-balance-btn {
    font-size: 0.78rem;
    font-weight: 500;
    text-align: center;
    border-radius: 8px;
    padding: 0.4rem 0.7rem;
    color: var(--dark-on-green);
    background: linear-gradient(95deg, var(--green-1), var(--green-2));
}
@media (max-width: 760px) {
    .lp-stage { height: 460px; }
    .lp-float-sale { left: 2%; top: 2%; }
    .lp-float-pix { right: 2%; top: 16%; }
    .lp-float-push { left: 4%; bottom: 24%; }
    .lp-float-balance { right: 4%; bottom: 2%; }
}

/* — Stats — */
.lp-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin: clamp(2rem, 5vh, 3rem) 0 0;
    padding: 1.4rem 0;
    border-block: 1px solid var(--line);
}
@media (max-width: 760px) {
    .lp-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.lp-stat { text-align: center; }
.lp-stat-value {
    font-size: clamp(1.5rem, 3vw, 2.1rem);
    font-weight: 600;
    background: linear-gradient(95deg, var(--green-1), var(--green-2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.lp-stat-label {
    margin: 0.25rem 0 0;
    font-size: 0.84rem;
    color: var(--mut);
}

/* — Adquirentes — */
.lp-acq {
    padding: 2.6rem 0 1.6rem;
}
.lp-acq-label {
    text-align: center;
    font-size: 0.72rem;
    letter-spacing: 0.2em;
    color: var(--mut);
    margin: 0 0 1.2rem;
}
.lp-marquee {
    overflow: hidden;
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 10%, #000 90%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 10%, #000 90%, transparent);
}
.lp-marquee-track {
    display: flex;
    width: max-content;
    animation: lp-marquee 38s linear infinite;
}
@keyframes lp-marquee {
    to { transform: translateX(-50%); }
}
.lp-marquee-off .lp-marquee-track {
    animation: none;
    width: 100%;
    flex-wrap: wrap;
    justify-content: center;
}
.lp-marquee-off .lp-marquee-dup {
    display: none;
}
.lp-marquee-group {
    display: flex;
    align-items: center;
}
.lp-chip {
    display: grid;
    place-items: center;
    background: rgba(244, 248, 244, 0.93);
    border-radius: 12px;
    padding: 0.55rem 1.1rem;
    margin: 0 0.45rem 0.9rem;
    min-width: 108px;
    height: 44px;
}
.lp-chip img {
    max-height: 26px;
    max-width: 110px;
    object-fit: contain;
}

/* — Seções / bento — */
.lp-sec {
    padding: clamp(3.5rem, 9vh, 6.5rem) 0;
}
.lp-sec-glass {
    background: linear-gradient(180deg, rgba(16, 24, 19, 0.55), transparent);
    border-block: 1px solid var(--line);
}
.lp-bento {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 2.2rem;
}
@media (max-width: 900px) {
    .lp-bento { grid-template-columns: 1fr; }
    .lp-cell-wide { grid-column: auto !important; }
}
.lp-cell {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 1.6rem 1.5rem;
    backdrop-filter: blur(10px);
    transition: border-color 0.25s ease, transform 0.25s ease;
}
.lp-cell:hover {
    border-color: rgba(47, 213, 127, 0.45);
    transform: translateY(-3px);
}
.lp-cell-wide { grid-column: span 2; }
.lp-cell-tag {
    font-size: 0.68rem;
    font-weight: 500;
    letter-spacing: 0.18em;
    color: var(--green-2);
    margin: 0 0 0.75rem;
}
.lp-cell-text {
    margin: 0;
    font-size: 0.93rem;
    line-height: 1.65;
    color: var(--mut);
}
.lp-mini-timeline {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1.2rem;
}
.lp-step {
    font-size: 0.76rem;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    border: 1px solid var(--line);
    color: var(--mut);
}
.lp-step-done {
    color: var(--txt);
}
.lp-step-glow {
    color: var(--dark-on-green);
    background: linear-gradient(95deg, var(--green-1), var(--green-2));
    border-color: transparent;
    font-weight: 500;
}
.lp-step-line {
    flex: none;
    width: 18px;
    height: 1px;
    background: var(--line);
}
.lp-mini-med {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1.1rem;
}
.lp-med-badge {
    font-size: 0.74rem;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 107, 107, 0.14);
    color: #ff8f8f;
}
.lp-med-amount {
    font-size: 0.74rem;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    border: 1px solid var(--line);
    color: var(--mut);
}
.lp-mini-push {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1.1rem;
    font-size: 0.86rem;
    padding: 0.45rem 0.85rem;
    border-radius: 999px;
    border: 1px solid rgba(47, 213, 127, 0.4);
    color: var(--green-2);
}

/* — Split / mock — */
.lp-split {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: clamp(2rem, 5vw, 4rem);
    align-items: center;
}
@media (max-width: 900px) {
    .lp-split { grid-template-columns: 1fr; }
}
.lp-list {
    list-style: none;
    padding: 0;
    margin: 0 0 1.7rem;
    display: grid;
    gap: 0.6rem;
}
.lp-list li {
    display: flex;
    gap: 0.6rem;
    font-size: 0.93rem;
    line-height: 1.55;
}
.lp-list li::before {
    content: '✓';
    color: var(--green-2);
    font-weight: 600;
}
.lp-mock {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 0.9rem;
    backdrop-filter: blur(10px);
    box-shadow: 0 34px 80px -40px rgba(0, 0, 0, 0.9);
}
.lp-mock-url {
    font-size: 0.72rem;
    color: var(--mut);
    border: 1px solid var(--line);
    border-radius: 999px;
    padding: 0.3rem 0.8rem;
    width: max-content;
    margin: 0 auto 0.8rem;
}
.lp-mock-hero {
    height: 58px;
    border-radius: 12px;
    background: linear-gradient(110deg, rgba(207, 235, 43, 0.3), rgba(47, 213, 127, 0.28));
    margin-bottom: 0.8rem;
}
.lp-mock-grid {
    display: grid;
    grid-template-columns: 1.25fr 1fr;
    gap: 0.8rem;
}
.lp-mock-left,
.lp-mock-pay {
    display: grid;
    gap: 0.55rem;
    align-content: start;
}
.lp-mock-pay {
    background: rgba(7, 12, 9, 0.65);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 0.8rem;
}
.lp-skel {
    height: 9px;
    border-radius: 5px;
    background: rgba(147, 163, 154, 0.22);
}
.lp-skel.w-55 { width: 55%; }
.lp-skel.w-60 { width: 60%; }
.lp-skel.w-80 { width: 80%; }
.lp-skel.w-90 { width: 90%; }
.lp-mock-video {
    height: 84px;
    border-radius: 10px;
    background: rgba(147, 163, 154, 0.1);
    display: grid;
    place-items: center;
    color: rgba(242, 247, 242, 0.6);
}
.lp-mock-input {
    height: 26px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: rgba(147, 163, 154, 0.06);
}
.lp-mock-buy {
    height: 34px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--dark-on-green);
    background: linear-gradient(95deg, var(--green-1), var(--green-2));
}
.lp-mock-safe {
    font-size: 0.68rem;
    color: var(--mut);
    text-align: center;
}

/* — CTA final — */
.lp-final {
    position: relative;
    padding: clamp(4rem, 10vh, 7rem) 0;
    text-align: center;
    overflow: hidden;
}
.lp-final-glow {
    position: absolute;
    inset: auto -20% -70%;
    height: 130%;
    background: radial-gradient(50% 55% at 50% 100%, rgba(47, 213, 127, 0.28), transparent 70%);
    z-index: -1;
}
.lp-final-logo {
    height: 34px;
    width: auto;
    margin: 0 auto 1.4rem;
    display: block;
}
.lp-final-ctas {
    margin-top: 1.6rem;
}

/* — Footer — */
.lp-footer {
    border-top: 1px solid var(--line);
    padding: 1.6rem 0;
}
.lp-footer-in {
    display: flex;
    align-items: center;
    gap: 1.4rem;
    flex-wrap: wrap;
}
.lp-footer-logo {
    height: 24px;
    width: auto;
}
.lp-footer-links {
    display: flex;
    gap: 1.2rem;
    margin-left: auto;
}
.lp-footer-links a {
    color: var(--mut);
    font-size: 0.84rem;
    text-decoration: none;
}
.lp-footer-links a:hover { color: var(--txt); }
.lp-footer-copy {
    color: var(--mut);
    font-size: 0.84rem;
}
@media (max-width: 640px) {
    .lp-footer-in { flex-direction: column; gap: 0.8rem; }
    .lp-footer-links { margin-left: 0; }
}

/* — Reveal — */
.lp-reveal {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.65s ease, transform 0.65s ease;
}
.lp-in {
    opacity: 1;
    transform: none;
}

@media (prefers-reduced-motion: reduce) {
    .lp-reveal { opacity: 1; transform: none; transition: none; }
    .lp-aurora,
    .lp-float,
    .lp-dot-live,
    .lp-marquee-track {
        animation: none !important;
    }
    .lp-btn, .lp-cell { transition: none; }
}
</style>
