import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const fs = require('fs');
const path = require('path');

/**
 * OWA.l() and OWA.ln(): the reporting JavaScript's strings, keyed by their
 * English. With no OWA.config.strings (English, or nothing translated) the
 * English is the answer; with it, the translation, and a counted message takes
 * the form the locale's plural rule picks.
 */

afterEach(() => {
    delete OWA.config.strings;
    delete OWA.config.plural_rule;
});

describe('without translations', () => {

    test('a string is its English', () => {
        expect(OWA.l('Next page')).toBe('Next page');
        expect(OWA.l('Open', 'goal status')).toBe('Open');
    });

    test('a counted message is the English singular for one and the plural otherwise', () => {
        expect(OWA.ln('%d row', '%d rows', 1)).toBe('%d row');
        expect(OWA.ln('%d row', '%d rows', 0)).toBe('%d rows');
        expect(OWA.ln('%d row', '%d rows', 2)).toBe('%d rows');
        expect(OWA.ln('%d row', '%d rows', -1)).toBe('%d row');
    });
});

describe('with translations', () => {

    beforeEach(() => {
        OWA.config.strings = {
            'Next page': 'Page suivante',
            'goal status\x04Open': 'Ouvert',
            '%d row': ['%d ligne', '%d lignes'],
            '%d file': ['%d plik', '%d pliki', '%d plików'],
            '%d gap': ['%d trou'],
        };
    });

    test('a string is found by its English, and a context by context and English', () => {
        expect(OWA.l('Next page')).toBe('Page suivante');
        expect(OWA.l('Open', 'goal status')).toBe('Ouvert');
        expect(OWA.l('Open')).toBe('Open');
        expect(OWA.l('Not in the map')).toBe('Not in the map');
    });

    test('a counted message takes the form its rule picks', () => {
        OWA.config.plural_rule = 'french';

        expect(OWA.ln('%d row', '%d rows', 0)).toBe('%d ligne');
        expect(OWA.ln('%d row', '%d rows', 1)).toBe('%d ligne');
        expect(OWA.ln('%d row', '%d rows', 3)).toBe('%d lignes');

        OWA.config.plural_rule = 'polish';

        expect([1, 2, 5, 22, 25].map((n) => OWA.ln('%d file', '%d files', n)))
            .toEqual(['%d plik', '%d pliki', '%d plików', '%d pliki', '%d plików']);
    });

    test('a missing form, or a key held as one string, falls back to the English', () => {
        OWA.config.plural_rule = 'english';

        expect(OWA.ln('%d gap', '%d gaps', 1)).toBe('%d trou');
        expect(OWA.ln('%d gap', '%d gaps', 2)).toBe('%d gaps');
        expect(OWA.ln('Next page', 'Next pages', 2)).toBe('Next pages');
        expect(OWA.l('%d row')).toBe('%d row');
    });

    test('translate() answers as l() does, for a string the code was given', () => {
        expect(OWA.translate('Next page')).toBe('Page suivante');
        expect(OWA.translatePlural('%d row', '%d rows', 2)).toBe('%d lignes');
    });

    test('a key the object inherits is not a translation', () => {
        expect(OWA.l('constructor')).toBe('constructor');
        expect(OWA.l('toString')).toBe('toString');
    });
});

test('placeholders are filled as PHP sprintf fills them, including numbered ones', () => {
    OWA.config.strings = { '%1$s: %2$d user': ['%2$d utilisateur en %1$s', '%2$d utilisateurs en %1$s'] };
    OWA.config.plural_rule = 'french';

    expect(OWA.util.sprintf(OWA.ln('%1$s: %2$d user', '%1$s: %2$d users', 4), 'France', 4))
        .toBe('4 utilisateurs en France');
    expect(OWA.util.sprintf(OWA.l('%s by %s over time'), 'Visits', 'Browser')).toBe('Visits by Browser over time');
});

test('each plural rule picks the form Strings::pluralIndex() picks', () => {
    const table = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/plural-rules.json'), 'utf8'));

    for (const [rule, counts] of Object.entries(table)) {
        if (rule.startsWith('_')) {
            continue;
        }
        for (const [n, form] of Object.entries(counts)) {
            expect([rule, n, OWA.pluralIndex(Number(n), rule)]).toEqual([rule, n, form]);
        }
    }
});
