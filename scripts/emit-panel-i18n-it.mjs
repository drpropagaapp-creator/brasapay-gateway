import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const en = JSON.parse(fs.readFileSync(path.join(root, 'storage/app/panel_en.json'), 'utf8'));
const it = JSON.parse(fs.readFileSync(path.join(root, 'scripts/panel_i18n_it.json'), 'utf8'));

const missing = Object.keys(en).filter((k) => !Object.prototype.hasOwnProperty.call(it, k));
const extra = Object.keys(it).filter((k) => !Object.prototype.hasOwnProperty.call(en, k));
if (missing.length || extra.length) {
    console.error(JSON.stringify({ missing, extra }, null, 2));
    process.exit(1);
}

function phpString(value) {
    return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

const lines = ['<?php', '', 'return ['];
for (const [key, value] of Object.entries(it)) {
    lines.push(`    ${phpString(key)} => ${phpString(value)},`);
}
lines.push('];', '');
fs.writeFileSync(path.join(root, 'config/panel_i18n_it.php'), lines.join('\n'));
console.log(`Wrote ${Object.keys(it).length} Italian panel keys.`);
