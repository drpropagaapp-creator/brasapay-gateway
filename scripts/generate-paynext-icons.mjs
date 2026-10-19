#!/usr/bin/env node
/**
 * Gera resources/js/components/icons/paynext-icons.js a partir de paynext-icon-map.json.
 *
 * O Paynext DS usa Phosphor em duotone (e fill no estado ativo). O pacote Vue oficial
 * embute os 6 pesos de cada ícone; aqui extraímos só duotone e fill dos SVGs crus de
 * @phosphor-icons/core. Para usar um ícone Lucide novo numa tela, adicione o nome ao
 * JSON (Lucide → Phosphor) e rode:
 *
 *   PHOSPHOR_CORE_DIR=<pasta>/node_modules/@phosphor-icons/core node scripts/generate-paynext-icons.mjs
 *
 * @phosphor-icons/core não fica nas dependências do projeto: são ~9 mil SVGs, que em
 * disco exFAT (blocos de 1 MB) ocupam ~9 GB. Instale numa pasta temporária
 * (npm i --prefix <pasta> @phosphor-icons/core) e aponte PHOSPHOR_CORE_DIR para ela.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const mapFile = path.join(root, 'resources/js/components/icons/paynext-icon-map.json');
const outFile = path.join(root, 'resources/js/components/icons/paynext-icons.js');
const coreDir = process.env.PHOSPHOR_CORE_DIR || path.join(root, 'node_modules/@phosphor-icons/core');
const assets = path.join(coreDir, 'assets');
if (!fs.existsSync(assets)) {
    console.error(`@phosphor-icons/core não encontrado em ${coreDir}. Veja o comentário no topo deste script.`);
    process.exit(1);
}
const WEIGHTS = ['duotone', 'fill'];
const MIRRORED = new Set(['PanelRightOpen']);

const map = JSON.parse(fs.readFileSync(mapFile, 'utf8'));
const kebab = (name) =>
    name
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .replace(/([A-Z])([A-Z][a-z])/g, '$1-$2')
        .toLowerCase();

const svgBody = (phName, weight) => {
    const file = path.join(assets, weight, `${kebab(phName)}-${weight}.svg`);
    if (!fs.existsSync(file)) {
        throw new Error(`Ícone Phosphor inexistente: ${phName} (${file})`);
    }
    const svg = fs.readFileSync(file, 'utf8');
    const match = svg.match(/<svg[^>]*>([\s\S]*)<\/svg>/);
    if (!match) {
        throw new Error(`SVG inválido: ${file}`);
    }
    return match[1].trim();
};

const shapes = {};
for (const phName of [...new Set(Object.values(map))].sort()) {
    shapes[phName] = Object.fromEntries(WEIGHTS.map((w) => [w, svgBody(phName, w)]));
}

let out = `/*
 * Ícones do Paynext Design System: Phosphor duotone (fill no estado ativo).
 * ARQUIVO GERADO por scripts/generate-paynext-icons.mjs — edite paynext-icon-map.json.
 *
 * O vite.config.js aponta "lucide-vue-next" para este módulo: as telas que importam um
 * ícone Lucide recebem o equivalente Phosphor com o mesmo nome e as mesmas props
 * (size, color, class). Um nome fora do mapa cai no Lucide original via export *.
 */
import { defineComponent, h } from 'vue';

export * from 'lucide-vue-next/dist/esm/lucide-vue-next.js';

const SHAPES = ${JSON.stringify(shapes, null, 4)};

function phosphor(name, shape, mirrored = false) {
    return defineComponent({
        name,
        inheritAttrs: false,
        props: {
            size: { type: [Number, String], default: 24 },
            color: { type: String, default: 'currentColor' },
            weight: { type: String, default: 'duotone' },
            strokeWidth: { type: [Number, String], default: undefined },
            absoluteStrokeWidth: { type: Boolean, default: false },
        },
        setup(props, { attrs }) {
            return () =>
                h('svg', {
                    xmlns: 'http://www.w3.org/2000/svg',
                    viewBox: '0 0 256 256',
                    width: props.size,
                    height: props.size,
                    fill: props.color,
                    'aria-hidden': attrs['aria-label'] ? undefined : 'true',
                    ...attrs,
                    style: mirrored ? [attrs.style, { transform: 'scaleX(-1)' }] : attrs.style,
                    innerHTML: SHAPES[shape][props.weight === 'fill' ? 'fill' : 'duotone'],
                });
        },
    });
}

`;
for (const [lucide, ph] of Object.entries(map).sort(([a], [b]) => a.localeCompare(b))) {
    out += `export const ${lucide} = phosphor('${lucide}', '${ph}'${MIRRORED.has(lucide) ? ', true' : ''});\n`;
    out += `export const ${lucide}Icon = ${lucide};\n`;
}

fs.writeFileSync(outFile, out);
console.log(`paynext-icons.js: ${Object.keys(map).length} ícones, ${Object.keys(shapes).length} desenhos Phosphor.`);
