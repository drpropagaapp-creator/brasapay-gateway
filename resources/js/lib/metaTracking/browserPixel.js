const META_FBEvents_SRC = 'https://connect.facebook.net/en_US/fbevents.js';
const META_FBEvents_SCRIPT_ID = 'getfy-meta-fbevents';

let metaLibLoadPromise = null;
let metaLibReady = false;
const metaInitedPixelIds = new Set();

function isValidPixelId(id) {
    if (typeof id !== 'string' || id.length > 64) return false;
    return /^[a-zA-Z0-9_-]+$/.test(id);
}

export function normalizeMetaPixelId(raw) {
    const digits = String(raw ?? '').replace(/\D/g, '');
    if (digits.length < 5 || digits.length > 20) return '';
    return digits;
}

export function getMetaEntries(pixels) {
    const m = pixels?.meta;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries
            .map((e) => {
                if (!e || typeof e !== 'object') return null;
                const pixelId = normalizeMetaPixelId(e.pixel_id);
                if (!pixelId || !isValidPixelId(pixelId)) return null;
                return { ...e, pixel_id: pixelId };
            })
            .filter(Boolean);
    }
    if (m.pixel_id) {
        const pixelId = normalizeMetaPixelId(m.pixel_id);
        if (pixelId && isValidPixelId(pixelId)) {
            return [{ ...m, pixel_id: pixelId }];
        }
    }
    return [];
}

function bootstrapMetaQueue() {
    if (window.fbq) return;
    const n = (window.fbq = function (...args) {
        if (n.callMethod) {
            n.callMethod(...args);
        } else {
            n.queue.push(args);
        }
    });
    if (!window._fbq) window._fbq = n;
    n.push = n;
    n.loaded = true;
    n.version = '2.0';
    n.queue = n.queue || [];
}

function ensureMetaLibLoaded() {
    bootstrapMetaQueue();
    if (metaLibReady) {
        return Promise.resolve();
    }
    if (metaLibLoadPromise) {
        return metaLibLoadPromise;
    }

    metaLibLoadPromise = new Promise((resolve, reject) => {
        let script = document.getElementById(META_FBEvents_SCRIPT_ID);
        const finish = () => {
            metaLibReady = true;
            resolve();
        };
        const fail = () => reject(new Error('meta_fbevents_load_failed'));

        if (!script) {
            script = document.createElement('script');
            script.id = META_FBEvents_SCRIPT_ID;
            script.async = true;
            script.src = META_FBEvents_SRC;
            script.onload = finish;
            script.onerror = fail;
            document.head.appendChild(script);
            return;
        }

        if (script.getAttribute('data-loaded') === '1') {
            finish();
            return;
        }

        script.addEventListener(
            'load',
            () => {
                script.setAttribute('data-loaded', '1');
                finish();
            },
            { once: true }
        );
        script.addEventListener('error', fail, { once: true });
    }).catch((err) => {
        metaLibLoadPromise = null;
        throw err;
    });

    return metaLibLoadPromise;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForMetaPixelInit(metaEntries, maxMs = 4200) {
    const ids = metaEntries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return false;
    const startedAt = Date.now();
    while (Date.now() - startedAt < maxMs) {
        if (typeof window.fbq === 'function' && ids.every((id) => metaInitedPixelIds.has(id))) return true;
        await sleep(60);
    }
    return typeof window.fbq === 'function' && ids.every((id) => metaInitedPixelIds.has(id));
}

/**
 * E.164 (só dígitos, sem "+") para Advanced Matching.
 * Incompleto ou tamanho inválido para o país → '' (não enviar ph).
 * Alinhado com App\Support\MetaPhoneNormalizer.
 */
export function normalizeMetaPhone(phone) {
    const trimmed = String(phone ?? '').trim();
    if (!trimmed) return '';

    const explicitIntl = trimmed.startsWith('+') || /^\s*00\d/.test(trimmed);
    let digits = trimmed.replace(/\D/g, '');
    if (digits.startsWith('00')) {
        digits = digits.slice(2);
    }
    if (!digits) return '';

    const len = digits.length;
    const second = digits[1] || '';
    const third = digits[2] || '';

    if (digits.startsWith('55') && (len === 12 || len === 13)) {
        return digits;
    }

    if (explicitIntl) {
        if (digits.startsWith('55')) return '';
        if (len >= 10 && len <= 15) return digits;
        return '';
    }

    if (len === 11 && !digits.startsWith('55') && third === '9') {
        return `55${digits}`;
    }

    if (len === 10 && !digits.startsWith('55') && third >= '2' && third <= '8') {
        return `55${digits}`;
    }

    if (len === 11 && digits.startsWith('1') && second >= '2' && second <= '9') {
        return digits;
    }

    if (len >= 12 && len <= 15 && !digits.startsWith('55')) {
        return digits;
    }

    return '';
}

function normalizeMetaName(name) {
    return String(name ?? '')
        .trim()
        .toLowerCase()
        .replace(/[0-9!"#$%&'()*+,\-./:;<=>?@[\\\]^_`{|}~]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Dados em texto para o pixel hashear (mesmo formato normalizado da CAPI).
 */
export function buildAdvancedMatching(customer = {}) {
    const data = {};
    const email = String(customer.email ?? '').trim().toLowerCase();
    const tenantId = Number(customer.tenantId ?? customer.tenant_id ?? 0);
    if (email) {
        data.em = email;
        if (Number.isFinite(tenantId) && tenantId > 0) {
            data.external_id = `${tenantId}:${email}`;
        }
    }
    const phone = normalizeMetaPhone(customer.phone);
    if (phone) data.ph = phone;
    const firstName = normalizeMetaName(customer.firstName);
    const lastName = normalizeMetaName(customer.lastName);
    if (firstName) data.fn = firstName;
    if (lastName) data.ln = lastName;
    return data;
}

export function splitCustomerName(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    return {
        firstName: parts[0] || '',
        lastName: parts.slice(1).join(' '),
    };
}

/**
 * Load fbevents.js and init all Meta pixel IDs.
 * userData liga advanced matching quando e-mail ou telefone já existem.
 */
export async function initMetaPixels(metaEntries, userData = null) {
    const ids = metaEntries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return false;

    await ensureMetaLibLoaded().catch(() => {});

    if (typeof window.fbq !== 'function') {
        return false;
    }

    const matching = userData && typeof userData === 'object' && Object.keys(userData).length ? userData : null;
    ids.forEach((id) => {
        if (!metaInitedPixelIds.has(id) || matching) {
            window.fbq('init', id, matching || {});
            metaInitedPixelIds.add(id);
        }
    });

    await waitForMetaPixelInit(metaEntries, 4200);

    return typeof window.fbq === 'function';
}

export function trackMetaEvent(eventName, payload = {}, eventId = undefined) {
    if (typeof window.fbq !== 'function') return false;
    const options = eventId ? { eventID: eventId } : undefined;
    window.fbq('track', eventName, payload, options);
    return true;
}

export function trackPageView(eventId = undefined) {
    if (typeof window.fbq !== 'function') return false;
    const options = eventId ? { eventID: eventId } : undefined;
    window.fbq('track', 'PageView', {}, options);
    return true;
}

export function buildCheckoutEventPayload(value, currency, contentKey) {
    const num = Number(value);
    const normalizedCurrency = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const key = (contentKey || '').trim();

    return {
        value: Number.isFinite(num) ? num : 0,
        currency: normalizedCurrency,
        content_type: 'product',
        num_items: 1,
        content_ids: key ? [key] : [],
        contents: key ? [{ id: key, quantity: 1, item_price: Number.isFinite(num) ? num : 0 }] : [],
    };
}

export function normalizeMetaContents(contents, fallbackKey = '', fallbackPrice = 0) {
    const rows = Array.isArray(contents) ? contents : [];
    const normalized = rows
        .map((row) => {
            const id = String(row?.id ?? '').trim();
            if (!id) return null;
            const quantity = Number(row.quantity);
            const itemPrice = Number(row.item_price);
            return {
                id,
                quantity: Number.isFinite(quantity) && quantity > 0 ? quantity : 1,
                item_price: Number.isFinite(itemPrice) ? itemPrice : 0,
            };
        })
        .filter(Boolean);

    if (normalized.length) return normalized;

    const key = String(fallbackKey || '').trim();
    if (!key) return [];
    const price = Number(fallbackPrice);
    return [{ id: key, quantity: 1, item_price: Number.isFinite(price) ? price : 0 }];
}

export function buildPurchaseEventPayload(value, currency, orderId, contentKey = '', contents = null) {
    const num = Number(value);
    const normalizedCurrency = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const oid = orderId ? String(orderId) : '';
    const price = Number.isFinite(num) ? num : 0;
    const lines = normalizeMetaContents(contents, contentKey, price);

    return {
        value: price,
        currency: normalizedCurrency,
        content_type: 'product',
        num_items: lines.length || 1,
        content_ids: lines.map((line) => line.id),
        contents: lines,
        ...(oid ? { order_id: oid } : {}),
    };
}

export { getMetaEntries as extractMetaEntries, metaInitedPixelIds };
