/**
 * The attribution-history cell, rendered by a NAMED formatter.
 *
 * A report definition names `attributionList`; the widget resolves the name.
 * The definition never carries a function, which is the gate on report
 * configuration ever being user-authored -- the same reason excludeColumns
 * became a list of names rather than a fragment of script.
 *
 * It replaces a jqote template fetched by DOM id, and fixes it. jqGrid hands a
 * formatter the whole cell -- {value, formatted_value} -- so the old
 * `typeof value !== 'object'` guard never fired, the JSON was never parsed, and
 * jqote iterated the cell object instead of the attribution array. Every row
 * rendered its header line and nothing else.
 *
 * Escaping is the other half. All six fields arrive from URL parameters on a
 * tracked page, and jqote does not escape.
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

    const start = src.indexOf('attributionList : function');
    expect(start).toBeGreaterThan(-1);

    const decl = src.slice(src.indexOf('function', start));
    // Balance braces from the function's opening brace to its close.
    let depth = 0, end = -1;
    for (let i = decl.indexOf('{'); i < decl.length; i++) {
        if (decl[i] === '{') depth++;
        else if (decl[i] === '}' && --depth === 0) { end = i + 1; break; }
    }
    expect(end).toBeGreaterThan(0);

    // eslint-disable-next-line no-new-func
    return new Function('return (' + decl.slice(0, end) + ');')();
}

const cell = (json) => ({ value: json, formatted_value: json });

describe('attributionList formatter', () => {

    const fmt = loadFormatter();

    test('renders one block per attribution, in order', () => {
        const html = fmt(cell('[{"md":"beach","sr":"kauai"},{"md":"email"}]'));

        expect(html).toContain('<b>Attribution 1:</b>');
        expect(html).toContain('<b>Attribution 2:</b>');
        expect(html.indexOf('Attribution 1')).toBeLessThan(html.indexOf('Attribution 2'));
    });

    test('shows the fields an attribution carries, labelled', () => {
        const html = fmt(cell('[{"md":"beach","sr":"kauai"}]'));

        expect(html).toContain('<i>Medium:</i> beach');
        expect(html).toContain('<i>Source:</i> kauai');
    });

    test('omits the fields it does not carry', () => {
        const html = fmt(cell('[{"md":"beach"}]'));

        expect(html).toContain('Medium:');
        expect(html).not.toContain('Source:');
        expect(html).not.toContain('Campaign:');
        expect(html).not.toContain('Search Terms:');
    });

    /**
     * The bug the old template could not have. jqGrid passes the cell object,
     * so the JSON lives on .value -- reading the argument directly rendered
     * the header and nothing else.
     */
    test('reads the JSON from the cell object jqGrid actually passes', () => {
        const html = fmt(cell('[{"md":"beach","sr":"kauai"}]'));

        expect(html).toContain('beach');
        expect(html).not.toBe('(none)');
    });

    test('escapes every field, which arrive from URL parameters', () => {
        const html = fmt(cell(JSON.stringify(
            [{ md: '<img src=x onerror=alert(1)>', sr: '"quoted"', cn: "it's" }])));

        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img');
        expect(html).toContain('&quot;quoted&quot;');
        expect(html).toContain('it&#39;s');
    });

    test.each([
        ['an empty cell', cell('')],
        ['a null cell', null],
        ['an empty array', cell('[]')],
        ['unparseable JSON', cell('{not json')],
    ])('reads %s as (none)', (_label, value) => {
        expect(fmt(value)).toBe('(none)');
    });

    test('accepts an already-decoded array, since the column may arrive parsed', () => {
        expect(fmt(cell([{ md: 'beach' }]))).toContain('Medium:</i> beach');
    });
});
