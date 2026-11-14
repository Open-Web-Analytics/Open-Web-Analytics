import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

/**
 * Dates and numbers in the reporting UI are written in the install's locale:
 * OWA.config.locale, the language tag config_dom.php sends (OWA_LOCALE). With
 * none, Intl's default -- the browser's.
 */

afterEach(() => {
    delete OWA.config.locale;
});

test('the locale is the tag the page sent, canonical, or none', () => {
    expect(OWA.locale()).toBeUndefined();

    OWA.config.locale = 'pt-br';
    expect(OWA.locale()).toBe('pt-BR');

    OWA.config.locale = 'not a tag';
    expect(OWA.locale()).toBeUndefined();

    OWA.config.locale = '';
    expect(OWA.locale()).toBeUndefined();
});

test('a number is grouped, and a percentage written, as the locale writes them', () => {
    expect(OWA.util.formatNumber(1234567.5)).toBe('1,234,567.5');
    expect(OWA.util.formatPercent(0.125, 1)).toBe('12.5%');
    expect(OWA.util.formatPercent(0.5)).toBe('50%');

    OWA.config.locale = 'de-DE';

    expect(OWA.util.formatNumber(1234567.5)).toBe('1.234.567,5');
    expect(OWA.util.formatPercent(0.125, 1)).toBe('12,5 %');
});

test('a date is written as the locale writes it', () => {
    const d = Date.UTC(2026, 10, 14);

    expect(OWA.util.formatDate(d, { month: 'short', day: 'numeric', timeZone: 'UTC' })).toBe('Nov 14');

    OWA.config.locale = 'fr-FR';

    expect(OWA.util.formatDate(d, { month: 'short', day: 'numeric', timeZone: 'UTC' })).toBe('14 nov.');
});
