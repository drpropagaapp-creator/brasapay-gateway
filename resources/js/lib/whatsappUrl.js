import { splitInternationalDigits } from '@/lib/phoneCountries.js';

/**
 * Telefone em dígitos E.164 (sem "+"), ou null.
 *
 * - Com "+" ou "00" na frente: já é internacional, mantém.
 * - 55 + 10/11 dígitos: Brasil com DDI.
 * - 11 dígitos com 3º dígito 9: celular brasileiro sem DDI → prefixa 55.
 *   Com outro 3º dígito não é celular brasileiro: é internacional gravado sem "+"
 *   (ex.: EUA "1 857 555 1233"; o checkout gravava DDI + número sem "+").
 * - 10 dígitos com 3º dígito 2–8: fixo brasileiro sem DDI → prefixa 55.
 * - 12 a 15 dígitos: internacional com DDI.
 *
 * @param {string|null|undefined} phone
 * @returns {string|null}
 */
export function normalizeCustomerPhone(phone) {
    const raw = String(phone ?? '').trim();
    if (!raw) return null;
    let digits = raw.replace(/\D/g, '');
    const explicitIntl = raw.startsWith('+') || /^00\d/.test(raw);
    if (explicitIntl && digits.startsWith('00')) digits = digits.slice(2);
    const len = digits.length;
    if (len < 8 || len > 15) return null;
    if (explicitIntl) return digits;
    if (digits.startsWith('55') && (len === 12 || len === 13)) return digits;
    if (len === 11) return digits[2] === '9' ? `55${digits}` : digits;
    if (len === 10) return digits[2] >= '2' && digits[2] <= '8' ? `55${digits}` : digits;
    if (len >= 12) return digits;
    return null;
}

/**
 * Telefone para exibir ao seller: "+55 (11) 99999-8888", "+1 857 555 1233", "+351 912 345 678".
 *
 * @param {string|null|undefined} phone
 * @returns {string}
 */
export function formatCustomerPhone(phone) {
    const e164 = normalizeCustomerPhone(phone);
    if (!e164) return String(phone ?? '').trim();
    const split = splitInternationalDigits(e164);
    if (!split) return `+${e164}`;
    const n = split.national;
    if (split.code === '55' && (n.length === 10 || n.length === 11)) {
        const head = n.length === 11 ? n.slice(2, 7) : n.slice(2, 6);
        return `+55 (${n.slice(0, 2)}) ${head}-${n.slice(head.length + 2)}`;
    }
    return `+${split.code} ${n.replace(/(\d{3})(?=\d{3,})/g, '$1 ')}`;
}

/**
 * Monta link wa.me a partir do telefone do checkout (Brasil ou internacional).
 *
 * @param {string|null|undefined} phone
 * @returns {string|null}
 */
export function buildWhatsAppUrl(phone) {
    const normalized = normalizeCustomerPhone(phone);
    if (!normalized || normalized.length < 10) {
        return null;
    }

    return `https://wa.me/${normalized}`;
}

/**
 * Telefone do pedido (checkout) com fallback na sessão.
 *
 * @param {{ phone?: string, checkout_session?: { phone?: string } }|null|undefined} order
 * @returns {string}
 */
export function orderCustomerPhone(order) {
    if (!order) {
        return '';
    }
    const fromOrder = String(order.phone ?? '').trim();
    if (fromOrder) {
        return fromOrder;
    }

    return String(order.checkout_session?.phone ?? '').trim();
}
