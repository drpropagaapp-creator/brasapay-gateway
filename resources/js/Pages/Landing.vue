<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import BrasaLogo from '@/components/landing/BrasaLogo.vue';
import CheckoutScene from '@/components/landing/CheckoutScene.vue';
import EmberCanvas from '@/components/landing/EmberCanvas.vue';
import PhoneScene from '@/components/landing/PhoneScene.vue';

gsap.registerPlugin(ScrollTrigger);

/**
 * Landing pública do BrasaPay — experiência cinematográfica:
 * abertura com a marca se desenhando, rolagem suave (Lenis), cenas pinadas
 * (celular com notificação, checkout com produto fictício), brasas em canvas,
 * texto que acende com o scroll e microinterações magnéticas.
 */

// ——— Abertura (uma vez por sessão) ———
const introState = ref('idle'); // play | calm | skip
const root = ref(null);
let lenis = null;
let mm = null;
let navTrigger = null;
let heroPlayed = false;

const WORD = 'brasapay';

function heroEntrance(instant) {
    if (heroPlayed) return;
    heroPlayed = true;
    const q = gsap.utils.selector(root.value);
    if (instant) {
        gsap.set(q('.hero-word i, .hero-sub, .hero-ctas, .hero-cue, .lp-nav'), { clearProps: 'all', opacity: 1 });
        return;
    }
    gsap.timeline({ defaults: { ease: 'power4.out' } })
        .to(q('.lp-nav'), { yPercent: 0, opacity: 1, duration: 0.7 }, 0)
        .to(q('.hero-word i'), { yPercent: 0, duration: 1.0, stagger: 0.09 }, 0.05)
        .to(q('.hero-sub'), { y: 0, opacity: 1, duration: 0.8 }, 0.55)
        .to(q('.hero-ctas'), { y: 0, opacity: 1, duration: 0.8 }, 0.7)
        .to(q('.hero-cue'), { opacity: 1, duration: 0.6 }, 1.1);
}

function runIntro() {
    // A abertura toca em todo carregamento (pedido do dono da página).
    introState.value = 'play';
    setTimeout(() => heroEntrance(false), 2050);
    setTimeout(() => (introState.value = 'skip'), 2900);
}

// ——— Botão magnético ———
function magnetic(e) {
    if (e.pointerType !== 'mouse') return;
    const el = e.currentTarget;
    const r = el.getBoundingClientRect();
    el.style.setProperty('--tx', `${((e.clientX - r.left - r.width / 2) * 0.3).toFixed(1)}px`);
    el.style.setProperty('--ty', `${((e.clientY - r.top - r.height / 2) * 0.3).toFixed(1)}px`);
}
function magneticOut(e) {
    e.currentTarget.style.setProperty('--tx', '0px');
    e.currentTarget.style.setProperty('--ty', '0px');
}

// ——— Cartão spotlight (luz segue o cursor + leve 3D) ———
function spot(e) {
    if (e.pointerType !== 'mouse') return;
    const el = e.currentTarget;
    const r = el.getBoundingClientRect();
    const px = (e.clientX - r.left) / r.width;
    const py = (e.clientY - r.top) / r.height;
    el.style.setProperty('--mx', `${(px * 100).toFixed(1)}%`);
    el.style.setProperty('--my', `${(py * 100).toFixed(1)}%`);
    el.style.setProperty('--rx', `${((0.5 - py) * 5).toFixed(2)}deg`);
    el.style.setProperty('--ry', `${((px - 0.5) * 6).toFixed(2)}deg`);
}
function spotOut(e) {
    e.currentTarget.style.setProperty('--rx', '0deg');
    e.currentTarget.style.setProperty('--ry', '0deg');
}

onMounted(() => {
    runIntro();

    mm = gsap.matchMedia();
    // Motion sempre ligado (decisão do dono da página): sem trava de
    // prefers-reduced-motion — o matchMedia fica só pelo ciclo de vida.
    mm.add('all', () => {
        const q = gsap.utils.selector(root.value);

        // Rolagem suave.
        lenis = new Lenis({ duration: 1.15, smoothWheel: true });
        lenis.on('scroll', ScrollTrigger.update);
        const raf = (time) => lenis.raf(time * 1000);
        gsap.ticker.add(raf);
        gsap.ticker.lagSmoothing(0);

        // Estados iniciais da entrada — só quando a abertura ainda vai tocar
        // (visitante recorrente já tem tudo visível; sem JS idem).
        if (!heroPlayed) {
            gsap.set(q('.hero-word i'), { yPercent: 112 });
            gsap.set(q('.hero-sub, .hero-ctas'), { y: 26, opacity: 0 });
            gsap.set(q('.hero-cue'), { opacity: 0 });
            gsap.set(q('.lp-nav'), { yPercent: -110, opacity: 0 });
        }

        // Garante ordem de refresh por posição no documento (as cenas pinadas
        // dos filhos montam antes dos triggers do pai).
        requestAnimationFrame(() => {
            ScrollTrigger.sort();
            ScrollTrigger.refresh();
        });

        // Barra de progresso da leitura.
        gsap.to(q('.lp-progress'), {
            scaleX: 1,
            ease: 'none',
            scrollTrigger: { start: 0, end: 'max', scrub: 0.4 },
        });

        // O hero recua enquanto a página rola por cima.
        gsap.to(q('.hero-inner'), {
            scale: 0.93,
            yPercent: 8,
            opacity: 0.25,
            ease: 'none',
            scrollTrigger: { trigger: q('.hero')[0], start: 'top top', end: 'bottom top', scrub: true },
        });

        // Nav esconde descendo, volta subindo.
        let lastY = 0;
        navTrigger = ScrollTrigger.create({
            start: 120,
            end: 'max',
            onUpdate(self) {
                const y = self.scroll();
                if (!heroPlayed) return;
                gsap.to(q('.lp-nav'), {
                    yPercent: y > lastY + 4 ? -110 : 0,
                    duration: 0.35,
                    ease: 'power2.out',
                    overwrite: 'auto',
                });
                lastY = y;
            },
        });

        // Banda de fatos: sobe em cascata + contador.
        gsap.set(q('.lp-stat'), { y: 30, opacity: 0 });
        ScrollTrigger.create({
            trigger: q('.lp-stats')[0],
            start: 'top 82%',
            once: true,
            onEnter: () => {
                gsap.to(q('.lp-stat'), { y: 0, opacity: 1, duration: 0.8, stagger: 0.1, ease: 'power3.out' });
                const el = q('.lp-count')[0];
                if (el) {
                    const v = { n: 0 };
                    gsap.to(v, {
                        n: 10,
                        duration: 1.4,
                        ease: 'power2.out',
                        onUpdate: () => (el.textContent = `${Math.round(v.n)}+`),
                    });
                }
            },
        });

        // Revelações genéricas.
        ScrollTrigger.batch(q('.lp-reveal'), {
            start: 'top 86%',
            once: true,
            onEnter: (els) =>
                gsap.fromTo(
                    els,
                    { y: 34, opacity: 0, filter: 'blur(8px)' },
                    { y: 0, opacity: 1, filter: 'blur(0px)', duration: 0.9, stagger: 0.09, ease: 'power3.out' }
                ),
        });

        // Manifesto: as palavras acendem conforme a rolagem passa.
        const words = q('.mani-w');
        gsap.set(words, { opacity: 0.14 });
        gsap.to(words, {
            opacity: 1,
            stagger: 0.06,
            ease: 'none',
            scrollTrigger: { trigger: q('.mani')[0], start: 'top 78%', end: 'top 26%', scrub: true },
        });

        // Assinatura gigante desliza no rodapé.
        gsap.fromTo(
            q('.lp-drift span'),
            { xPercent: 4 },
            {
                xPercent: -4,
                ease: 'none',
                scrollTrigger: { trigger: q('.lp-drift')[0], start: 'top bottom', end: 'bottom top', scrub: true },
            }
        );

        // Glow final respira.
        gsap.to(q('.final-glow'), { scale: 1.18, opacity: 0.9, duration: 2.6, yoyo: true, repeat: -1, ease: 'sine.inOut' });

        return () => {
            gsap.ticker.remove(raf);
            lenis?.destroy();
            lenis = null;
            navTrigger?.kill();
        };
    });
});

onBeforeUnmount(() => {
    mm?.revert();
    ScrollTrigger.getAll().forEach((t) => t.kill());
    lenis?.destroy();
});

// ——— Conteúdo ———
const stats = [
    { value: 'D+0', label: 'PIX na conta na hora' },
    { value: '10+', label: 'adquirentes com redundância', count: true },
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

const bento = [
    {
        tag: 'APROVAÇÃO INSTANTÂNEA',
        title: 'Pagou, caiu, liberou',
        text: 'Webhook recebido, status reconferido direto na adquirente e pedido confirmado em segundos — acesso e e-mail disparados sem você tocar em nada.',
        wide: true,
    },
    {
        tag: 'BLINDAGEM MED',
        title: 'Disputa sob controle',
        text: 'MED aberto? O sistema acha a venda, trava o valor, avisa o vendedor responsável e mostra tudo pro admin — com prazo para responder.',
    },
    {
        tag: 'REDUNDÂNCIA AUTOMÁTICA',
        title: 'Adquirente caiu? Nem percebe',
        text: 'Se uma adquirente recusa ou sai do ar, a cobrança nasce na próxima da fila. Seu checkout nunca fica fora do ar.',
    },
    {
        tag: 'FINANCEIRO',
        title: 'Saque automático com comprovante',
        text: 'Saques por PIX direto da adquirente, reconciliação automática e trilha de auditoria completa.',
    },
    {
        tag: 'PAINEL',
        title: '5 temas, do seu jeito',
        text: 'Default, Aurora, Kawaii, Prime e Studio — o visual que combina com a sua operação, para vendedor e admin.',
        wide: true,
    },
];

const manifesto = 'O dinheiro do seu produto não pode esperar compensação, planilha nem suporte. Aqui, venda aprovada é dinheiro na mão — e você ouve cada uma acontecer.';

const year = new Date().getFullYear();
</script>

<template>
    <Head title="BrasaPay — venda no digital, receba na hora">
        <meta
            name="description"
            content="O gateway de pagamentos para quem vende no digital: PIX aprovado em segundos, redundância de adquirentes, blindagem contra MED e um checkout que é a sua página de vendas."
        />
        <meta property="og:title" content="BrasaPay — venda no digital, receba na hora" />
        <meta property="og:description" content="PIX aprovado em segundos, redundância de adquirentes e checkout que converte." />
    </Head>

    <div ref="root" class="lp" :data-intro="introState">
        <!-- Abertura: a logo oficial revelada por cortina de luz -->
        <div v-if="introState === 'play' || introState === 'calm'" class="intro" aria-hidden="true">
            <span class="intro-glow"></span>
            <div class="intro-lockup">
                <img src="/images/brasapay/logo-branca.png" alt="" class="intro-logo" />
                <span class="intro-shine"></span>
            </div>
            <span class="intro-tag">pagamentos que caem na hora</span>
        </div>

        <span class="lp-progress" aria-hidden="true"></span>

        <!-- Navegação -->
        <header class="lp-nav">
            <div class="lp-wrap lp-nav-in">
                <a href="#" aria-label="BrasaPay — início"><BrasaLogo :size="30" /></a>
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
            <section class="hero">
                <EmberCanvas :density="80" />
                <div class="hero-vignette" aria-hidden="true"></div>
                <div class="lp-wrap hero-inner">
                    <p class="lp-pill hero-sub"><img src="/images/brasapay/simbolo.png" alt="" class="lp-pill-mark" /> Você só paga quando vende</p>
                    <h1 class="hero-h1" aria-label="Vendeu. Caiu. Na hora.">
                        <span class="hero-word"><i>Vendeu.</i></span>
                        <span class="hero-word"><i>Caiu.</i></span>
                        <span class="hero-word"><i class="lp-grad">Na&nbsp;hora.</i></span>
                    </h1>
                    <p class="hero-sub lp-sub">
                        O gateway de quem vive de vender no digital: PIX aprovado em segundos,
                        redundância real de adquirentes e um checkout que é a sua página de vendas.
                    </p>
                    <div class="hero-ctas">
                        <Link href="/cadastro" class="lp-btn lp-btn-green lp-btn-xl lp-magnet" @pointermove="magnetic" @pointerleave="magneticOut">
                            Começar a vender <span aria-hidden="true">→</span>
                        </Link>
                        <a href="#recursos" class="lp-btn lp-btn-ghost lp-btn-xl">Ver a tecnologia</a>
                    </div>
                </div>
                <div class="hero-cue" aria-hidden="true"><span></span></div>
            </section>

            <!-- Fatos -->
            <section class="lp-stats-wrap">
                <dl class="lp-wrap lp-stats">
                    <div v-for="s in stats" :key="s.label" class="lp-stat">
                        <dt class="lp-stat-value" :class="{ 'lp-count': s.count }">{{ s.value }}</dt>
                        <dd class="lp-stat-label">{{ s.label }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Celular -->
            <PhoneScene />

            <!-- Adquirentes -->
            <section id="adquirentes" class="lp-acq" aria-label="Adquirentes integradas">
                <p class="lp-acq-label lp-reveal">REDUNDÂNCIA REAL, NAS ADQUIRENTES QUE O BRASIL USA</p>
                <div class="lp-marquee">
                    <div class="lp-marquee-track">
                        <div v-for="dup in 2" :key="dup" class="lp-marquee-group" :aria-hidden="dup === 2" :class="{ 'lp-marquee-dup': dup === 2 }">
                            <span v-for="a in acquirers" :key="dup + a.name" class="lp-chip">
                                <img :src="a.src" :alt="dup === 1 ? a.name : ''" loading="lazy" />
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Checkout com produto fictício -->
            <div id="checkout"><CheckoutScene /></div>

            <!-- Bento de recursos -->
            <section id="recursos" class="lp-sec">
                <div class="lp-wrap">
                    <p class="lp-eyebrow lp-reveal">TECNOLOGIA PRÓPRIA</p>
                    <h2 class="lp-h2 lp-reveal">Cada detalhe existe pra uma coisa:<br /><span class="lp-grad">te fazer vender mais</span></h2>
                    <div class="lp-bento">
                        <article
                            v-for="c in bento"
                            :key="c.title"
                            class="lp-cell lp-reveal"
                            :class="{ 'lp-cell-wide': c.wide }"
                            @pointermove="spot"
                            @pointerleave="spotOut"
                        >
                            <p class="lp-cell-tag">{{ c.tag }}</p>
                            <h3 class="lp-h3">{{ c.title }}</h3>
                            <p class="lp-cell-text">{{ c.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Manifesto -->
            <section class="mani">
                <p class="lp-wrap mani-p" :aria-label="manifesto">
                    <span v-for="(w, i) in manifesto.split(' ')" :key="i" class="mani-w" aria-hidden="true">{{ w }}&nbsp;</span>
                </p>
            </section>

            <!-- CTA final -->
            <section class="final">
                <div class="final-glow" aria-hidden="true"></div>
                <div class="lp-wrap final-in">
                    <h2 class="lp-h2 lp-reveal">Acenda a sua operação<br /><span class="lp-grad">hoje.</span></h2>
                    <p class="lp-sub-sm lp-reveal">Crie a conta, conecte uma adquirente e gere a primeira cobrança em minutos.</p>
                    <div class="hero-ctas lp-reveal">
                        <Link href="/cadastro" class="lp-btn lp-btn-green lp-btn-xl lp-magnet" @pointermove="magnetic" @pointerleave="magneticOut">
                            Criar conta grátis
                        </Link>
                        <Link href="/login" class="lp-btn lp-btn-ghost lp-btn-xl">Já tenho conta</Link>
                    </div>
                </div>
                <div class="lp-drift" aria-hidden="true"><span>brasapay</span></div>
            </section>
        </main>

        <footer class="lp-footer">
            <div class="lp-wrap lp-footer-in">
                <BrasaLogo :size="24" />
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
/* ——— Tokens ——— */
.lp {
    --bg: #051013;
    --panel: rgba(12, 23, 24, 0.72);
    --line: rgba(100, 175, 180, 0.15);
    --g1: #5cb536;
    --g2: #0a986e;
    --g3: #008894;
    --txt: #f2f7f2;
    --mut: #93a39a;
    --dark: #f4fcfa;
    --ease-cinema: cubic-bezier(0.16, 1, 0.3, 1);

    background: var(--bg);
    color: var(--txt);
    font-family: 'Lexend', ui-sans-serif, system-ui, sans-serif;
    font-weight: 300;
    min-height: 100vh;
    overflow-x: clip;
}
:deep(.lp-wrap) {
    width: min(1140px, 100% - 2.5rem);
    margin-inline: auto;
}
:deep(.lp-grad),
.lp-grad {
    background: linear-gradient(95deg, var(--g1), var(--g2) 60%, var(--g3));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
:deep(.lp-h2) {
    font-size: clamp(1.7rem, 3.8vw, 2.7rem);
    font-weight: 600;
    line-height: 1.12;
    letter-spacing: -0.02em;
    margin: 0 0 1rem;
    color: var(--txt);
}
:deep(.lp-h3) {
    font-size: 1.16rem;
    font-weight: 500;
    margin: 0 0 0.55rem;
}
:deep(.lp-eyebrow) {
    font-size: 0.72rem;
    font-weight: 500;
    letter-spacing: 0.24em;
    color: var(--g2);
    margin: 0 0 0.9rem;
}
:deep(.lp-sub-sm) {
    font-size: 1rem;
    line-height: 1.7;
    color: var(--mut);
    max-width: 30rem;
    margin: 0 0 1.4rem;
}
.lp-sub {
    font-size: 1.1rem;
    line-height: 1.7;
    color: var(--mut);
    max-width: 37rem;
    margin: 0 auto 2.1rem;
}
.lp-pill-mark {
    width: 16px;
    height: 16px;
    object-fit: contain;
}
.lp-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0 0 1.3rem;
    padding: 0.42rem 1rem;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: var(--panel);
    backdrop-filter: blur(10px);
    font-size: 0.82rem;
}

/* ——— Botões ——— */
.lp-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
    border-radius: 999px;
    padding: 0.66rem 1.4rem;
    font-size: 0.93rem;
    font-weight: 500;
    text-decoration: none;
    border: 1px solid transparent;
    transition: box-shadow 0.25s var(--ease-cinema), border-color 0.2s ease, transform 0.35s var(--ease-cinema);
    transform: translate(var(--tx, 0), var(--ty, 0));
    will-change: transform;
}
.lp-btn-xl { padding: 0.95rem 2rem; font-size: 1.04rem; }
.lp-btn-green {
    background: linear-gradient(95deg, var(--g1), var(--g2) 70%, var(--g3));
    color: var(--dark);
    font-weight: 600;
    box-shadow: 0 10px 36px -10px rgba(10, 152, 110, 0.6);
}
.lp-btn-green:hover { box-shadow: 0 16px 48px -10px rgba(10, 152, 110, 0.85); }
.lp-btn-ghost {
    color: var(--txt);
    border-color: var(--line);
    background: rgba(242, 247, 242, 0.04);
    backdrop-filter: blur(8px);
}
.lp-btn-ghost:hover { border-color: rgba(10, 152, 110, 0.5); }
.lp-btn:focus-visible { outline: 2px solid var(--g1); outline-offset: 3px; }

/* ——— Abertura ——— */
.intro {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    background: #040a0c;
    animation: intro-curtain 0.9s var(--ease-cinema) 2s both;
}
[data-intro='calm'] .intro { animation: intro-fade 0.9s ease 0.5s both; }
@keyframes intro-curtain {
    to { clip-path: inset(0 0 100% 0); visibility: hidden; }
}
@keyframes intro-fade {
    to { opacity: 0; visibility: hidden; }
}
.intro-glow {
    position: absolute;
    width: 46vmin;
    height: 46vmin;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(10, 152, 110, 0.3), transparent 70%);
    filter: blur(30px);
    animation: intro-bloom 2s var(--ease-cinema) both;
}
@keyframes intro-bloom {
    from { transform: scale(0.3); opacity: 0; }
    60% { opacity: 1; }
}
.intro-lockup {
    position: relative;
    overflow: hidden;
    padding: 0.6rem 1rem;
}
.intro-logo {
    width: clamp(240px, 38vmin, 380px);
    height: auto;
    display: block;
    /* A logo é revelada por uma cortina da esquerda pra direita, saindo do desfoque. */
    clip-path: inset(0 100% 0 0);
    filter: blur(10px) brightness(1.6);
    animation:
        intro-reveal 1.1s var(--ease-cinema) 0.25s forwards,
        intro-focus 0.9s ease 0.8s forwards;
}
@keyframes intro-reveal {
    to { clip-path: inset(0 0 0 0); }
}
@keyframes intro-focus {
    to { filter: blur(0) brightness(1); }
}
.intro-shine {
    position: absolute;
    inset: 0;
    background: linear-gradient(100deg, transparent 20%, rgba(255, 255, 255, 0.35) 50%, transparent 80%);
    transform: translateX(-120%);
    animation: intro-shine 0.9s var(--ease-cinema) 1.25s forwards;
    pointer-events: none;
}
@keyframes intro-shine {
    to { transform: translateX(120%); }
}
.intro-tag {
    position: absolute;
    bottom: 14vh;
    font-size: 0.82rem;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: rgba(242, 247, 242, 0.5);
    opacity: 0;
    animation: intro-tag 0.8s var(--ease-cinema) 1.35s forwards;
}
@keyframes intro-tag {
    from { opacity: 0; transform: translateY(14px); }
    to { opacity: 1; transform: none; }
}

/* ——— Progresso ——— */
.lp-progress {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    z-index: 60;
    background: linear-gradient(95deg, var(--g1), var(--g2), var(--g3));
    transform-origin: 0 50%;
    transform: scaleX(0);
    pointer-events: none;
}

/* ——— Nav ——— */
.lp-nav {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 50;
    background: rgba(5, 16, 19, 0.7);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--line);
    will-change: transform;
}
.lp-nav-in {
    display: flex;
    align-items: center;
    gap: 2rem;
    height: 68px;
}
.lp-nav-links { display: flex; gap: 1.6rem; margin-left: auto; }
.lp-nav-links a {
    color: var(--mut);
    font-size: 0.9rem;
    text-decoration: none;
    transition: color 0.15s ease;
}
.lp-nav-links a:hover { color: var(--txt); }
.lp-nav-cta { display: flex; gap: 0.6rem; }
@media (max-width: 760px) {
    .lp-nav-links { display: none; }
    .lp-nav-cta { margin-left: auto; }
}

/* ——— Hero ——— */
.hero {
    position: relative;
    min-height: 100svh;
    display: grid;
    place-items: center;
    text-align: center;
    overflow: hidden;
    isolation: isolate;
}
.hero-vignette {
    position: absolute;
    inset: 0;
    z-index: -1;
    background:
        radial-gradient(60% 50% at 50% 38%, rgba(10, 152, 110, 0.14), transparent 70%),
        radial-gradient(110% 80% at 50% 110%, rgba(5, 9, 7, 0.9), transparent 55%);
}
.hero-inner { padding: 7rem 0 5rem; will-change: transform; }
.hero-h1 {
    margin: 0 0 1.4rem;
    font-size: clamp(2.8rem, 9vw, 6.4rem);
    font-weight: 600;
    line-height: 1.02;
    letter-spacing: -0.03em;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0 1.6rem;
}
.hero-word {
    display: inline-block;
    overflow: hidden;
    padding-bottom: 0.08em;
}
.hero-word i {
    display: inline-block;
    font-style: normal;
    will-change: transform;
}
.hero-ctas {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.9rem;
}
.hero-cue {
    position: absolute;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%);
    width: 24px;
    height: 38px;
    border: 1.5px solid var(--line);
    border-radius: 14px;
    display: flex;
    justify-content: center;
}
.hero-cue span {
    width: 3px;
    height: 8px;
    margin-top: 7px;
    border-radius: 2px;
    background: var(--g2);
    animation: cue 1.6s ease-in-out infinite;
}
@keyframes cue {
    50% { transform: translateY(10px); opacity: 0.2; }
}

/* ——— Fatos ——— */
.lp-stats-wrap { border-block: 1px solid var(--line); background: rgba(12, 23, 24, 0.35); }
.lp-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin: 0;
    padding: 1.7rem 0;
}
@media (max-width: 760px) {
    .lp-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.lp-stat { text-align: center; }
.lp-stat-value {
    font-size: clamp(1.6rem, 3.2vw, 2.3rem);
    font-weight: 600;
    background: linear-gradient(95deg, var(--g1), var(--g2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    font-variant-numeric: tabular-nums;
}
.lp-stat-label { margin: 0.3rem 0 0; font-size: 0.84rem; color: var(--mut); }

/* ——— Adquirentes ——— */
.lp-acq { padding: 3.2rem 0 2rem; }
.lp-acq-label {
    text-align: center;
    font-size: 0.72rem;
    letter-spacing: 0.2em;
    color: var(--mut);
    margin: 0 0 1.3rem;
}
.lp-marquee {
    overflow: hidden;
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 10%, #000 90%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 10%, #000 90%, transparent);
}
.lp-marquee-track {
    display: flex;
    width: max-content;
    animation: marquee 38s linear infinite;
}
.lp-marquee:hover .lp-marquee-track { animation-play-state: paused; }
@keyframes marquee {
    to { transform: translateX(-50%); }
}
.lp-marquee-group { display: flex; align-items: center; }
.lp-chip {
    display: grid;
    place-items: center;
    background: rgba(243, 248, 248, 0.93);
    border-radius: 12px;
    padding: 0.55rem 1.1rem;
    margin: 0 0.45rem 0.9rem;
    min-width: 110px;
    height: 46px;
    transition: transform 0.25s var(--ease-cinema);
}
.lp-chip:hover { transform: translateY(-3px) scale(1.04); }
.lp-chip img {
    max-height: 26px;
    max-width: 112px;
    object-fit: contain;
}

/* ——— Bento ——— */
.lp-sec { padding: clamp(3.5rem, 9vh, 6.5rem) 0; }
.lp-bento {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 2.2rem;
    perspective: 1400px;
}
@media (max-width: 900px) {
    .lp-bento { grid-template-columns: 1fr; }
    .lp-cell-wide { grid-column: auto !important; }
}
.lp-cell {
    position: relative;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 1.7rem 1.55rem;
    backdrop-filter: blur(10px);
    overflow: hidden;
    transform: rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg));
    transition: transform 0.3s var(--ease-cinema), border-color 0.25s ease;
}
.lp-cell::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(320px circle at var(--mx, 50%) var(--my, 50%), rgba(10, 152, 110, 0.14), transparent 65%);
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}
.lp-cell:hover { border-color: rgba(10, 152, 110, 0.45); }
.lp-cell:hover::before { opacity: 1; }
.lp-cell-wide { grid-column: span 2; }
.lp-cell-tag {
    font-size: 0.66rem;
    font-weight: 500;
    letter-spacing: 0.2em;
    color: var(--g2);
    margin: 0 0 0.75rem;
}
.lp-cell-text {
    margin: 0;
    font-size: 0.93rem;
    line-height: 1.65;
    color: var(--mut);
}

/* ——— Manifesto ——— */
.mani { padding: clamp(3rem, 8vh, 5.5rem) 0; }
.mani-p {
    margin: 0;
    font-size: clamp(1.4rem, 3.4vw, 2.3rem);
    font-weight: 500;
    line-height: 1.4;
    letter-spacing: -0.015em;
    max-width: 56rem;
}
.mani-w { display: inline-block; }

/* ——— Final ——— */
.final {
    position: relative;
    padding: clamp(4rem, 10vh, 7rem) 0 0;
    text-align: center;
    overflow: hidden;
    isolation: isolate;
}
.final-glow {
    position: absolute;
    inset: auto -25% -75%;
    height: 130%;
    z-index: -1;
    background: radial-gradient(50% 55% at 50% 100%, rgba(10, 152, 110, 0.32), transparent 70%);
}
.final-in { padding-bottom: 3rem; }
.lp-drift {
    overflow: hidden;
    white-space: nowrap;
    line-height: 0.82;
    user-select: none;
}
.lp-drift span {
    display: inline-block;
    font-size: clamp(5rem, 19vw, 15rem);
    font-weight: 600;
    letter-spacing: -0.04em;
    color: transparent;
    -webkit-text-stroke: 1px rgba(10, 152, 110, 0.22);
    will-change: transform;
}

/* ——— Footer ——— */
.lp-footer { border-top: 1px solid var(--line); padding: 1.7rem 0; }
.lp-footer-in { display: flex; align-items: center; gap: 1.4rem; flex-wrap: wrap; }
.lp-footer-links { display: flex; gap: 1.2rem; margin-left: auto; }
.lp-footer-links a {
    color: var(--mut);
    font-size: 0.84rem;
    text-decoration: none;
}
.lp-footer-links a:hover { color: var(--txt); }
.lp-footer-copy { color: var(--mut); font-size: 0.84rem; }
@media (max-width: 640px) {
    .lp-footer-in { flex-direction: column; gap: 0.8rem; }
    .lp-footer-links { margin-left: 0; }
}

</style>
