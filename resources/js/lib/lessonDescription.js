import { formatLessonDescription } from '@/lib/utils';
import { sanitizeHtmlAllowlist } from '@/lib/sanitizeHtml';

const HTML_TAG_RE = /<\/?(?:p|br|div|span|strong|b|em|i|u|s|a|ul|ol|li|h[1-6]|blockquote|font|strike)\b/i;
const FORMAT_TAG_RE = /<(a|strong|b|em|i|u|s|ul|ol|li|h[1-6]|blockquote|font|strike)\b/i;
const ALLOWED_STYLE_PROPS = new Set(['color', 'font-size', 'text-align', 'font-weight', 'font-style', 'text-decoration']);

export function looksLikeLessonHtml(text) {
    if (text == null || typeof text !== 'string' || text === '') return false;
    return HTML_TAG_RE.test(text);
}

export function isEffectivelyPlainLessonHtml(html) {
    if (!html) return true;
    if (FORMAT_TAG_RE.test(html)) return false;
    if (/\sstyle\s*=/i.test(html)) return false;
    return true;
}

function filterStyleAttribute(style) {
    const kept = [];
    String(style || '')
        .split(';')
        .forEach((part) => {
            const trimmed = part.trim();
            if (!trimmed || !trimmed.includes(':')) return;
            const colon = trimmed.indexOf(':');
            const prop = trimmed.slice(0, colon).trim().toLowerCase();
            let value = trimmed.slice(colon + 1).trim();
            if (!ALLOWED_STYLE_PROPS.has(prop) || !value) return;
            const lower = value.toLowerCase();
            if (/url\s*\(|expression\s*\(|javascript:|@import|behavior/i.test(lower)) return;
            if (prop === 'text-align' && !/^(left|right|center|justify|start|end)$/i.test(value)) return;
            if (prop === 'font-size' && !/^(xx-small|x-small|small|medium|large|x-large|xx-large|\d+(\.\d+)?(px|em|rem|%))$/i.test(value)) return;
            const px = /^(\d+(\.\d+)?)px$/i.exec(value);
            if (px && Number(px[1]) > 32) value = '32px';
            if (prop === 'color' && !/^(#[0-9a-f]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-z]+)$/i.test(value)) return;
            kept.push(`${prop}: ${value}`);
        });
    return kept.join('; ');
}

function restrictLessonStyles(html) {
    return String(html || '').replace(/\sstyle\s*=\s*(["'])(.*?)\1/gi, (_, quote, value) => {
        const filtered = filterStyleAttribute(value);
        return filtered ? ` style=${quote}${filtered}${quote}` : '';
    });
}

export function sanitizeLessonHtml(html) {
    if (html == null) return '';
    const raw = String(html);
    if (!raw) return '';
    if (!looksLikeLessonHtml(raw) && !/<[a-z][\s\S]*?>/i.test(raw)) return raw;

    const cleaned = sanitizeHtmlAllowlist(raw, {
        ALLOWED_TAGS: [
            'a', 'b', 'strong', 'i', 'em', 'u', 's', 'p', 'br',
            'ul', 'ol', 'li', 'blockquote', 'span', 'div',
            'h1', 'h2', 'h3', 'h4',
        ],
        ALLOWED_ATTR: ['href', 'target', 'rel', 'title', 'style'],
        FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'img', 'table', 'form', 'input'],
    });

    return restrictLessonStyles(cleaned);
}

/**
 * Serializa o contenteditable: texto puro permanece texto puro;
 * formatação vira HTML sanitizado.
 */
export function serializeLessonEditorContent(html, innerText) {
    const source = html == null ? '' : String(html);
    if (isEffectivelyPlainLessonHtml(source)) {
        return String(innerText || '')
            .replace(/\u00a0/g, ' ')
            .replace(/\r\n/g, '\n')
            .replace(/\r/g, '\n');
    }
    return sanitizeLessonHtml(source);
}

/**
 * Exibe descrição da aula para o aluno.
 * Texto antigo (sem HTML): quebras de linha + URLs clicáveis.
 * HTML novo: sanitizado, sem escapar tags.
 */
export function renderLessonDescription(text) {
    if (text == null || typeof text !== 'string' || text === '') return '';
    if (looksLikeLessonHtml(text)) {
        return sanitizeLessonHtml(text);
    }
    return formatLessonDescription(text);
}
