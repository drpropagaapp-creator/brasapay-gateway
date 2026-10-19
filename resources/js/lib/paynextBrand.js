/**
 * Cores de marca do Paynext DS para as telas do comprador e do aluno (checkout e área de
 * membros). As cores configuradas pelo vendedor continuam salvas, mas não são aplicadas:
 * o visual é sempre o do Design System. Em hex (não var()) porque alguns componentes
 * anexam transparência ao código (ex.: `primary + '18'`).
 */
export const PN_PRIMARY = '#E1FB4D';
export const PN_INK = '#151718';
export const PN_CANVAS_DARK = '#0A0C0D';

/** Paleta de séries dos gráficos (tokens --chart-1..4 do DS), por tema. */
export function pnChartPalette(isDark) {
    return isDark ? ['#E1FB4D', '#7CC4FF', '#B8A6FF', '#FFC857'] : ['#E1FB4D', '#3F73B3', '#7B61D9', '#B97800'];
}
