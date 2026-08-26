/**
 * The Play link on a domstream recording, rendered by a NAMED formatter.
 *
 * WHY THE CELL CARRIES DATA AND NOT MARKUP
 *
 * The report used to build this anchor itself, in a template, out of a
 * base64-encoded parameter blob, a URL and two viewport numbers. Moving the
 * list into the standard grid meant the anchor had to come from somewhere the
 * grid understands, and the grid understands two things: a value, and the name
 * of a formatter for it.
 *
 * Naming the formatter is what keeps it that way. The cell holds
 * {overlay, url, width, height} -- data -- and this function is the single
 * place that turns it into HTML, which is also the single place it can be
 * escaped. A report that assembled the anchor itself would be handing the grid
 * markup, and the grid has no way to tell markup it built from markup it was
 * given.
 *
 * WHAT THE HREF IS
 *
 * The recorded page, with the player's parameters on the FRAGMENT. That is how
 * the overlay reaches the tracker running on that page; the fragment never
 * leaves the browser, which is also why the payload can be a blob rather than
 * query parameters.
 */
const path = require('path');
const fs = require('fs');

/**
 * The formatter, lifted out of the explorer so this cannot drift from what
 * ships. Loading the bundle to test one pure function would drag in jQuery,
 * flot and jqgrid, none of which it needs.
 */
function loadFormatter() {
    const src = fs.readFileSync(
        path.join(__dirname, '../../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js'),
        'utf8'
    );

    const start = src.indexOf('domstreamPlayer : function');
    expect(start).toBeGreaterThan(-1);

    const decl = src.slice(src.indexOf('function', start));
    let depth = 0, end = -1;
    for (let i = decl.indexOf('{'); i < decl.length; i++) {
        if (decl[i] === '{') depth++;
        else if (decl[i] === '}' && --depth === 0) { end = i + 1; break; }
    }
    expect(end).toBeGreaterThan(0);

    // eslint-disable-next-line no-new-func
    return new Function('return (' + decl.slice(0, end) + ');')();
}

const PAYLOAD = {
    overlay: 'eyJhY3Rpb24iOiJsb2FkUGxheWVyIn0',
    url: 'https://example.test/pricing',
    width: 1280,
    height: 800,
};

/* jqGrid hands a formatter the whole cell, not the value. */
const cell = (value) => ({ value: value, formatted_value: 'Play' });

describe('domstreamPlayer formatter', () => {

    const fmt = loadFormatter();

    test('renders a play link', () => {
        const html = fmt(cell(PAYLOAD));

        expect(html).toContain('class="play"');
        expect(html).toContain('>Play</a>');
    });

    test('the href is the recorded page with the overlay on the fragment', () => {
        const html = fmt(cell(PAYLOAD));

        expect(html).toContain('href="https://example.test/pricing#owa_overlay.'
            + PAYLOAD.overlay + '"');
    });

    /**
     * The viewport travels as data attributes because the click handler needs
     * it to size the window: the replay positions events against the geometry
     * they were recorded in, so a window of the reader's size puts the pointer
     * in the wrong places.
     */
    test('carries the recorded viewport', () => {
        const html = fmt(cell(PAYLOAD));

        expect(html).toContain('data-width="1280"');
        expect(html).toContain('data-height="800"');
    });

    test('a viewport that is not a number becomes zero rather than markup', () => {
        const html = fmt(cell(Object.assign({}, PAYLOAD, {
            width: '" onmouseover="alert(1)',
            height: null,
        })));

        expect(html).toContain('data-width="0"');
        expect(html).toContain('data-height="0"');
        expect(html).not.toContain('onmouseover');
    });

    /**
     * The url and the overlay are the two fields that reach an attribute
     * unmodified. page_url is recorded from the tracked page, so it is not
     * ours; a quote in it would otherwise close the attribute and everything
     * after it would be markup.
     *
     * Asserted by PARSING rather than by searching the string. A substring
     * check is the wrong instrument here: escaping works, so the injected text
     * is still present in the output -- inert, inside the href value. Searching
     * for `onclick=` fails on the escaped-and-safe output exactly as it does on
     * the vulnerable one. What actually matters is what a browser makes of it,
     * so this asks one.
     */
    describe('an injected quote cannot escape its attribute', () => {

        const parse = (html) => {
            const host = document.createElement('div');
            host.innerHTML = html;
            return host;
        };

        test('in the url', () => {
            const host = parse(fmt(cell(Object.assign({}, PAYLOAD, {
                url: 'https://example.test/"><script>alert(1)</script>',
            }))));

            expect(host.querySelector('script')).toBeNull();
            expect(host.children).toHaveLength(1);

            // The whole hostile string is the href, and nothing else.
            expect(host.querySelector('a').getAttribute('href'))
                .toBe('https://example.test/"><script>alert(1)</script>'
                    + '#owa_overlay.' + PAYLOAD.overlay);
        });

        test('in the overlay payload', () => {
            const host = parse(fmt(cell(Object.assign({}, PAYLOAD, {
                overlay: 'abc" onclick="alert(1)',
            }))));

            const a = host.querySelector('a');

            expect(a.getAttribute('onclick')).toBeNull();
            expect(a.onclick).toBeNull();

            expect(a.getAttribute('href'))
                .toBe('https://example.test/pricing#owa_overlay.abc" onclick="alert(1)');
        });

        test('the anchor carries no attributes beyond the ones it is given', () => {
            const host = parse(fmt(cell(Object.assign({}, PAYLOAD, {
                url: 'https://example.test/" data-evil="1',
            }))));

            const names = Array.from(host.querySelector('a').attributes)
                .map((attr) => attr.name).sort();

            expect(names).toEqual(['class', 'data-height', 'data-width', 'href']);
        });
    });

    /** The cell may arrive already serialised. */
    test('accepts the payload as a JSON string', () => {
        const html = fmt(cell(JSON.stringify(PAYLOAD)));

        expect(html).toContain('data-width="1280"');
        expect(html).toContain('href="https://example.test/pricing#owa_overlay.');
    });

    /**
     * A recording the player cannot be built for renders as NOTHING, not as a
     * broken link. A Play link that goes nowhere is worse than no link: the
     * reader cannot tell it apart from one that works.
     */
    describe('renders nothing when the player cannot be built', () => {

        test.each([
            ['no value', null],
            ['empty object', {}],
            ['no url', { overlay: 'x' }],
            ['no overlay', { url: 'https://example.test/' }],
            ['unparseable json', '{not json'],
        ])('%s', (label, value) => {
            expect(fmt(cell(value))).toBe('');
        });
    });
});
