<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Aurora do hero: cortinas de luz nas cores da marca ondulando devagar.
 * Renderiza em baixa resolução e o CSS amplia/desfoca — o resultado é uma
 * luz macia e barata de desenhar. Pausa fora da tela e com a aba oculta.
 */
const canvasRef = ref(null);
const SCALE = 0.16;
const RIBBONS = [
    { color: [0, 136, 148], y: 0.2, amp: 0.11, freq: 0.9, speed: 0.11, thick: 0.5, alpha: 0.5, phase: 3.1 },
    { color: [10, 152, 110], y: 0.12, amp: 0.1, freq: 1.4, speed: 0.16, thick: 0.38, alpha: 0.55, phase: 0 },
    { color: [92, 181, 54], y: 0.06, amp: 0.07, freq: 2.1, speed: -0.12, thick: 0.24, alpha: 0.32, phase: 1.7 },
];

let ctx = null;
let raf = 0;
let running = false;
let io = null;
let w = 0;
let h = 0;
let mx = 0;
let tmx = 0;
const t0 = performance.now();

function resize() {
    const c = canvasRef.value;
    if (!c) return;
    w = Math.max(80, Math.round(c.clientWidth * SCALE));
    h = Math.max(50, Math.round(c.clientHeight * SCALE));
    c.width = w;
    c.height = h;
}

function draw(now) {
    if (!running) return;
    const t = (now - t0) / 1000;
    mx += (tmx - mx) * 0.03;
    ctx.globalCompositeOperation = 'source-over';
    ctx.clearRect(0, 0, w, h);
    ctx.globalCompositeOperation = 'lighter';

    for (const r of RIBBONS) {
        const [cr, cg, cb] = r.color;
        for (let x = 0; x <= w; x += 2) {
            const nx = x / w;
            const wave =
                Math.sin(nx * r.freq * Math.PI * 2 + t * r.speed * 2 + r.phase + mx * 0.8) * r.amp +
                Math.sin(nx * r.freq * 3.1 + t * r.speed * 1.3 + r.phase * 2) * r.amp * 0.45;
            const top = (r.y + wave) * h;
            const len = r.thick * h * (0.6 + 0.4 * Math.sin(nx * 5 + t * 0.6 + r.phase));
            const flick = 0.7 + 0.3 * Math.sin(nx * 21 + t * 1.6 + r.phase);
            const g = ctx.createLinearGradient(0, top, 0, top + len);
            g.addColorStop(0, `rgba(${cr},${cg},${cb},0)`);
            g.addColorStop(0.1, `rgba(${cr},${cg},${cb},${(r.alpha * flick).toFixed(3)})`);
            g.addColorStop(1, `rgba(${cr},${cg},${cb},0)`);
            ctx.fillStyle = g;
            ctx.fillRect(x, top, 2, len);
        }
    }
    raf = requestAnimationFrame(draw);
}

function start() {
    if (running || !ctx) return;
    running = true;
    raf = requestAnimationFrame(draw);
}

function stop() {
    running = false;
    cancelAnimationFrame(raf);
}

function onMouse(e) {
    tmx = e.clientX / window.innerWidth - 0.5;
}

function onVisibility() {
    if (document.hidden) stop();
    else start();
}

onMounted(() => {
    const c = canvasRef.value;
    ctx = c.getContext('2d');
    resize();
    window.addEventListener('resize', resize);
    window.addEventListener('mousemove', onMouse, { passive: true });
    document.addEventListener('visibilitychange', onVisibility);
    io = new IntersectionObserver(([entry]) => (entry.isIntersecting ? start() : stop()));
    io.observe(c);
});

onBeforeUnmount(() => {
    stop();
    io?.disconnect();
    window.removeEventListener('resize', resize);
    window.removeEventListener('mousemove', onMouse);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <canvas ref="canvasRef" class="aurora" aria-hidden="true"></canvas>
</template>

<style scoped>
.aurora {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    display: block;
    pointer-events: none;
    filter: blur(16px) saturate(1.25);
    -webkit-mask-image: linear-gradient(180deg, #000 0%, #000 55%, transparent 100%);
    mask-image: linear-gradient(180deg, #000 0%, #000 55%, transparent 100%);
}
</style>
