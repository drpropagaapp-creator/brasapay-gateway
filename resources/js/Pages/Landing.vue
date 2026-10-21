<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AuroraCanvas from '@/components/landing/AuroraCanvas.vue';
import BrasaLogo from '@/components/landing/BrasaLogo.vue';
import CheckoutScene from '@/components/landing/CheckoutScene.vue';
import DashboardMock from '@/components/landing/DashboardMock.vue';
import EmberCanvas from '@/components/landing/EmberCanvas.vue';
import PhoneScene from '@/components/landing/PhoneScene.vue';

gsap.registerPlugin(ScrollTrigger);

/**
 * Landing pública do BrasaPay. Experiência cinematográfica em 8 atos:
 * hero com aurora e o painel ao vivo, posicionamento com texto que acende,
 * cena pinada do checkout, cena pinada do celular, benefícios com visuais
 * animados, jornada desenhada pelo scroll, futuro em faixas gigantes e CTA.
 */

const root = ref(null);
const introState = ref('idle');
let lenis = null;
let mm = null;
let heroPlayed = false;

// ——— Conteúdo ———
const HERO_LINES = [
    [{ w: 'Você' }, { w: 'vende.' }],
    [{ w: 'A' }, { w: 'BrasaPay' }, { w: 'impulsiona' }],
    [{ w: 'o' }, { w: 'seu' }, { w: 'próximo', g: true }, { w: 'nível.', g: true }],
];
const POS_HEAD = 'Você não precisa de mais uma plataforma. Precisa de uma que acompanhe sua ambição.';
const POS_SHIFTS = ['O mercado digital evoluiu.', 'Seus clientes mudaram.', 'Suas oportunidades cresceram.'];

const BENEFITS = [
    { kind: 'simple', title: 'Uma experiência simples', text: 'Menos complexidade na operação para você dedicar mais atenção ao crescimento do seu negócio.' },
    { kind: 'tech', title: 'Tecnologia que acompanha você', text: 'Uma experiência digital moderna, criada para acompanhar sua jornada de vendas.' },
    { kind: 'control', title: 'Controle para evoluir', text: 'Organize sua operação, acompanhe resultados e identifique oportunidades com mais clareza.' },
    { kind: 'ambition', title: 'Feita para sua ambição', text: 'Não importa se você está começando ou buscando um novo patamar. A BrasaPay quer fazer parte da sua evolução.' },
];

const JOURNEY = [
    { label: 'Primeiro pagamento', x: 20, y: 130 },
    { label: 'Primeiras conquistas', x: 340, y: 92 },
    { label: 'Novas oportunidades', x: 660, y: 56 },
    { label: 'Próximo nível', x: 980, y: 18 },
];
const JOURNEY_PATH = 'M20 130 C 160 132, 220 100, 340 92 S 540 74, 660 56 S 880 28, 980 18';

const FUTURE = ['Novas ideias', 'Novas oportunidades', 'Novas formas de fazer negócios'];

const FINAL_LINES = [
    'Sua ideia merece sair do papel.',
    'Seu negócio merece evoluir.',
    'E suas próximas vendas merecem uma experiência à altura.',
];

// Estrelas do céu do hero (posições fixas e pseudoaleatórias).
const STARS = Array.from({ length: 70 }, (_, i) => {
    const r = (n) => {
        const x = Math.sin(i * 12.9898 + n * 78.233) * 43758.5453;
        return x - Math.floor(x);
    };
    return { left: `${(r(1) * 100).toFixed(2)}%`, top: `${(r(2) * 70).toFixed(2)}%`, size: 1 + r(3) * 1.6, delay: `${(r(4) * 5).toFixed(2)}s` };
});

const year = new Date().getFullYear();

// ——— Abertura + entrada do hero ———
function heroEntrance(instant) {
    if (heroPlayed) return;
    heroPlayed = true;
    const q = gsap.utils.selector(root.value);
    if (instant) {
        gsap.set(q('.hero-word i, .hero-fade, .hero-dash-wrap, .hero-float, .lp-nav'), { clearProps: 'all' });
        return;
    }
    gsap.timeline({ defaults: { ease: 'power4.out' } })
        .to(q('.lp-nav'), { yPercent: 0, opacity: 1, duration: 0.7 }, 0)
        .to(q('.hero-word i'), { yPercent: 0, duration: 1.05, stagger: 0.055 }, 0.05)
        .to(q('.hero-fade'), { y: 0, opacity: 1, duration: 0.9, stagger: 0.08 }, 0.5)
        .to(q('.hero-dash-wrap'), { y: 0, opacity: 1, duration: 1.4, ease: 'expo.out' }, 0.75)
        .to(q('.hero-float'), { y: 0, opacity: 1, scale: 1, duration: 0.9, stagger: 0.15, ease: 'back.out(1.6)' }, 1.3);
}

function runIntro() {
    introState.value = 'play';
    setTimeout(() => heroEntrance(false), 2000);
    setTimeout(() => (introState.value = 'skip'), 2850);
}

// ——— Microinterações ———
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
    mm.add('all', () => {
        const q = gsap.utils.selector(root.value);

        // Rolagem suave.
        lenis = new Lenis({ duration: 1.15, smoothWheel: true });
        lenis.on('scroll', ScrollTrigger.update);
        const raf = (time) => lenis.raf(time * 1000);
        gsap.ticker.add(raf);
        gsap.ticker.lagSmoothing(0);

        // Âncoras da navegação rolam com o Lenis.
        q('a[href^="#"]').forEach((a) => {
            a.addEventListener('click', (ev) => {
                const id = a.getAttribute('href');
                if (id.length > 1 && document.querySelector(id)) {
                    ev.preventDefault();
                    lenis.scrollTo(id, { offset: -70 });
                }
            });
        });

        // Estados iniciais da entrada.
        if (!heroPlayed) {
            gsap.set(q('.hero-word i'), { yPercent: 115 });
            gsap.set(q('.hero-fade'), { y: 26, opacity: 0 });
            gsap.set(q('.hero-dash-wrap'), { y: 140, opacity: 0 });
            gsap.set(q('.hero-float'), { y: 30, opacity: 0, scale: 0.9 });
            gsap.set(q('.lp-nav'), { yPercent: -110, opacity: 0 });
        }

        // Progresso da leitura.
        gsap.to(q('.lp-progress'), { scaleX: 1, ease: 'none', scrollTrigger: { start: 0, end: 'max', scrub: 0.4 } });

        // Nav esconde ao descer e volta ao subir.
        let lastY = 0;
        ScrollTrigger.create({
            start: 140,
            end: 'max',
            onUpdate(self) {
                if (!heroPlayed) return;
                const y = self.scroll();
                gsap.to(q('.lp-nav'), { yPercent: y > lastY + 4 ? -110 : 0, duration: 0.35, ease: 'power2.out', overwrite: 'auto' });
                lastY = y;
            },
        });

        // O texto do hero recua enquanto o painel sobe.
        gsap.to(q('.hero-copy'), {
            yPercent: -18,
            opacity: 0,
            ease: 'none',
            scrollTrigger: { trigger: q('.hero')[0], start: 'top top', end: '45% top', scrub: true },
        });

        // O painel chega inclinado e assenta com o scroll.
        gsap.fromTo(
            q('.hero-dash'),
            { rotateX: 26, scale: 0.9, transformPerspective: 1600, transformOrigin: '50% 0%' },
            {
                rotateX: 0,
                scale: 1,
                ease: 'none',
                scrollTrigger: { trigger: q('.hero-stage')[0], start: 'top 92%', end: 'top 22%', scrub: 0.8 },
            }
        );

        // Cartões flutuantes em profundidades diferentes.
        q('.hero-float').forEach((el, i) => {
            gsap.to(el, {
                yPercent: i % 2 ? -90 : -160,
                ease: 'none',
                scrollTrigger: { trigger: q('.hero-stage')[0], start: 'top 80%', end: 'bottom top', scrub: true },
            });
        });

        // Texto que acende palavra por palavra.
        q('.scrub').forEach((el) => {
            const words = el.querySelectorAll('.scrub-w');
            gsap.set(words, { opacity: 0.13 });
            gsap.to(words, {
                opacity: 1,
                stagger: 0.08,
                ease: 'none',
                scrollTrigger: { trigger: el, start: 'top 82%', end: 'bottom 45%', scrub: true },
            });
        });

        // Frases que sobem em sequência.
        q('.pos-shift').forEach((el, i) => {
            gsap.fromTo(
                el,
                { yPercent: 105 },
                {
                    yPercent: 0,
                    duration: 1,
                    delay: i * 0.12,
                    ease: 'power4.out',
                    scrollTrigger: { trigger: q('.pos-shifts')[0], start: 'top 85%', once: true },
                }
            );
        });

        // Revelações genéricas.
        ScrollTrigger.batch(q('.lp-reveal'), {
            start: 'top 88%',
            once: true,
            onEnter: (els) =>
                gsap.fromTo(
                    els,
                    { y: 36, opacity: 0, filter: 'blur(8px)' },
                    { y: 0, opacity: 1, filter: 'blur(0px)', duration: 0.95, stagger: 0.1, ease: 'power3.out' }
                ),
        });

        // Jornada: o caminho se desenha e os marcos acendem.
        const nodes = q('.jr-node');
        const steps = q('.jr-step');
        gsap.fromTo(
            q('.jr-path'),
            { strokeDashoffset: 1 },
            {
                strokeDashoffset: 0,
                ease: 'none',
                scrollTrigger: {
                    trigger: q('.jr')[0],
                    start: 'top 78%',
                    end: 'bottom 50%',
                    scrub: 0.6,
                    onUpdate(self) {
                        const marks = [0.02, 0.34, 0.66, 0.97];
                        marks.forEach((m, i) => {
                            const on = self.progress >= m;
                            nodes[i]?.classList.toggle('is-on', on);
                            steps[i]?.classList.toggle('is-on', on);
                        });
                    },
                },
            }
        );

        // Futuro: faixas gigantes deslizando em sentidos opostos.
        gsap.fromTo(
            q('.fut-row-a'),
            { xPercent: 0 },
            { xPercent: -28, ease: 'none', scrollTrigger: { trigger: q('.fut')[0], start: 'top bottom', end: 'bottom top', scrub: true } }
        );
        gsap.fromTo(
            q('.fut-row-b'),
            { xPercent: -28 },
            { xPercent: 0, ease: 'none', scrollTrigger: { trigger: q('.fut')[0], start: 'top bottom', end: 'bottom top', scrub: true } }
        );

        // Final: linhas em cascata + glow que respira + assinatura deslizando.
        gsap.fromTo(
            q('.final-line'),
            { y: 40, opacity: 0 },
            {
                y: 0,
                opacity: 1,
                duration: 1,
                stagger: 0.18,
                ease: 'power3.out',
                scrollTrigger: { trigger: q('.final-lines')[0], start: 'top 82%', once: true },
            }
        );
        gsap.to(q('.final-glow'), { scale: 1.18, opacity: 0.9, duration: 2.8, yoyo: true, repeat: -1, ease: 'sine.inOut' });
        gsap.fromTo(
            q('.lp-drift span'),
            { xPercent: 4 },
            { xPercent: -4, ease: 'none', scrollTrigger: { trigger: q('.lp-drift')[0], start: 'top bottom', end: 'bottom top', scrub: true } }
        );

        requestAnimationFrame(() => {
            ScrollTrigger.sort();
            ScrollTrigger.refresh();
        });

        return () => {
            gsap.ticker.remove(raf);
            lenis?.destroy();
            lenis = null;
        };
    });
});

onBeforeUnmount(() => {
    mm?.revert();
    ScrollTrigger.getAll().forEach((t) => t.kill());
    lenis?.destroy();
});
</script>

<template>
    <Head title="BrasaPay — Tecnologia para vender. Inteligência para crescer.">
        <meta
            name="description"
            content="BrasaPay: uma plataforma de pagamentos criada para transformar a maneira como você vende, acompanha seus resultados e faz seu negócio crescer."
        />
        <meta property="og:title" content="BrasaPay — Tecnologia para vender. Inteligência para crescer." />
        <meta property="og:description" content="Você vende. A BrasaPay impulsiona o seu próximo nível." />
    </Head>

    <div ref="root" class="lp" :data-intro="introState">
        <!-- Abertura -->
        <div v-if="introState === 'play'" class="intro" aria-hidden="true">
            <span class="intro-glow"></span>
            <div class="intro-lockup">
                <img src="/images/brasapay/logo-branca.png" alt="" class="intro-logo" />
                <span class="intro-shine"></span>
            </div>
            <span class="intro-tag">Tecnologia para vender. Inteligência para crescer.</span>
        </div>

        <span class="lp-progress" aria-hidden="true"></span>

        <!-- Navegação -->
        <header class="lp-nav">
            <div class="lp-wrap lp-nav-in">
                <a href="#topo" aria-label="BrasaPay — início"><BrasaLogo :size="34" /></a>
                <nav class="lp-nav-links" aria-label="Seções">
                    <a href="#checkout">Checkout</a>
                    <a href="#gestao">Gestão</a>
                    <a href="#solucoes">Soluções</a>
                    <a href="#publico">Para quem</a>
                </nav>
                <div class="lp-nav-cta">
                    <Link href="/login" class="lp-btn lp-btn-ghost">Entrar</Link>
                    <Link href="/cadastro" class="lp-btn lp-btn-green">Começar agora</Link>
                </div>
            </div>
        </header>

        <main>
            <!-- 1. HERO -->
            <section id="topo" class="hero">
                <AuroraCanvas />
                <div class="hero-stars" aria-hidden="true">
                    <i v-for="(s, i) in STARS" :key="i" :style="{ left: s.left, top: s.top, width: `${s.size}px`, height: `${s.size}px`, animationDelay: s.delay }"></i>
                </div>
                <div class="hero-horizon" aria-hidden="true"></div>

                <div class="lp-wrap hero-copy">
                    <p class="lp-pill hero-fade">
                        <img src="/images/brasapay/simbolo.png" alt="" class="lp-pill-mark" />
                        A nova geração de vendas online
                    </p>
                    <h1 class="hero-h1" aria-label="Você vende. A BrasaPay impulsiona o seu próximo nível.">
                        <span v-for="(line, li) in HERO_LINES" :key="li" class="hero-line" aria-hidden="true">
                            <span v-for="(word, wi) in line" :key="wi" class="hero-word"><i :class="{ 'lp-grad': word.g }">{{ word.w }}</i></span>
                        </span>
                    </h1>
                    <p class="lp-sub hero-fade">
                        Uma plataforma de pagamentos criada para transformar a maneira como você vende,
                        acompanha seus resultados e faz seu negócio crescer.
                    </p>
                    <p class="hero-trio hero-fade">
                        <span>Menos complicação.</span><span>Mais controle.</span><span>Mais possibilidades para suas vendas.</span>
                    </p>
                    <div class="hero-ctas hero-fade">
                        <Link href="/cadastro" class="lp-btn lp-btn-green lp-btn-xl" @pointermove="magnetic" @pointerleave="magneticOut">
                            Comece a vender agora <span aria-hidden="true">→</span>
                        </Link>
                    </div>
                    <p class="hero-micro hero-fade">Conheça uma nova experiência em pagamentos digitais.</p>
                </div>

                <div class="lp-wrap hero-stage">
                    <div class="hero-dash-wrap">
                        <div class="hero-dash-glow" aria-hidden="true"></div>
                        <div class="hero-dash"><DashboardMock /></div>
                    </div>
                    <div class="hero-float hero-float-a" aria-hidden="true">
                        <img src="/images/brasapay/simbolo.png" alt="" />
                        <span><strong>Venda aprovada</strong><em>R$ 497,00 · PIX</em></span>
                    </div>
                    <div class="hero-float hero-float-b" aria-hidden="true">
                        <span class="hero-float-ic">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
                        </span>
                        <span><strong>Saque enviado</strong><em>R$ 2.300,00 · agora</em></span>
                    </div>
                </div>
            </section>

            <!-- 2. POSICIONAMENTO -->
            <section id="posicionamento" class="lp-sec pos">
                <div class="lp-wrap">
                    <p class="lp-eyebrow lp-reveal">SEU NEGÓCIO MERECE MAIS</p>
                    <h2 class="pos-h scrub" :aria-label="POS_HEAD">
                        <span v-for="(w, i) in POS_HEAD.split(' ')" :key="i" class="scrub-w" aria-hidden="true">{{ w }}&nbsp;</span>
                    </h2>
                    <div class="pos-grid">
                        <div class="pos-shifts">
                            <span v-for="s in POS_SHIFTS" :key="s" class="pos-shift-mask"><span class="pos-shift">{{ s }}</span></span>
                        </div>
                        <div class="pos-body">
                            <p class="lp-sub-sm lp-reveal">E sua plataforma de pagamentos precisa acompanhar tudo isso.</p>
                            <p class="lp-sub-sm lp-reveal">
                                A BrasaPay nasce com uma proposta clara: unir tecnologia, simplicidade e uma experiência
                                inteligente para que você tenha mais liberdade para fazer o que realmente importa.
                            </p>
                            <p class="pos-strong lp-reveal"><span class="lp-grad">Vender, crescer e conquistar novos resultados.</span></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. CHECKOUT -->
            <div id="checkout"><CheckoutScene /></div>

            <!-- 4. GESTÃO -->
            <div id="gestao"><PhoneScene /></div>

            <!-- 5. BENEFÍCIOS -->
            <section id="solucoes" class="lp-sec">
                <div class="lp-wrap">
                    <p class="lp-eyebrow lp-reveal">MAIS DO QUE PAGAMENTOS</p>
                    <h2 class="lp-h2 lp-reveal">Tudo começa com uma venda.<br /><span class="lp-grad">O próximo nível depende de você.</span></h2>
                    <div class="ben">
                        <article v-for="b in BENEFITS" :key="b.title" class="ben-card lp-reveal" @pointermove="spot" @pointerleave="spotOut">
                            <div class="ben-vis" aria-hidden="true">
                                <!-- Simples: um clique e aprovado -->
                                <div v-if="b.kind === 'simple'" class="v-simple">
                                    <span class="v-btn"><span class="v-btn-a">Pagar com PIX</span><span class="v-btn-b">Aprovado</span></span>
                                    <svg class="v-cursor" viewBox="0 0 24 24"><path d="M5 3l14 8-6 2-2 6z" fill="#eaf4f3" stroke="#061013" stroke-width="1.2" stroke-linejoin="round" /></svg>
                                </div>
                                <!-- Tecnologia: o símbolo emitindo pulsos -->
                                <div v-else-if="b.kind === 'tech'" class="v-tech">
                                    <i></i><i></i><i></i>
                                    <img src="/images/brasapay/simbolo.png" alt="" />
                                </div>
                                <!-- Controle: barras crescendo -->
                                <div v-else-if="b.kind === 'control'" class="v-control">
                                    <i v-for="n in 7" :key="n" :style="{ '--h': `${[38, 52, 44, 66, 58, 80, 96][n - 1]}%`, '--d': `${n * 0.08}s` }"></i>
                                </div>
                                <!-- Ambição: curva subindo -->
                                <svg v-else class="v-ambition" viewBox="0 0 200 90">
                                    <defs>
                                        <linearGradient id="v-amb" x1="0" y1="0" x2="1" y2="0">
                                            <stop offset="0%" stop-color="#5cb536" />
                                            <stop offset="100%" stop-color="#008894" />
                                        </linearGradient>
                                    </defs>
                                    <path d="M6 80 C 50 78, 70 60, 100 54 S 150 30, 190 10" pathLength="1" class="v-amb-path" stroke="url(#v-amb)" />
                                    <circle cx="190" cy="10" r="5" class="v-amb-dot" />
                                </svg>
                            </div>
                            <h3 class="lp-h3">{{ b.title }}</h3>
                            <p class="ben-text">{{ b.text }}</p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- 6. PÚBLICO -->
            <section id="publico" class="lp-sec pub">
                <div class="lp-wrap">
                    <p class="lp-eyebrow lp-reveal">SEU NEGÓCIO. SUAS CONQUISTAS.</p>
                    <h2 class="lp-h2 lp-reveal">Do primeiro pagamento aos<br /><span class="lp-grad">seus maiores resultados.</span></h2>

                    <div class="jr" aria-hidden="true">
                        <svg viewBox="0 0 1000 150" class="jr-svg" preserveAspectRatio="xMidYMid meet">
                            <defs>
                                <linearGradient id="jr-g" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0%" stop-color="#5cb536" />
                                    <stop offset="55%" stop-color="#0a986e" />
                                    <stop offset="100%" stop-color="#008894" />
                                </linearGradient>
                            </defs>
                            <path :d="JOURNEY_PATH" class="jr-track" />
                            <path :d="JOURNEY_PATH" pathLength="1" class="jr-path" stroke="url(#jr-g)" />
                            <g v-for="n in JOURNEY" :key="n.label" class="jr-node">
                                <circle :cx="n.x" :cy="n.y" r="13" class="jr-halo" />
                                <circle :cx="n.x" :cy="n.y" r="6" class="jr-dot" />
                            </g>
                        </svg>
                        <ol class="jr-steps">
                            <li v-for="n in JOURNEY" :key="n.label" class="jr-step">{{ n.label }}</li>
                        </ol>
                    </div>

                    <div class="pub-cards">
                        <article class="pub-card lp-reveal">
                            <p class="pub-tag">Para quem está começando</p>
                            <p class="pub-text">Cada venda representa uma <span class="lp-grad">conquista.</span></p>
                        </article>
                        <article class="pub-card lp-reveal">
                            <p class="pub-tag">Para quem já vende</p>
                            <p class="pub-text">Cada nova oportunidade representa um <span class="lp-grad">próximo nível.</span></p>
                        </article>
                    </div>
                    <div class="pub-close lp-reveal">
                        <p class="lp-sub-sm">A BrasaPay foi pensada para acompanhar essas diferentes jornadas com uma experiência moderna e objetiva.</p>
                        <p class="lp-strong">Seu próximo resultado começa com a decisão de evoluir.</p>
                        <Link href="/cadastro" class="lp-btn lp-btn-green" @pointermove="magnetic" @pointerleave="magneticOut">Criar minha conta <span aria-hidden="true">→</span></Link>
                    </div>
                </div>
            </section>

            <!-- 7. FUTURO -->
            <section class="fut">
                <div class="fut-bands" aria-hidden="true">
                    <div class="fut-row fut-row-a">
                        <span v-for="n in 3" :key="`a${n}`" class="fut-group">
                            <template v-for="(f, i) in FUTURE" :key="`a${n}${i}`"><b :class="{ 'is-outline': i % 2 }">{{ f }}</b><img src="/images/brasapay/simbolo.png" alt="" /></template>
                        </span>
                    </div>
                    <div class="fut-row fut-row-b">
                        <span v-for="n in 3" :key="`b${n}`" class="fut-group">
                            <template v-for="(f, i) in FUTURE" :key="`b${n}${i}`"><b :class="{ 'is-outline': !(i % 2) }">{{ f }}</b><img src="/images/brasapay/simbolo.png" alt="" /></template>
                        </span>
                    </div>
                </div>
                <div class="lp-wrap fut-in">
                    <p class="lp-eyebrow lp-reveal">O FUTURO NÃO ESPERA</p>
                    <h2 class="lp-h2 lp-reveal">O digital não para de evoluir.<br /><span class="lp-grad">E você também não deveria.</span></h2>
                    <p class="lp-sub-sm fut-p lp-reveal">Novas ideias. Novas oportunidades. Novas formas de fazer negócios.</p>
                    <p class="lp-sub-sm fut-p lp-reveal">
                        Acreditamos que a tecnologia deve simplificar o caminho de quem empreende, e não torná-lo mais complicado.
                    </p>
                    <p class="fut-why lp-reveal">É por isso que existe a <span class="lp-grad">BrasaPay.</span></p>
                    <p class="lp-sub-sm fut-p lp-reveal">Para conectar a evolução dos pagamentos à ambição de quem quer ir mais longe.</p>
                </div>
            </section>

            <!-- 8. CTA FINAL -->
            <section class="final">
                <EmberCanvas :density="45" />
                <div class="final-glow" aria-hidden="true"></div>
                <div class="lp-wrap final-in">
                    <p class="lp-eyebrow lp-reveal">SUA PRÓXIMA CONQUISTA COMEÇA AQUI.</p>
                    <h2 class="final-h lp-reveal">Grandes resultados começam com o <span class="lp-grad">primeiro movimento.</span></h2>
                    <div class="final-lines">
                        <p v-for="l in FINAL_LINES" :key="l" class="final-line">{{ l }}</p>
                    </div>
                    <p class="final-sign lp-reveal"><strong>BrasaPay.</strong> Seu negócio em movimento.</p>
                    <div class="hero-ctas lp-reveal">
                        <Link href="/cadastro" class="lp-btn lp-btn-green lp-btn-xl" @pointermove="magnetic" @pointerleave="magneticOut">
                            Começar agora <span aria-hidden="true">→</span>
                        </Link>
                    </div>
                </div>
                <div class="lp-drift" aria-hidden="true"><span>brasapay</span></div>
            </section>
        </main>

        <footer class="lp-footer">
            <div class="lp-wrap lp-footer-in">
                <div class="lp-footer-brand">
                    <BrasaLogo :size="30" />
                    <p>Tecnologia para vender. Inteligência para crescer.</p>
                </div>
                <nav class="lp-footer-links" aria-label="Rodapé">
                    <a href="#solucoes">Soluções</a>
                    <Link href="/termos-de-uso">Termos de Uso</Link>
                    <Link href="/politica-privacidade">Privacidade</Link>
                </nav>
            </div>
            <div class="lp-wrap lp-footer-copy">© {{ year }} BrasaPay. Todos os direitos reservados.</div>
        </footer>
    </div>
</template>

<style scoped>
/* ——— Tokens (paleta oficial da logo) ——— */
.lp {
    --bg: #051013;
    --panel: rgba(12, 23, 24, 0.72);
    --line: rgba(100, 175, 180, 0.15);
    --g1: #5cb536;
    --g2: #0a986e;
    --g3: #008894;
    --txt: #eef6f5;
    --mut: #8fa6a6;
    --ease-cinema: cubic-bezier(0.16, 1, 0.3, 1);

    background: var(--bg);
    color: var(--txt);
    font-family: 'Lexend', ui-sans-serif, system-ui, sans-serif;
    font-weight: 300;
    min-height: 100vh;
    overflow-x: clip;
}

/* ——— Utilitários compartilhados com as cenas ——— */
:deep(.lp-wrap) {
    width: min(1180px, 100% - 2.5rem);
    margin-inline: auto;
}
:deep(.lp-grad) {
    background: linear-gradient(95deg, var(--g1), var(--g2) 55%, var(--g3));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
:deep(.lp-h2) {
    font-size: clamp(1.8rem, 3.9vw, 2.9rem);
    font-weight: 600;
    line-height: 1.12;
    letter-spacing: -0.025em;
    margin: 0 0 1.1rem;
    color: var(--txt);
}
:deep(.lp-h3) {
    font-size: 1.16rem;
    font-weight: 500;
    margin: 0 0 0.5rem;
}
:deep(.lp-eyebrow) {
    font-size: 0.72rem;
    font-weight: 500;
    letter-spacing: 0.26em;
    color: var(--g2);
    margin: 0 0 1rem;
}
:deep(.lp-sub-sm) {
    font-size: 1rem;
    line-height: 1.75;
    color: var(--mut);
    max-width: 32rem;
    margin: 0 0 1rem;
}
:deep(.lp-strong) {
    font-size: 1.04rem;
    font-weight: 500;
    line-height: 1.55;
    color: var(--txt);
    max-width: 30rem;
    margin: 0.4rem 0 1.6rem;
}
:deep(.sr-only) {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
:deep(.lp-btn) {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
    border-radius: 999px;
    padding: 0.68rem 1.45rem;
    font-size: 0.93rem;
    font-weight: 500;
    text-decoration: none;
    border: 1px solid transparent;
    transition: box-shadow 0.25s var(--ease-cinema), border-color 0.2s ease, transform 0.35s var(--ease-cinema);
    transform: translate(var(--tx, 0), var(--ty, 0));
}
:deep(.lp-btn-xl) {
    padding: 1rem 2.1rem;
    font-size: 1.04rem;
}
:deep(.lp-btn-green) {
    background: linear-gradient(95deg, var(--g1), var(--g2) 55%, var(--g3));
    color: #f6fffd;
    font-weight: 600;
    box-shadow: 0 10px 36px -10px rgba(10, 152, 110, 0.65), inset 0 1px 0 rgba(255, 255, 255, 0.25);
}
:deep(.lp-btn-green:hover) {
    box-shadow: 0 16px 48px -10px rgba(10, 152, 110, 0.9), inset 0 1px 0 rgba(255, 255, 255, 0.25);
}
:deep(.lp-btn-ghost) {
    color: var(--txt);
    border-color: var(--line);
    background: rgba(238, 246, 245, 0.04);
    backdrop-filter: blur(8px);
}
:deep(.lp-btn-ghost:hover) {
    border-color: rgba(10, 152, 110, 0.55);
}
:deep(.lp-btn:focus-visible) {
    outline: 2px solid var(--g1);
    outline-offset: 3px;
}
.lp-sub {
    font-size: 1.12rem;
    line-height: 1.7;
    color: var(--mut);
    max-width: 40rem;
    margin: 0 auto 1rem;
}
.lp-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0 0 1.5rem;
    padding: 0.42rem 1rem 0.42rem 0.6rem;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: var(--panel);
    backdrop-filter: blur(10px);
    font-size: 0.74rem;
    font-weight: 500;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}
.lp-pill-mark {
    width: 18px;
    height: 18px;
    object-fit: contain;
}
.lp-sec {
    padding: clamp(4.5rem, 12vh, 8rem) 0;
}

/* ——— Abertura ——— */
.intro {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    background: #030a0c;
    animation: intro-curtain 0.9s var(--ease-cinema) 1.95s both;
}
@keyframes intro-curtain {
    to { clip-path: inset(0 0 100% 0); visibility: hidden; }
}
.intro-glow {
    position: absolute;
    width: 52vmin;
    height: 52vmin;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(10, 152, 110, 0.32), rgba(0, 136, 148, 0.1) 50%, transparent 70%);
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
    clip-path: inset(0 100% 0 0);
    filter: blur(10px) brightness(1.6);
    animation: intro-reveal 1.1s var(--ease-cinema) 0.25s forwards, intro-focus 0.9s ease 0.8s forwards;
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
    animation: intro-shine 0.9s var(--ease-cinema) 1.2s forwards;
    pointer-events: none;
}
@keyframes intro-shine {
    to { transform: translateX(120%); }
}
.intro-tag {
    position: absolute;
    bottom: 14vh;
    padding-inline: 1rem;
    text-align: center;
    font-size: 0.78rem;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: rgba(238, 246, 245, 0.5);
    opacity: 0;
    animation: intro-tag 0.8s var(--ease-cinema) 1.3s forwards;
}
@keyframes intro-tag {
    from { opacity: 0; transform: translateY(14px); }
    to { opacity: 1; transform: none; }
}

/* ——— Progresso / Nav ——— */
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
.lp-nav {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 50;
    background: rgba(5, 16, 19, 0.62);
    backdrop-filter: blur(18px) saturate(1.3);
    border-bottom: 1px solid var(--line);
}
.lp-nav-in {
    display: flex;
    align-items: center;
    gap: 2rem;
    height: 70px;
}
.lp-nav-links {
    display: flex;
    gap: 0.3rem;
    margin-left: auto;
    padding: 0.3rem;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: rgba(238, 246, 245, 0.03);
}
.lp-nav-links a {
    padding: 0.42rem 0.95rem;
    border-radius: 999px;
    color: var(--mut);
    font-size: 0.86rem;
    text-decoration: none;
    transition: color 0.15s ease, background-color 0.2s ease;
}
.lp-nav-links a:hover {
    color: var(--txt);
    background: rgba(10, 152, 110, 0.14);
}
.lp-nav-cta {
    display: flex;
    gap: 0.6rem;
    margin-left: auto;
}
@media (max-width: 900px) {
    .lp-nav-links { display: none; }
}

/* ——— 1. Hero ——— */
.hero {
    position: relative;
    padding-top: clamp(7.5rem, 17vh, 10rem);
    text-align: center;
    overflow: hidden;
    isolation: isolate;
    background:
        radial-gradient(120% 70% at 50% 0%, #0a2a2e 0%, #061518 45%, var(--bg) 75%);
}
.hero-stars {
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
}
.hero-stars i {
    position: absolute;
    border-radius: 50%;
    background: #dff5f1;
    opacity: 0.35;
    animation: twinkle 4.5s ease-in-out infinite;
}
@keyframes twinkle {
    50% { opacity: 0.9; transform: scale(1.4); }
}
.hero-horizon {
    position: absolute;
    left: 50%;
    top: clamp(560px, 70vh, 720px);
    width: 140%;
    height: 520px;
    transform: translateX(-50%);
    border-radius: 50%;
    background: radial-gradient(50% 50% at 50% 0%, rgba(10, 152, 110, 0.45), rgba(0, 136, 148, 0.12) 45%, transparent 70%);
    filter: blur(20px);
    z-index: -1;
    pointer-events: none;
}
.hero-copy {
    position: relative;
    will-change: transform, opacity;
}
.hero-h1 {
    margin: 0 auto 1.4rem;
    max-width: 15ch;
    font-size: clamp(2.5rem, 6.4vw, 5rem);
    font-weight: 600;
    line-height: 1.04;
    letter-spacing: -0.035em;
}
.hero-line {
    display: block;
}
.hero-word {
    display: inline-block;
    overflow: hidden;
    padding-bottom: 0.1em;
    margin-bottom: -0.1em;
    margin-right: 0.24em;
}
.hero-word:last-child { margin-right: 0; }
.hero-word i {
    display: inline-block;
    font-style: normal;
    will-change: transform;
}
.hero-trio {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.4rem 1.4rem;
    margin: 0 0 2.1rem;
    font-size: 0.95rem;
    color: var(--txt);
}
.hero-trio span {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.hero-trio span::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--g1), var(--g3));
    box-shadow: 0 0 10px rgba(10, 152, 110, 0.9);
}
.hero-ctas {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.9rem;
}
.hero-micro {
    margin: 1.1rem 0 0;
    font-size: 0.82rem;
    color: var(--mut);
}
.hero-stage {
    position: relative;
    margin-top: clamp(3.5rem, 8vh, 5rem);
    padding-bottom: clamp(3rem, 8vh, 5rem);
}
.hero-dash-wrap {
    position: relative;
}
.hero-dash-glow {
    position: absolute;
    inset: -6% 8% auto;
    height: 60%;
    background: radial-gradient(50% 60% at 50% 0%, rgba(10, 152, 110, 0.5), transparent 70%);
    filter: blur(40px);
    pointer-events: none;
}
.hero-dash {
    position: relative;
    will-change: transform;
}
.hero-float {
    position: absolute;
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.75rem 1.1rem 0.75rem 0.75rem;
    border-radius: 16px;
    text-align: left;
    background: rgba(13, 31, 35, 0.88);
    border: 1px solid rgba(100, 175, 180, 0.28);
    backdrop-filter: blur(14px);
    box-shadow: 0 24px 60px -24px rgba(0, 0, 0, 0.9), 0 0 40px -12px rgba(10, 152, 110, 0.45);
    z-index: 4;
    will-change: transform;
}
.hero-float img { width: 34px; height: 34px; object-fit: contain; }
.hero-float span { display: grid; line-height: 1.3; }
.hero-float strong { font-size: 0.86rem; font-weight: 600; }
.hero-float em { font-style: normal; font-size: 0.76rem; color: #7fe0b0; }
.hero-float-a { left: -2.5%; top: 26%; }
.hero-float-b { right: -2.5%; top: 64%; }
.hero-float-ic {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: grid !important;
    place-items: center;
    color: #f6fffd;
    background: linear-gradient(135deg, var(--g2), var(--g3));
}
.hero-float-ic svg { width: 18px; height: 18px; }
@media (max-width: 900px) {
    .hero-float { display: none; }
}

/* ——— 2. Posicionamento ——— */
.pos-h {
    margin: 0 0 clamp(2.5rem, 6vh, 4rem);
    max-width: 22ch;
    font-size: clamp(2rem, 4.6vw, 3.6rem);
    font-weight: 600;
    line-height: 1.12;
    letter-spacing: -0.03em;
}
.scrub-w { display: inline-block; }
.pos-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: clamp(2rem, 6vw, 5rem);
    align-items: start;
}
@media (max-width: 860px) {
    .pos-grid { grid-template-columns: 1fr; }
}
.pos-shifts { display: grid; gap: 0.35rem; }
.pos-shift-mask {
    display: block;
    overflow: hidden;
    padding-bottom: 0.08em;
}
.pos-shift {
    display: block;
    font-size: clamp(1.25rem, 2.4vw, 1.75rem);
    font-weight: 500;
    letter-spacing: -0.015em;
    color: var(--txt);
}
.pos-shift-mask:nth-child(3) .pos-shift {
    background: linear-gradient(95deg, var(--g1), var(--g3));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.pos-strong {
    margin: 0.6rem 0 0;
    font-size: clamp(1.15rem, 2vw, 1.4rem);
    font-weight: 600;
}

/* ——— 5. Benefícios ——— */
.ben {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.1rem;
    margin-top: 2.6rem;
    perspective: 1400px;
}
@media (max-width: 760px) {
    .ben { grid-template-columns: 1fr; }
}
.ben-card {
    position: relative;
    padding: 1.6rem 1.6rem 1.7rem;
    border-radius: 22px;
    background: linear-gradient(170deg, rgba(14, 32, 36, 0.8), rgba(8, 19, 22, 0.8));
    border: 1px solid var(--line);
    overflow: hidden;
    transform: rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg));
    transition: transform 0.3s var(--ease-cinema), border-color 0.25s ease;
}
.ben-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(340px circle at var(--mx, 50%) var(--my, 50%), rgba(10, 152, 110, 0.16), transparent 65%);
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}
.ben-card:hover { border-color: rgba(10, 152, 110, 0.5); }
.ben-card:hover::before { opacity: 1; }
.ben-text {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.65;
    color: var(--mut);
    max-width: 30rem;
}
.ben-vis {
    position: relative;
    height: 132px;
    margin-bottom: 1.3rem;
    border-radius: 16px;
    background:
        radial-gradient(70% 90% at 50% 100%, rgba(10, 152, 110, 0.14), transparent 70%),
        rgba(5, 16, 19, 0.6);
    border: 1px solid rgba(100, 175, 180, 0.1);
    display: grid;
    place-items: center;
    overflow: hidden;
}

/* Visual: simples */
.v-simple { position: relative; }
.v-btn {
    position: relative;
    display: grid;
    place-items: center;
    width: 190px;
    height: 46px;
    border-radius: 999px;
    font-size: 0.86rem;
    font-weight: 600;
    color: #f6fffd;
    background: linear-gradient(95deg, var(--g1), var(--g2) 55%, var(--g3));
    box-shadow: 0 10px 30px -10px rgba(10, 152, 110, 0.7);
    animation: v-press 4s var(--ease-cinema) infinite;
}
.v-btn-a, .v-btn-b { grid-area: 1 / 1; }
.v-btn-a { animation: v-a 4s ease infinite; }
.v-btn-b { opacity: 0; animation: v-b 4s ease infinite; }
@keyframes v-press {
    0%, 38% { transform: scale(1); }
    44% { transform: scale(0.94); }
    52%, 100% { transform: scale(1); }
}
@keyframes v-a {
    0%, 46% { opacity: 1; }
    52%, 88% { opacity: 0; }
    96%, 100% { opacity: 1; }
}
@keyframes v-b {
    0%, 46% { opacity: 0; }
    52%, 88% { opacity: 1; }
    96%, 100% { opacity: 0; }
}
.v-cursor {
    position: absolute;
    width: 22px;
    height: 22px;
    left: 62%;
    top: 58%;
    animation: v-cursor 4s var(--ease-cinema) infinite;
}
@keyframes v-cursor {
    0% { transform: translate(70px, 50px); opacity: 0; }
    12% { opacity: 1; }
    38% { transform: translate(0, 0); }
    44% { transform: translate(0, 0) scale(0.85); }
    52% { transform: translate(0, 0) scale(1); }
    80% { opacity: 1; }
    100% { transform: translate(-20px, 40px); opacity: 0; }
}

/* Visual: tecnologia */
.v-tech { position: relative; display: grid; place-items: center; width: 100%; height: 100%; }
.v-tech img { position: relative; width: 52px; height: 52px; object-fit: contain; z-index: 1; filter: drop-shadow(0 0 14px rgba(10, 152, 110, 0.6)); }
.v-tech i {
    position: absolute;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: 1.5px solid rgba(10, 152, 110, 0.7);
    animation: v-ring 3s ease-out infinite;
}
.v-tech i:nth-child(2) { animation-delay: 1s; }
.v-tech i:nth-child(3) { animation-delay: 2s; }
@keyframes v-ring {
    from { transform: scale(0.8); opacity: 0.9; }
    to { transform: scale(3.2); opacity: 0; }
}

/* Visual: controle */
.v-control {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    height: 88px;
}
.v-control i {
    width: 18px;
    height: var(--h);
    border-radius: 6px 6px 3px 3px;
    background: linear-gradient(180deg, var(--g1), var(--g3));
    transform-origin: bottom;
    animation: v-bar 3.6s var(--ease-cinema) infinite;
    animation-delay: var(--d);
    opacity: 0.9;
}
@keyframes v-bar {
    0% { transform: scaleY(0.12); }
    35%, 80% { transform: scaleY(1); }
    100% { transform: scaleY(0.12); }
}

/* Visual: ambição */
.v-ambition { width: 78%; height: 100px; overflow: visible; }
.v-amb-path {
    fill: none;
    stroke-width: 4;
    stroke-linecap: round;
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
    animation: v-draw 3.4s var(--ease-cinema) infinite;
}
@keyframes v-draw {
    0% { stroke-dashoffset: 1; }
    55%, 85% { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: 0; opacity: 0; }
}
.v-amb-dot {
    fill: #eef6f5;
    stroke: var(--g3);
    stroke-width: 3;
    opacity: 0;
    animation: v-dot 3.4s ease infinite;
}
@keyframes v-dot {
    0%, 50% { opacity: 0; }
    58%, 85% { opacity: 1; }
    100% { opacity: 0; }
}

/* ——— 6. Público / jornada ——— */
.jr {
    margin: clamp(2rem, 5vh, 3.5rem) 0 clamp(2.5rem, 6vh, 4rem);
}
.jr-svg {
    width: 100%;
    height: auto;
    overflow: visible;
}
.jr-track {
    fill: none;
    stroke: rgba(100, 175, 180, 0.14);
    stroke-width: 3;
    stroke-dasharray: 2 10;
    stroke-linecap: round;
}
.jr-path {
    fill: none;
    stroke-width: 4;
    stroke-linecap: round;
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
    filter: drop-shadow(0 0 8px rgba(10, 152, 110, 0.6));
}
.jr-dot {
    fill: #0b1c20;
    stroke: rgba(100, 175, 180, 0.4);
    stroke-width: 2;
    transition: fill 0.4s ease, stroke 0.4s ease;
}
.jr-halo {
    fill: rgba(10, 152, 110, 0.25);
    opacity: 0;
    transform-box: fill-box;
    transform-origin: center;
    transform: scale(0.4);
    transition: opacity 0.5s ease, transform 0.6s var(--ease-cinema);
}
.jr-node.is-on .jr-dot { fill: #eef6f5; stroke: var(--g2); }
.jr-node.is-on .jr-halo { opacity: 1; transform: scale(1); }
.jr-steps {
    list-style: none;
    margin: 0.8rem 0 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}
.jr-step {
    font-size: 0.92rem;
    font-weight: 500;
    color: var(--mut);
    opacity: 0.45;
    transition: opacity 0.5s ease, color 0.5s ease;
}
.jr-step:nth-child(2) { text-align: center; padding-right: 22%; }
.jr-step:nth-child(3) { text-align: center; padding-left: 22%; }
.jr-step:nth-child(4) { text-align: right; }
.jr-step.is-on { opacity: 1; color: var(--txt); }
@media (max-width: 760px) {
    .jr-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .jr-step { text-align: left !important; padding: 0 !important; }
}
.pub-cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.1rem;
    margin-bottom: 2.4rem;
}
@media (max-width: 760px) {
    .pub-cards { grid-template-columns: 1fr; }
}
.pub-card {
    padding: 1.7rem 1.6rem;
    border-radius: 22px;
    background: linear-gradient(170deg, rgba(14, 32, 36, 0.8), rgba(8, 19, 22, 0.8));
    border: 1px solid var(--line);
}
.pub-tag {
    margin: 0 0 0.7rem;
    font-size: 0.72rem;
    font-weight: 500;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--g2);
}
.pub-text {
    margin: 0;
    font-size: clamp(1.3rem, 2.4vw, 1.75rem);
    font-weight: 500;
    line-height: 1.3;
    letter-spacing: -0.015em;
}
.pub-close {
    display: grid;
    justify-items: start;
}

/* ——— 7. Futuro ——— */
.fut {
    position: relative;
    padding: clamp(3rem, 8vh, 5rem) 0 clamp(4.5rem, 12vh, 8rem);
    overflow: hidden;
}
.fut-bands {
    display: grid;
    gap: 0.6rem;
    margin-bottom: clamp(3rem, 8vh, 5rem);
    user-select: none;
}
.fut-row {
    display: flex;
    width: max-content;
    will-change: transform;
}
.fut-group {
    display: flex;
    align-items: center;
}
.fut-row b {
    font-size: clamp(2.6rem, 7vw, 5.6rem);
    font-weight: 600;
    letter-spacing: -0.035em;
    white-space: nowrap;
    color: var(--txt);
}
.fut-row b.is-outline {
    color: transparent;
    -webkit-text-stroke: 1.5px rgba(10, 152, 110, 0.55);
}
.fut-row img {
    width: clamp(34px, 5vw, 64px);
    height: auto;
    margin: 0 clamp(1rem, 2.5vw, 2rem);
    opacity: 0.9;
}
.fut-in {
    text-align: center;
}
.fut-in :deep(.lp-sub-sm),
.fut-p {
    margin-inline: auto;
    max-width: 38rem;
}
.fut-why {
    margin: 1.6rem 0 1rem;
    font-size: clamp(1.4rem, 2.8vw, 2rem);
    font-weight: 600;
    letter-spacing: -0.02em;
}

/* ——— 8. CTA final ——— */
.final {
    position: relative;
    padding: clamp(5rem, 12vh, 8rem) 0 0;
    text-align: center;
    overflow: hidden;
    isolation: isolate;
    border-top: 1px solid var(--line);
}
.final-glow {
    position: absolute;
    inset: auto -25% -75%;
    height: 130%;
    z-index: -1;
    background: radial-gradient(50% 55% at 50% 100%, rgba(10, 152, 110, 0.35), rgba(0, 136, 148, 0.12) 45%, transparent 70%);
}
.final-in {
    position: relative;
    padding-bottom: 3.5rem;
}
.final-h {
    margin: 0 auto 2rem;
    max-width: 20ch;
    font-size: clamp(2.1rem, 5vw, 3.9rem);
    font-weight: 600;
    line-height: 1.08;
    letter-spacing: -0.035em;
}
.final-lines {
    display: grid;
    gap: 0.45rem;
    margin-bottom: 1.8rem;
}
.final-line {
    margin: 0;
    font-size: clamp(1.02rem, 1.8vw, 1.22rem);
    color: var(--mut);
}
.final-sign {
    margin: 0 0 2rem;
    font-size: 1.1rem;
    color: var(--txt);
}
.final-sign strong { font-weight: 600; }
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
    -webkit-text-stroke: 1px rgba(10, 152, 110, 0.25);
    will-change: transform;
}

/* ——— Rodapé ——— */
.lp-footer {
    border-top: 1px solid var(--line);
    padding: 2.2rem 0 1.6rem;
}
.lp-footer-in {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.lp-footer-brand p {
    margin: 0.7rem 0 0;
    font-size: 0.86rem;
    color: var(--mut);
}
.lp-footer-links {
    display: flex;
    flex-wrap: wrap;
    gap: 1.4rem;
}
.lp-footer-links a {
    color: var(--mut);
    font-size: 0.88rem;
    text-decoration: none;
    transition: color 0.15s ease;
}
.lp-footer-links a:hover { color: var(--txt); }
.lp-footer-copy {
    margin-top: 1.8rem;
    padding-top: 1.2rem;
    border-top: 1px solid rgba(100, 175, 180, 0.08);
    font-size: 0.8rem;
    color: var(--mut);
}
</style>
