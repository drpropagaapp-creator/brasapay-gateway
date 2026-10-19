#!/usr/bin/env node
/**
 * Gera resources/js/components/icons/paynext-icons.js a partir de paynext-icon-map.json.
 *
 * O Paynext DS usa Phosphor em duotone (e fill no estado ativo). O pacote Vue oficial
 * embute os 6 pesos de cada ícone; aqui extraímos só duotone e fill dos SVGs crus de
 * @phosphor-icons/core. Para usar um ícone Lucide novo numa tela, adicione o nome ao
 * JSON (Lucide → Phosphor) e rode: node scripts/generate-paynext-icons.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const mapFile = path.join(root, 'resources/js/components/icons/paynext-icon-map.json');
const outFile = path.join(root, 'resources/js/components/icons/paynext-icons.js');
const assets = path.join(root, 'node_modules/@phosphor-icons/core/assets');
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
