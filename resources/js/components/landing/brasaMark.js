/**
 * Marca BrasaPay — chama estilizada com vazio interno (fill-rule evenodd).
 * Usada no lockup da navegação, na abertura (desenho do traço) e no rodapé.
 */
export const MARK_VIEWBOX = '0 0 100 120';

export const MARK_OUTER = 'M50 3 C66 27 90 45 90 76 A40 40 0 1 1 10 76 C10 45 34 27 50 3 Z';

export const MARK_INNER = 'M50 50 C58 60 67 67 67 82 A17 17 0 1 1 33 82 C33 67 42 60 50 50 Z';

export const MARK_PATH = `${MARK_OUTER} ${MARK_INNER}`;

/** Paradas do degradê da marca (lime → esmeralda → teal). */
export const MARK_STOPS = [
    ['0%', '#5cb536'],
    ['55%', '#0a986e'],
    ['100%', '#008894'],
];
