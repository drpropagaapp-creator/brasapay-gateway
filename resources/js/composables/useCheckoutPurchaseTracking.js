import { ensureFbcFromFbclid, getAttributionPayload } from '@/lib/metaTracking/attribution.js';
import { mirrorMetaAddPaymentInfoToServer, mirrorMetaPurchaseToServer } from '@/lib/metaTracking/serverMirror.js';
import { getMetaEntries, buildCheckoutEventPayload, buildPurchaseEventPayload, trackMetaEvent, initMetaPixels, buildAdvancedMatching } from '@/lib/metaTracking/browserPixel.js';
import { sendPurchasePixelAck } from '@/composables/usePurchasePixelAck.js';
import { fireMetaPurchaseReliable } from '@/composables/useMetaCheckoutTracking.js';

function safeStorageGet(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function safeStorageSet(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (_) {}
}

function purchaseDedupeKey(orderId) {
    const oid = orderId ? String(orderId) : '';
    return oid ? `px:purchase_sent:${oid}` : '';
}

/**
 * Dispara Purchase (browser + mirror CAPI + ack) após aprovação da compra.
 *
 * @param {object} options
 * @param {string|number} options.orderId
 * @param {string} [options.checkoutSessionToken]
 * @param {string} [options.token] PIX/boleto display token
 * @param {'approved'|'pix'|'boleto'} [options.triggerType]
 * @param {number} [options.value]
 * @param {string} [options.currency]
 * @param {string} [options.contentKey]
 * @param {Array<{id: string, quantity?: number, item_price?: number}>|null} [options.contents]
 * @param {string} [options.contentName]
 * @param {object|null} [options.customer]
 * @param {object} [options.pixels]
 * @param {{ firePurchaseReliable?: Function }|null} [options.conversionPixelsApi]
 * @param {number} [options.settleDelayMs]
 * @param {boolean} [options.beaconOnly] só ack + CAPI mirror (pagehide)
 */
export async function trackCheckoutPurchase({
    orderId,
    checkoutSessionToken = '',
    token = '',
    triggerType = 'approved',
    value = 0,
    currency = 'BRL',
    contentKey = '',
    contents = null,
    contentName = '',
    customer = null,
    pixels = {},
    conversionPixelsApi = null,
    settleDelayMs = 350,
    beaconOnly = false,
}) {
    const oid = orderId !== null && orderId !== undefined ? String(orderId) : '';
    if (!oid) {
        return { ok: false, reason: 'missing_order_id' };
    }

    const dedupeKey = purchaseDedupeKey(oid);
    if (!beaconOnly && dedupeKey && safeStorageGet(dedupeKey) === '1') {
        return { ok: false, reason: 'already_sent' };
    }

    ensureFbcFromFbclid();
    const attribution = getAttributionPayload();
    const eventSourceUrl = typeof window !== 'undefined' ? window.location.href : undefined;

    sendPurchasePixelAck({
        orderId: oid,
        checkoutSessionToken,
        token,
        triggerType,
    });

    const purchaseLines = buildPurchaseEventPayload(value, currency, oid, contentKey, contents);

    mirrorMetaPurchaseToServer({
        checkoutSessionToken,
        orderId: oid,
        eventId: `order:${oid}`,
        ...attribution,
        value,
        currency,
        eventSourceUrl,
        contentIds: purchaseLines.content_ids,
        contentName: contentName || undefined,
    });

    if (beaconOnly) {
        return { ok: true, reason: 'beacon_only' };
    }

    let tracked = false;

    if (conversionPixelsApi?.firePurchaseReliable) {
        await conversionPixelsApi.firePurchaseReliable(
            value,
            currency,
            oid,
            false,
            triggerType,
            settleDelayMs,
            contentKey,
            customer,
            contents
        );
        tracked = true;
    } else if (getMetaEntries(pixels).length > 0) {
        await fireMetaPurchaseReliable({
            pixels,
            value,
            currency,
            orderId: oid,
            contentKey,
            contents,
            customer,
            triggerType,
            settleDelayMs,
        });
        tracked = true;
    }

    if (tracked && dedupeKey) {
        safeStorageSet(dedupeKey, '1');
    }

    return { ok: true, reason: tracked ? 'browser_and_capi' : 'capi_only' };
}

/**
 * Beacon rápido ao sair da página (PIX aprovado, aba fechando).
 */
export function trackCheckoutPurchaseBeacon(options) {
    return trackCheckoutPurchase({ ...options, beaconOnly: true });
}

function paymentInfoDedupeKey(orderId) {
    const oid = orderId ? String(orderId) : '';
    return oid ? `px:add_payment_info:${oid}` : '';
}

/**
 * AddPaymentInfo quando o PIX, boleto ou checkout de pagamento é criado.
 * Purchase continua só depois da confirmação.
 */
export async function trackCheckoutAddPaymentInfo({
    orderId,
    checkoutSessionToken = '',
    value = 0,
    currency = 'BRL',
    contentKey = '',
    contents = null,
    contentName = '',
    customer = null,
    pixels = {},
}) {
    const oid = orderId !== null && orderId !== undefined ? String(orderId) : '';
    const token = String(checkoutSessionToken || '').trim();
    if (!oid || !token) {
        return { ok: false, reason: 'missing_ids' };
    }

    const dedupeKey = paymentInfoDedupeKey(oid);
    if (dedupeKey && safeStorageGet(dedupeKey) === '1') {
        return { ok: false, reason: 'already_sent' };
    }

    ensureFbcFromFbclid();
    const attribution = getAttributionPayload();
    const eventId = `pay:${oid}`;
    const payload = contents
        ? buildPurchaseEventPayload(value, currency, oid, contentKey, contents)
        : buildCheckoutEventPayload(value, currency, contentKey);

    mirrorMetaAddPaymentInfoToServer({
        checkoutSessionToken: token,
        orderId: oid,
        eventId,
        ...attribution,
        value: payload.value,
        currency: payload.currency,
        contentIds: payload.content_ids,
        contentName: contentName || undefined,
    });

    const metaEntries = getMetaEntries(pixels);
    if (metaEntries.length) {
        const matching = customer ? buildAdvancedMatching(customer) : null;
        await initMetaPixels(metaEntries, matching && Object.keys(matching).length ? matching : null);
        trackMetaEvent('AddPaymentInfo', payload, eventId);
    }

    if (dedupeKey) {
        safeStorageSet(dedupeKey, '1');
    }

    return { ok: true };
}
