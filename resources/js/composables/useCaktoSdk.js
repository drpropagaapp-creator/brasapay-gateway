/**
 * Cakto SDK (navegador): tokeniza o cartão e coleta o perfil antifraude exigido na cobrança.
 * O cartão nunca vai ao backend do BrasaPay: só card_token + referência antifraude + fingerprint.
 *
 * @see https://docs.cakto.com.br/sdk/visao-geral
 */
const SDK_URL = 'https://cakto-sdk.pages.dev/cakto-sdk.min.js';
const DEVICE_KEY = 'bp_device_id';

let scriptPromise = null;
let sdkInstance = null;
let sdkClientId = '';
let antifraudPromise = null;

function loadScript() {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('Cakto SDK só roda no navegador.'));
    }
    if (window.Cakto?.CaktoSDK) {
        return Promise.resolve();
    }
    if (!scriptPromise) {
        scriptPromise = new Promise((resolve, reject) => {
            const el = document.createElement('script');
            el.src = SDK_URL;
            el.async = true;
            el.onload = () => (window.Cakto?.CaktoSDK ? resolve() : reject(new Error('Cakto SDK indisponível.')));
            el.onerror = () => {
                scriptPromise = null;
                reject(new Error('Não foi possível carregar o pagamento por cartão. Recarregue a página.'));
            };
            document.head.appendChild(el);
        });
    }
    return scriptPromise;
}

async function getSdk(clientId) {
    const id = String(clientId || '').trim();
    if (!id) {
        throw new Error('Pagamento por cartão não está configurado.');
    }
    await loadScript();
    if (!sdkInstance || sdkClientId !== id) {
        // Uma única instância por página: o perfil antifraude depende disso.
        sdkInstance = new window.Cakto.CaktoSDK({ client_id: id });
        sdkClientId = id;
        antifraudPromise = null;
    }
    return sdkInstance;
}

/** Inicia a coleta antifraude cedo (ao escolher cartão): quanto antes, mais sinais. */
export async function initCaktoAntifraud(clientId) {
    const sdk = await getSdk(clientId);
    if (!antifraudPromise) {
        antifraudPromise = sdk.initAntifraud().catch((err) => {
            antifraudPromise = null;
            throw err;
        });
    }
    return antifraudPromise;
}

/** Identificador estável do dispositivo (exigido pela Cakto em customer.fingerprint). */
export function caktoDeviceFingerprint() {
    const fresh = () => {
        const rnd = typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        return `bp_${rnd}`;
    };
    try {
        let id = window.localStorage.getItem(DEVICE_KEY);
        if (!id) {
            id = fresh();
            window.localStorage.setItem(DEVICE_KEY, id);
        }
        return id;
    } catch {
        return fresh();
    }
}

/**
 * Gera o payment_token (JSON) para o backend.
 * @param {{ clientId: string, holderName: string, cardNumber: string, cvv: string, expMonth: string, expYear: string, installments: number }} p
 */
export async function requestCaktoPaymentToken(p) {
    const sdk = await getSdk(p.clientId);
    await initCaktoAntifraud(p.clientId);

    const digits = String(p.cardNumber || '').replace(/\D/g, '');
    const year = String(p.expYear || '').replace(/\D/g, '');
    let result;
    try {
        result = await sdk.createToken({
            holderName: String(p.holderName || '').trim() || undefined,
            cardNumber: digits,
            cvv: String(p.cvv || '').replace(/\D/g, ''),
            expMonth: String(p.expMonth || '').padStart(2, '0'),
            // O SDK prefixa o século: precisa receber o ano com 2 dígitos.
            expYear: year.slice(-2),
        });
    } catch (err) {
        const status = err?.response?.status;
        if (status === 422) {
            throw new Error('Número do cartão inválido. Confira e tente de novo.');
        }
        throw new Error('Não foi possível validar o cartão. Confira os dados e tente de novo.');
    }
    const cardToken = result?.cardToken || result?.token || result?.id || '';
    if (!cardToken) {
        throw new Error('Não foi possível validar o cartão. Tente de novo.');
    }

    await sdk.completeAntifraudProfile();
    const reference = sdk.getAntifraudReference();
    // Cada cobrança usa um perfil novo: se esta tentativa for recusada, a próxima reinicia a coleta.
    antifraudPromise = null;
    try {
        sdk.cleanupAntifraud();
    } catch {
        // sem efeito no pagamento
    }
    if (!reference) {
        throw new Error('Verificação de segurança não concluída. Recarregue a página e tente de novo.');
    }

    const last4 = digits.slice(-4);
    return {
        payment_token: JSON.stringify({
            card_token: cardToken,
            antifraud_reference: reference,
            fingerprint: caktoDeviceFingerprint(),
            installments: Math.max(1, Number(p.installments) || 1),
        }),
        card_mask: last4 ? `****${last4}` : '',
    };
}
