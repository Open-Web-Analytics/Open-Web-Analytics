import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

/**
 * A jqGrid formatter's return value becomes the cell's HTML.
 *
 * So every value a formatter concatenates into its output is markup until it is
 * escaped. Two of the formatters in owa.resultSetExplorer.js always did this
 * with a local copy of the same five replaces; urlFormatter and
 * useServerFormatter built markup without it. There is one helper now, and
 * these tests pin it -- a report cell renders values that came from the wire.
 */
describe('OWA.util.escapeHtml', () => {

    test('neutralises the characters that make markup', () => {
        expect(OWA.util.escapeHtml('<img src=x onerror=y>'))
            .toBe('&lt;img src=x onerror=y&gt;');
    });

    test('closes the double-quoted attribute position', () => {
        expect(OWA.util.escapeHtml('" onmouseover="x'))
            .toBe('&quot; onmouseover=&quot;x');
    });

    test('closes the single-quoted attribute position', () => {
        expect(OWA.util.escapeHtml("' onmouseover='x"))
            .toBe('&#39; onmouseover=&#39;x');
    });

    /* Ampersand first, or every later replacement gets double-escaped. */
    test('does not double-escape', () => {
        expect(OWA.util.escapeHtml('a & b')).toBe('a &amp; b');
        expect(OWA.util.escapeHtml('&lt;')).toBe('&amp;lt;');
    });

    test('leaves ordinary text alone', () => {
        expect(OWA.util.escapeHtml('Grüße aus München')).toBe('Grüße aus München');
        expect(OWA.util.escapeHtml('https://x.test/a?b=1')).toBe('https://x.test/a?b=1');
    });

    test('renders absent values as empty rather than "null"', () => {
        expect(OWA.util.escapeHtml(null)).toBe('');
        expect(OWA.util.escapeHtml(undefined)).toBe('');
    });

    test('stringifies non-strings', () => {
        expect(OWA.util.escapeHtml(0)).toBe('0');
        expect(OWA.util.escapeHtml(false)).toBe('false');
    });

    /*
     * The property that matters: whatever goes in, no '<' comes out, so the
     * result cannot open a tag in the cell it is written into.
     */
    test('never emits a character that can open a tag', () => {
        const inputs = [
            '<script>alert(1)</script>',
            '<svg/onload=alert(1)>',
            '</a><img src=x onerror=alert(1)>',
            '<<b>>',
            'plain',
        ];

        inputs.forEach((input) => {
            const out = OWA.util.escapeHtml(input);
            expect(out).not.toMatch(/[<>]/);
        });
    });
});
