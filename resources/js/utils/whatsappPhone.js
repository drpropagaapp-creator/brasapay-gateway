export function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '');
}

/**
 * Máscara BR para DDD local; com "+" / "00" ou DDI internacional mantém E.164.
 */
export function maskWhatsappPhone(value) {
    const raw = String(value || '');
    const trimmed = raw.trimStart();
    let d = digitsOnly(raw);
    if (d.startsWith('00')) {
        d = d.slice(2);
    }

    const explicitIntl = trimmed.startsWith('+') || trimmed.startsWith('00');

    if (explicitIntl) {
        d = d.slice(0, 15);
        return d ? `+${d}` : '+';
    }

    // Internacional sem "+" (ex.: 351912345678) — não forçar máscara BR.
    if (d.length >= 12 && !(d.startsWith('55') && d.length <= 13)) {
        return d.slice(0, 15);
    }

    if (d.startsWith('55') && d.length >= 12) {
        d = d.slice(2);
    }
    d = d.slice(0, 11);
    if (d.length <= 2) return d;
    if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

/** Espelha a heurística de App\Support\WhatsappPhoneNormalizer. */
export function isValidWhatsappPhoneInput(value) {
    const raw = String(value || '').trim();
    if (!raw) return false;

    let d = digitsOnly(raw);
    const explicitIntl = raw.startsWith('+') || /^\s*00\d/.test(raw);
    if (d.startsWith('00')) {
        d = d.slice(2);
    }

    if (d.length < 8 || d.length > 15) return false;
    if (explicitIntl) return true;
    if (d.startsWith('55') && d.length >= 12 && d.length <= 13) return true;
    if (d.length >= 10 && d.length <= 11) return true;
    if (d.length >= 12 && d.length <= 15) return true;

    return false;
}
