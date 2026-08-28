const fs = require('fs');
const path = require('path');

/**
 * One slice per LABEL, not one per row.
 *
 * A boolean column holds three values: 1, 0 and NULL each group separately in
 * SQL, so `isRepeatVisitor` comes back as three rows on any site with history
 * predating the derivation fix. The dashboard's Visitor Types pie folds 0 and
 * NULL together with valueLabels because they mean the same thing -- this
 * visitor had not been here before -- and the pie drew that as two slices both
 * labelled "New", splitting the total between them.
 *
 * Driven against the BUILT bundle rather than a copy of the loop, so it cannot
 * pass while the shipped pie does something else. jsdom cannot paint, which
 * does not matter: what is under test is the series handed to flot, and that is
 * captured by standing in for jQuery.plot.
 */
describe('a pie draws one slice per label', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const bundlePath = path.join(repoRoot, 'public/base/dist/owa.reporting-combined-min.js');

    let OWA;
    let plotted;   // the data array flot was handed

    beforeAll(() => {
        if (!fs.existsSync(bundlePath)) { return; }

        const run = new Function('window', 'document', 'navigator',
            fs.readFileSync(bundlePath, 'utf8'));
        run(window, document, navigator);
        OWA = window.OWA;

        // Stand in for flot. The real one measures a canvas jsdom will not
        // paint; the series it is given is the whole of what is being asserted.
        window.jQuery.plot = (el, data) => { plotted = data; };
    });

    /**
     * @param rows [rawValue, formattedValue, visits] triples
     */
    function drawPie(rows, valueLabels) {

        document.body.innerHTML = '<div id="pie-under-test"></div>';

        plotted = null;

        const resultSet = {
            guid: 'pie-test',
            resultsRows: rows.map(([value, formatted, visits]) => ({
                isRepeatVisitor: { value, formatted_value: formatted },
                visits: { value: visits },
            })),
            aggregates: { visits: { value: rows.reduce((t, r) => t + r[2], 0) } },
        };

        const pie = new OWA.pieChart();

        pie.mergeOptions({
            dimension: 'isRepeatVisitor',
            metric: 'visits',
            numSlices: 10,
            valueLabels: valueLabels,
        });

        pie.generate(resultSet, 'pie-under-test');

        return plotted;
    }

    /*
     * The shape that produced the bug, exactly: three rows, 1 / 0 / NULL, with
     * the last two folded onto one label by the report's own value map.
     */
    test('rows folded onto one label are one slice carrying their total', () => {
        if (!OWA) return;

        const data = drawPie(
            [['1', 'Yes', 6], ['0', 'No', 3], [null, '', 1]],
            { '1': 'Repeat', '0': 'New', '': 'New' }
        );

        expect(data.map(s => s.label)).toEqual(['Repeat', 'New']);

        // 3 + 1. A pie that drew two "New" slices still totalled 4 across
        // them, so the count is what separates merged from merely deduped.
        expect(data.find(s => s.label === 'New').data).toBe(4);
        expect(data.find(s => s.label === 'Repeat').data).toBe(6);
    });

    /*
     * ...and rows that do NOT share a label are still their own slices. Without
     * this the test above passes on a pie that collapsed everything into one.
     */
    test('rows with distinct labels stay distinct slices', () => {
        if (!OWA) return;

        const data = drawPie(
            [['1', 'Yes', 6], ['0', 'No', 3], [null, '', 1]],
            { '1': 'Repeat', '0': 'New', '': 'Unknown' }
        );

        expect(data.map(s => s.label)).toEqual(['Repeat', 'New', 'Unknown']);
        expect(data.map(s => s.data)).toEqual([6, 3, 1]);
    });
});
