<script setup>
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { onBeforeUnmount, onMounted, ref } from 'vue';

gsap.registerPlugin(ScrollTrigger);

/**
 * Cena pinada do checkout: um navegador mostra a landing de um produto
 * FICTÍCIO ("Método Escala Digital") montada no próprio checkout. O scroll
 * "navega" a página, foca no bloco de compra, o QR PIX surge e o pagamento
 * aprova com um bloom de brilhos — a prova visual do produto.
 */
const scene = ref(null);
let mm = null;

function buildTimeline(scrubbed) {
    const q = gsap.utils.selector(scene.value);
    const tl = gsap.timeline(
        scrubbed
            ? {
                  scrollTrigger: {
                      trigger: scene.value,
                      start: 'top top',
                      end: '+=320%',
                      pin: true,
                      scrub: 1,
                      anticipatePin: 1,
                  },
              }
            : {
                  scrollTrigger: {
                      trigger: scene.value,
                      start: 'top 60%',
                      toggleActions: 'play none none none',
                  },
                  defaults: { duration: 0.7 },
              }
    );

    gsap.set(q('.ck-browser'), { rotateX: 16, y: 70, scale: 0.94, opacity: 0.4, transformPerspective: 1500 });
    gsap.set(q('.ck-page'), { yPercent: 0 });
    gsap.set(q('.ck-qr'), { scale: 0.6, opacity: 0, yPercent: 12 });
    gsap.set(q('.ck-paid'), { scale: 0.5, opacity: 0 });
    gsap.set(q('.ck-spark'), { scale: 0, opacity: 0 });
    gsap.set(q('.ck-line'), { yPercent: 40, opacity: 0 });

    tl.to(q('.ck-browser'), { rotateX: 0, y: 0, scale: 1, opacity: 1, duration: 1.1, ease: 'power2.out' })
        .to(q('.ck-line')[0], { yPercent: 0, opacity: 1, duration: 0.5, ease: 'power3.out' }, 0.2)

        // A página rola como se alguém navegasse.
        .to(q('.ck-page'), { yPercent: -58, duration: 2.6, ease: 'none' }, 1.0)

        // Troca da frase lateral no meio da navegação.
        .to(q('.ck-line')[0], { yPercent: -40, opacity: 0, duration: 0.35 }, 2.2)
        .to(q('.ck-line')[1], { yPercent: 0, opacity: 1, duration: 0.45, ease: 'power3.out' }, 2.4)

        // QR em foco.
        .to(q('.ck-qr'), { scale: 1, opacity: 1, yPercent: 0, duration: 0.6, ease: 'back.out(1.5)' }, 3.6)

        // Pagamento aprovado + brilhos.
        .to(q('.ck-qr'), { filter: 'blur(3px) brightness(0.65)', duration: 0.3 }, 4.5)
        .to(q('.ck-paid'), { scale: 1, opacity: 1, duration: 0.55, ease: 'back.out(1.8)' }, 4.55)
        .to(
            q('.ck-spark'),
            {
                scale: 1,
                opacity: 1,
                x: (i) => Math.cos((i / 8) * Math.PI * 2) * 92,
                y: (i) => Math.sin((i / 8) * Math.PI * 2) * 92,
                duration: 0.55,
                ease: 'power2.out',
                stagger: 0.02,
            },
            4.55
        )
        .to(q('.ck-spark'), { opacity: 0, duration: 0.4 }, 5.0)
        .to(q('.ck-line')[1], { yPercent: -40, opacity: 0, duration: 0.35 }, 4.7)
        .to(q('.ck-line')[2], { yPercent: 0, opacity: 1, duration: 0.45, ease: 'power3.out' }, 4.9);

    return tl;
}

onMounted(() => {
    mm = gsap.matchMedia();
    mm.add(
        {
            desktop: '(min-width: 900px)',
            mobile: '(max-width: 899px)',
        },
        (ctx) => {
            const tl = buildTimeline(Boolean(ctx.conditions.desktop));
            return () => tl.scrollTrigger?.kill();
        }
    );
});

onBeforeUnmount(() => mm?.revert());
</script>

<template>
    <section ref="scene" class="ck" aria-label="Demonstração do checkout com landing page">
        <div class="lp-wrap ck-grid">
            <div class="ck-copy">
                <p class="lp-eyebrow">CHECKOUT · LANDING EMBUTIDA</p>
                <div class="ck-lines" aria-hidden="true">
                    <h2 class="lp-h2 ck-line">O checkout é a sua<br /><span class="lp-grad">página de vendas.</span></h2>
                    <h2 class="lp-h2 ck-line">Vídeo, provas e oferta<br />no mesmo lugar.</h2>
                    <h2 class="lp-h2 ck-line">E o PIX aprova<br /><span class="lp-grad">em 1,9s.</span></h2>
                </div>
                <p class="lp-sub-sm">
                    Monte a landing inteira dentro do checkout: VSL, benefícios, avaliações e HTML
                    livre, na ordem que você quiser. O comprador nunca sai da página de pagamento.
                </p>
            </div>

            <div class="ck-stage" aria-hidden="true">
                <div class="ck-browser">
                    <div class="ck-bar">
                        <span class="ck-dots"><i></i><i></i><i></i></span>
                        <span class="ck-url">🔒 brasapay.com.br/c/metodo-escala-digital</span>
                    </div>

                    <div class="ck-viewport">
                        <div class="ck-page">
                            <div class="ck-pg-hero">
                                <p class="ck-pg-kicker">MENTORIA AO VIVO · TURMA 7</p>
                                <h3>Método Escala Digital</h3>
                                <p class="ck-pg-sub">Do primeiro produto a R$ 100 mil/mês com tráfego pago</p>
                            </div>
                            <div class="ck-pg-video">
                                <span class="ck-pg-play">▶</span>
                            </div>
                            <ul class="ck-pg-benefits">
                                <li>✓ 8 semanas de aulas ao vivo com o Rafa Monteiro</li>
                                <li>✓ Funis prontos de VSL e página de captura</li>
                                <li>✓ Comunidade fechada + suporte diário</li>
                            </ul>
                            <div class="ck-pg-review">
                                <span class="ck-pg-stars">★★★★★</span>
                                <p>"Fiz R$ 32 mil no primeiro lançamento seguindo o método."</p>
                                <span class="ck-pg-author">— Juliana S. · aluna da turma 6</span>
                            </div>
                            <div class="ck-pg-buy">
                                <div class="ck-pg-price">
                                    <span>Método Escala Digital</span>
                                    <strong>R$ 497,00</strong>
                                    <em>ou 12x de R$ 48,27</em>
                                </div>
                                <div class="ck-pg-input"></div>
                                <div class="ck-pg-input"></div>
                                <div class="ck-pg-btn">Pagar com PIX</div>
                                <p class="ck-pg-safe">🔒 Compra segura · BrasaPay</p>
                            </div>
                        </div>

                        <!-- QR em foco -->
                        <div class="ck-qr">
                            <svg viewBox="0 0 29 29">
                                <path
                                    fill="currentColor"
                                    d="M0 0h7v7H0zM2 2h3v3H2zM22 0h7v7h-7zM24 2h3v3h-3zM0 22h7v7H0zM2 24h3v3H2zM9 0h2v2H9zM13 1h2v3h-2zM17 0h2v4h-2zM20 9h3v2h-3zM9 4h3v2H9zM10 9h2v3h-2zM13 6h4v2h-4zM9 13h2v4H9zM12 14h3v2h-3zM16 12h2v3h-2zM19 14h3v3h-3zM24 10h3v2h-3zM26 14h3v3h-3zM23 18h3v2h-3zM13 18h3v3h-3zM9 20h2v3H9zM17 21h2v4h-2zM20 23h3v2h-3zM24 21h2v3h-2zM13 24h2v3h-2zM10 26h2v2h-2zM20 27h4v2h-4zM26 24h3v3h-3zM4 9h2v4H4zM0 10h3v2H0zM1 15h4v2H1zM5 17h2v3H5zM0 18h3v2H0z"
                                />
                            </svg>
                            <p>Escaneie para pagar · R$ 497,00</p>
                        </div>

                        <!-- Aprovado -->
                        <div class="ck-paid">
                            <span class="ck-paid-mark">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                            </span>
                            <strong>Pagamento aprovado</strong>
                            <span>R$ 497,00 · em 1,9s</span>
                        </div>
                        <span v-for="n in 8" :key="n" class="ck-spark"></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.ck {
    min-height: 100svh;
    display: flex;
    align-items: center;
    overflow: hidden;
}
.ck-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
    gap: clamp(2rem, 6vw, 5rem);
    align-items: center;
    width: 100%;
}
@media (max-width: 899px) {
    .ck { padding: 4rem 0; min-height: auto; }
    .ck-grid { grid-template-columns: 1fr; }
}
.ck-lines {
    position: relative;
    min-height: 7.2em;
}
.ck-line {
    position: absolute;
    inset: 0 auto auto 0;
    margin: 0;
}
/* Sem JS / movimento reduzido: só a primeira frase aparece. */
.ck-line:not(:first-child) { opacity: 0; }
.ck-stage {
    position: relative;
    display: grid;
    place-items: center;
}
.ck-browser {
    width: min(520px, 100%);
    border-radius: 18px;
    border: 1px solid rgba(140, 195, 200, 0.18);
    background: linear-gradient(170deg, rgba(20, 28, 23, 0.95), rgba(10, 15, 12, 0.98));
    box-shadow: 0 60px 120px -48px rgba(0, 0, 0, 0.95);
    overflow: hidden;
    will-change: transform;
}
.ck-bar {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.65rem 0.9rem;
    border-bottom: 1px solid rgba(140, 195, 200, 0.12);
}
.ck-dots { display: flex; gap: 5px; }
.ck-dots i {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: rgba(140, 195, 200, 0.25);
}
.ck-url {
    flex: 1;
    text-align: center;
    font-size: 0.7rem;
    color: rgba(238, 246, 239, 0.55);
    background: rgba(238, 246, 239, 0.05);
    border-radius: 999px;
    padding: 0.28rem 0.8rem;
}
.ck-viewport {
    position: relative;
    height: min(480px, 58vh);
    overflow: hidden;
}
.ck-page {
    will-change: transform;
    background: #f6f8f5;
    color: #15201a;
    font-family: 'Lexend', sans-serif;
}
.ck-pg-hero {
    padding: 2rem 1.4rem 1.4rem;
    background:
        radial-gradient(120% 90% at 85% 0%, rgba(10, 152, 110, 0.35), transparent 60%),
        linear-gradient(160deg, #0a1517, #0e2328);
    color: #f2f7f2;
    text-align: center;
}
.ck-pg-kicker {
    margin: 0 0 0.5rem;
    font-size: 0.62rem;
    letter-spacing: 0.22em;
    color: #8fdcd2;
}
.ck-pg-hero h3 {
    margin: 0 0 0.4rem;
    font-size: 1.55rem;
    font-weight: 600;
}
.ck-pg-sub {
    margin: 0;
    font-size: 0.82rem;
    color: rgba(242, 247, 242, 0.75);
}
.ck-pg-video {
    margin: 1rem 1.4rem;
    aspect-ratio: 16/9;
    border-radius: 12px;
    background: linear-gradient(160deg, #122226, #081114);
    display: grid;
    place-items: center;
}
.ck-pg-play {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    padding-left: 4px;
    color: #f4fcfa;
    background: linear-gradient(95deg, #5cb536, #0a986e);
    box-shadow: 0 0 0 0 rgba(10, 152, 110, 0.55);
    animation: ck-pulse 1.8s ease-out infinite;
}
@keyframes ck-pulse {
    to { box-shadow: 0 0 0 20px rgba(10, 152, 110, 0); }
}
.ck-pg-benefits {
    list-style: none;
    margin: 0 1.4rem 1rem;
    padding: 0;
    display: grid;
    gap: 0.5rem;
    font-size: 0.82rem;
}
.ck-pg-review {
    margin: 0 1.4rem 1.1rem;
    padding: 0.9rem 1rem;
    border-radius: 12px;
    background: #fff;
    border: 1px solid #e3eae2;
    font-size: 0.8rem;
}
.ck-pg-stars { color: #e8a914; letter-spacing: 2px; font-size: 0.78rem; }
.ck-pg-review p { margin: 0.35rem 0; }
.ck-pg-author { color: #6b7a70; font-size: 0.7rem; }
.ck-pg-buy {
    margin: 0 1.4rem 1.6rem;
    padding: 1rem;
    border-radius: 14px;
    background: #fff;
    border: 1px solid #dde7dd;
    display: grid;
    gap: 0.55rem;
    box-shadow: 0 18px 40px -24px rgba(21, 32, 26, 0.4);
}
.ck-pg-price {
    display: grid;
    gap: 0.1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px dashed #dde7dd;
}
.ck-pg-price span { font-size: 0.74rem; color: #6b7a70; }
.ck-pg-price strong { font-size: 1.3rem; font-weight: 600; }
.ck-pg-price em { font-style: normal; font-size: 0.7rem; color: #1f9d5f; }
.ck-pg-input {
    height: 30px;
    border-radius: 8px;
    border: 1px solid #d6e0d6;
    background: #fafcf9;
}
.ck-pg-btn {
    height: 38px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    font-size: 0.84rem;
    font-weight: 600;
    color: #f4fcfa;
    background: linear-gradient(95deg, #5cb536, #0a986e);
}
.ck-pg-safe {
    margin: 0;
    text-align: center;
    font-size: 0.64rem;
    color: #6b7a70;
}
.ck-qr {
    position: absolute;
    inset: 0;
    margin: auto;
    width: 210px;
    height: fit-content;
    padding: 1rem;
    border-radius: 18px;
    background: #fff;
    color: #10180f;
    text-align: center;
    box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.8);
    will-change: transform;
}
.ck-qr svg { width: 150px; height: 150px; }
.ck-qr p { margin: 0.5rem 0 0; font-size: 0.68rem; color: #4a5a4e; }
.ck-paid {
    position: absolute;
    inset: 0;
    margin: auto;
    width: 230px;
    height: fit-content;
    padding: 1.3rem 1rem;
    border-radius: 20px;
    display: grid;
    justify-items: center;
    gap: 0.25rem;
    background: rgba(9, 19, 21, 0.94);
    border: 1px solid rgba(10, 152, 110, 0.5);
    box-shadow: 0 0 60px -10px rgba(10, 152, 110, 0.45);
    will-change: transform;
}
.ck-paid-mark {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #5cb536, #0a986e);
    color: #f4fcfa;
    margin-bottom: 0.3rem;
}
.ck-paid-mark svg { width: 22px; height: 26px; }
.ck-paid strong { font-size: 0.98rem; font-weight: 600; color: #f2f7f2; }
.ck-paid span { font-size: 0.76rem; color: rgba(242, 247, 242, 0.65); }
.ck-spark {
    position: absolute;
    inset: 0;
    margin: auto;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: linear-gradient(95deg, #5cb536, #0a986e);
    box-shadow: 0 0 12px rgba(10, 152, 110, 0.9);
    pointer-events: none;
}
</style>
