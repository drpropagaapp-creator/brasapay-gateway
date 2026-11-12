/**
 * DDI (código de chamada internacional) por país, para o campo de telefone do checkout.
 * O nome exibido vem de Intl.DisplayNames no idioma do checkout (sem lista traduzida à mão).
 */

/** [ISO 3166-1 alpha-2, DDI] — Brasil primeiro; demais em ordem alfabética do ISO. */
const RAW = [
    ['BR', '55'],
    ['AD', '376'], ['AE', '971'], ['AF', '93'], ['AG', '1'], ['AI', '1'], ['AL', '355'], ['AM', '374'],
    ['AO', '244'], ['AR', '54'], ['AS', '1'], ['AT', '43'], ['AU', '61'], ['AW', '297'], ['AZ', '994'],
    ['BA', '387'], ['BB', '1'], ['BD', '880'], ['BE', '32'], ['BF', '226'], ['BG', '359'], ['BH', '973'],
    ['BI', '257'], ['BJ', '229'], ['BM', '1'], ['BN', '673'], ['BO', '591'], ['BS', '1'], ['BT', '975'],
    ['BW', '267'], ['BY', '375'], ['BZ', '501'], ['CA', '1'], ['CD', '243'], ['CF', '236'], ['CG', '242'],
    ['CH', '41'], ['CI', '225'], ['CL', '56'], ['CM', '237'], ['CN', '86'], ['CO', '57'], ['CR', '506'],
    ['CU', '53'], ['CV', '238'], ['CW', '599'], ['CY', '357'], ['CZ', '420'], ['DE', '49'], ['DJ', '253'],
    ['DK', '45'], ['DM', '1'], ['DO', '1'], ['DZ', '213'], ['EC', '593'], ['EE', '372'], ['EG', '20'],
    ['ER', '291'], ['ES', '34'], ['ET', '251'], ['FI', '358'], ['FJ', '679'], ['FO', '298'], ['FR', '33'],
    ['GA', '241'], ['GB', '44'], ['GD', '1'], ['GE', '995'], ['GF', '594'], ['GH', '233'], ['GI', '350'],
    ['GL', '299'], ['GM', '220'], ['GN', '224'], ['GP', '590'], ['GQ', '240'], ['GR', '30'], ['GT', '502'],
    ['GU', '1'], ['GW', '245'], ['GY', '592'], ['HK', '852'], ['HN', '504'], ['HR', '385'], ['HT', '509'],
    ['HU', '36'], ['ID', '62'], ['IE', '353'], ['IL', '972'], ['IN', '91'], ['IQ', '964'], ['IR', '98'],
    ['IS', '354'], ['IT', '39'], ['JM', '1'], ['JO', '962'], ['JP', '81'], ['KE', '254'], ['KG', '996'],
    ['KH', '855'], ['KM', '269'], ['KN', '1'], ['KR', '82'], ['KW', '965'], ['KY', '1'], ['KZ', '7'],
    ['LA', '856'], ['LB', '961'], ['LC', '1'], ['LI', '423'], ['LK', '94'], ['LR', '231'], ['LS', '266'],
    ['LT', '370'], ['LU', '352'], ['LV', '371'], ['LY', '218'], ['MA', '212'], ['MC', '377'], ['MD', '373'],
    ['ME', '382'], ['MG', '261'], ['MK', '389'], ['ML', '223'], ['MM', '95'], ['MN', '976'], ['MO', '853'],
    ['MQ', '596'], ['MR', '222'], ['MT', '356'], ['MU', '230'], ['MV', '960'], ['MW', '265'], ['MX', '52'],
    ['MY', '60'], ['MZ', '258'], ['NA', '264'], ['NC', '687'], ['NE', '227'], ['NG', '234'], ['NI', '505'],
    ['NL', '31'], ['NO', '47'], ['NP', '977'], ['NZ', '64'], ['OM', '968'], ['PA', '507'], ['PE', '51'],
    ['PF', '689'], ['PG', '675'], ['PH', '63'], ['PK', '92'], ['PL', '48'], ['PR', '1'], ['PS', '970'],
    ['PT', '351'], ['PY', '595'], ['QA', '974'], ['RE', '262'], ['RO', '40'], ['RS', '381'], ['RU', '7'],
    ['RW', '250'], ['SA', '966'], ['SB', '677'], ['SC', '248'], ['SD', '249'], ['SE', '46'], ['SG', '65'],
    ['SI', '386'], ['SK', '421'], ['SL', '232'], ['SM', '378'], ['SN', '221'], ['SO', '252'], ['SR', '597'],
    ['SS', '211'], ['ST', '239'], ['SV', '503'], ['SX', '1'], ['SY', '963'], ['SZ', '268'], ['TC', '1'],
    ['TD', '235'], ['TG', '228'], ['TH', '66'], ['TJ', '992'], ['TL', '670'], ['TM', '993'], ['TN', '216'],
    ['TO', '676'], ['TR', '90'], ['TT', '1'], ['TW', '886'], ['TZ', '255'], ['UA', '380'], ['UG', '256'],
    ['US', '1'], ['UY', '598'], ['UZ', '998'], ['VC', '1'], ['VE', '58'], ['VG', '1'], ['VI', '1'],
    ['VN', '84'], ['VU', '678'], ['WS', '685'], ['XK', '383'], ['YE', '967'], ['ZA', '27'], ['ZM', '260'],
    ['ZW', '263'],
];

/** País usado quando um DDI é compartilhado e só temos os dígitos (ex.: colou "+1 ..."). */
const PRIMARY_FOR_CODE = { '1': 'US', '7': 'RU', '44': 'GB', '262': 'RE', '590': 'GP', '599': 'CW' };

export const PHONE_COUNTRIES = RAW.map(([country, code]) => ({ country, code }));

const displayNamesCache = new Map();

function displayNames(locale) {
    const key = String(locale || 'pt-BR').replace('_', '-');
    if (!displayNamesCache.has(key)) {
        let dn = null;
        try {
            dn = typeof Intl !== 'undefined' && Intl.DisplayNames ? new Intl.DisplayNames([key, 'pt-BR'], { type: 'region' }) : null;
        } catch {
            dn = null;
        }
        displayNamesCache.set(key, dn);
    }
    return displayNamesCache.get(key);
}

/** Nome do país no idioma do checkout (ex.: 'pt_BR' → "Estados Unidos", 'en' → "United States"). */
export function phoneCountryLabel(country, locale) {
    const dn = displayNames(locale);
    try {
        return (dn && dn.of(country)) || country;
    } catch {
        return country;
    }
}

export function findPhoneCountry(country) {
    const iso = String(country || '').toUpperCase();
    return PHONE_COUNTRIES.find((c) => c.country === iso) || null;
}

/** Primeiro país com esse DDI, preferindo o principal quando o DDI é compartilhado. */
export function findPhoneCountryByCode(code) {
    const primary = PRIMARY_FOR_CODE[code];
    if (primary) return findPhoneCountry(primary);
    return PHONE_COUNTRIES.find((c) => c.code === code) || null;
}

/**
 * Separa DDI e número a partir de dígitos digitados/colados em formato internacional
 * ("+351 912 345 678" → { country: 'PT', code: '351', national: '912345678' }).
 */
export function splitInternationalDigits(digits) {
    const d = String(digits || '').replace(/\D/g, '');
    for (const len of [3, 2, 1]) {
        if (d.length <= len) continue;
        const match = findPhoneCountryByCode(d.slice(0, len));
        if (match) return { country: match.country, code: match.code, national: d.slice(len) };
    }
    return null;
}

/**
 * Telefone completo para o DDI escolhido.
 * Brasil: DDD + 8 ou 9 dígitos. Outros países: limites do E.164 (até 15 dígitos com o DDI),
 * sem tentar adivinhar o plano de numeração de cada país.
 */
export function isValidPhoneForCountry(code, national) {
    const cc = String(code || '').replace(/\D/g, '');
    const n = String(national || '').replace(/\D/g, '');
    if (!cc || !n) return false;
    if (cc === '55') {
        if (n.length === 11) return n[2] === '9';
        return n.length === 10 && n[2] >= '2' && n[2] <= '8';
    }
    const total = cc.length + n.length;
    return n.length >= 4 && total >= 8 && total <= 15;
}
