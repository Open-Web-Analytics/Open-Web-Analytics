/**
 * Headlines are a sentence with named slots, substituted here.
 *
 * They used to be jqote templates carried in report configuration -- 55 inline
 * strings plus 4 named blocks. A definition that can hand a template engine
 * arbitrary source cannot safely be authored by a user, which is what report
 * configuration is meant to become. So the message stays data and the widget
 * does the substituting.
 *
 * The four "rich" headlines turned out to need exactly one thing the other 55
 * did not: singular/plural. That is data about a language, not code.
 */

const path = require('path');
const fs = require('fs');

/**
 * The renderer, lifted out of the explorer.
 *
 * Loading the whole reporting bundle to test one pure substitution would drag
 * in jQuery, flot and jqgrid; the function under test needs none of them. It is
 * read from source so this cannot drift from what ships -- if the method is
 * renamed or removed, this fails rather than testing a copy.
 */
function loadRenderHeadline() {
    const src = fs.readFileSync(
        path.join(__dirname, '../../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js'),
        'utf8'
    );

    const start = src.indexOf('renderHeadline : function');
    expect(start).toBeGreaterThan(-1);

    // Up to the next sibling method at the same indentation.
    const end = src.indexOf('\n    renderTemplate : function', start);
    expect(end).toBeGreaterThan(start);

    // From the `function` keyword, so the parameter list comes with it, and
    // back to the closing brace, dropping the trailing comma that separates it
    // from the next method.
    const decl = src.slice(src.indexOf('function', start), end);
    const body = decl.slice(0, decl.lastIndexOf('}') + 1);

    // A stand-in for the jQuery call at the end, so the text is observable.
    const written = { html: null };
    const jQuery = () => ({ html: (t) => { written.html = t; } });

    // eslint-disable-next-line no-new-func
    const fn = new Function('jQuery', 'return (' + body + ');')(jQuery);

    return { fn, written };
}

function explorer(aggregates) {
    return { dom_id: 'headline', resultSet: { aggregates } };
}

describe('headline slots', () => {

    const agg = {
        visits: { value: 5, formatted_value: '5' },
        pageViews: { value: 1234, formatted_value: '1,234' },
        uniquePageViews: { value: 900, formatted_value: '900' },
        one: { value: 1, formatted_value: '1' },
        none: { value: 0, formatted_value: '0' },
    };

    const render = (message, aggregates = agg) => {
        const { fn, written } = loadRenderHeadline();
        fn.call(explorer(aggregates), message);
        return written.html;
    };

    test('a formatted slot uses the formatted value, keeping its separators', () => {
        expect(render('There were {pageViews.formatted} page views.'))
            .toBe('There were 1,234 page views.');
    });

    test('a raw slot uses the underlying value', () => {
        // The existing headlines reach for BOTH -- one of the 55 asks for
        // uniquePageViews.value while asking for pageViews.formatted_value in
        // the same sentence, so a slot has to say which it wants.
        expect(render('{pageViews.formatted} views over {uniquePageViews.raw} pages.'))
            .toBe('1,234 views over 900 pages.');
    });

    test('several slots in one sentence', () => {
        expect(render('{visits.formatted} / {pageViews.formatted} / {uniquePageViews.raw}'))
            .toBe('5 / 1,234 / 900');
    });

    describe('pluralisation', () => {

        test('one is singular', () => {
            expect(render('{one.formatted} {one|visit|visits}')).toBe('1 visit');
        });

        test('more than one is plural', () => {
            expect(render('{visits.formatted} {visits|visit|visits}')).toBe('5 visits');
        });

        test('zero is plural', () => {
            // What the templates being replaced did (`> 1`), and right for
            // English: "0 visits", not "0 visit".
            expect(render('{none.formatted} {none|visit|visits}')).toBe('0 visits');
        });
    });

    test('an unknown metric renders as nothing, not as the slot text', () => {
        // A mistyped name should read as missing data, not leak markup into
        // the sentence.
        expect(render('There were {nosuchmetric.formatted} of them.'))
            .toBe('There were  of them.');

        expect(render('{nosuchmetric|thing|things}')).toBe('things');
    });

    test('a message with no slots is rendered as written', () => {
        expect(render('Nothing to interpolate here.')).toBe('Nothing to interpolate here.');
    });

    test('survives a result set that has not loaded', () => {
        const { fn, written } = loadRenderHeadline();

        fn.call({ dom_id: 'headline', resultSet: [] }, 'There were {visits.formatted} visits.');

        expect(written.html).toBe('There were  visits.');
    });

    /**
     * The real headlines, converted.
     *
     * Each of these is one of the sentences the reports actually shipped, so
     * the substitution is checked against the text it has to reproduce.
     */
    test('reproduces the shipped headlines', () => {
        expect(render('There were {pageViews.formatted} page views for {uniquePageViews.raw} unique pages.'))
            .toBe('There were 1,234 page views for 900 unique pages.');

        expect(render('There were {visits.formatted} {visits|visit|visits} from all mediums.'))
            .toBe('There were 5 visits from all mediums.');
    });
});
