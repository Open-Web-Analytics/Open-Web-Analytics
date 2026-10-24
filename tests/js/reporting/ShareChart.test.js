const fs = require('fs');
const path = require('path');

/**
 * A share chart: each value's share of one metric, ranked, the rest as Other.
 *
 * Driven against the BUILT bundle rather than a copy of the code, so it cannot
 * pass while the shipped chart does something else.
 */
describe('a share chart', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const bundlePath = path.join(repoRoot, 'public/base/dist/owa.reporting-combined-min.js');

    let OWA;

    beforeAll(() => {
        if (!fs.existsSync(bundlePath)) { return; }

        const run = new Function('window', 'document', 'navigator',
            fs.readFileSync(bundlePath, 'utf8'));
        run(window, document, navigator);
        OWA = window.OWA;
    });

    /**
     * @param rows  [rawValue, formattedValue, sessions] triples
     * @param total the period's total, when it is not the rows' sum
     */
    function resultSet(rows, total) {
        return {
            resultsRows: rows.map(([value, formatted, sessions]) => ({
                dimensionUnderTest: { value, formatted_value: formatted },
                sessions: { value: String(sessions), formatted_value: String(sessions) },
            })),
            aggregates: { sessions: { value: String(total === undefined
                ? rows.reduce((t, r) => t + r[2], 0) : total) } },
        };
    }

    function draw(rs, options) {
        document.body.innerHTML = '<div id="share-under-test"></div>';

        const chart = new OWA.shareChart(Object.assign({
            dimension: 'dimensionUnderTest',
            metric: 'sessions',
        }, options || {}));

        chart.generate(rs, 'share-under-test');

        return [...document.querySelectorAll('#share-under-test .owa_shareRow')].map((li) => ({
            label: li.querySelector('.owa_shareLabel').textContent,
            value: li.querySelector('.owa_shareValue').textContent,
            bar: li.querySelector('.owa_gridBarFill').style.width,
            other: li.classList.contains('is-other'),
        }));
    }

    test('the five largest are ranked, and the rest is Other, last', () => {
        if (!OWA) return;

        const rows = draw(resultSet([
            ['b', 'Direct', 290], ['a', 'Organic Search', 410], ['c', 'Referral', 140],
            ['d', 'Social', 90], ['e', 'Email', 40], ['f', 'Paid', 20], ['g', 'Display', 10],
        ]));

        expect(rows.map((r) => r.label))
            .toEqual(['Organic Search', 'Direct', 'Referral', 'Social', 'Email', 'Other']);
        expect(rows.map((r) => r.value)).toEqual(['41%', '29%', '14%', '9%', '4%', '3%']);
        expect(rows[5].other).toBe(true);

        // Bars against the largest share, so the first runs the width.
        expect(rows[0].bar).toBe('100%');
        expect(rows[1].bar).toBe('70.7%');
    });

    test('Other is the total less the rows shown, rows the query did not return included', () => {
        if (!OWA) return;

        const rows = draw(resultSet([['a', 'A', 50], ['b', 'B', 30]], 100));

        expect(rows.map((r) => [r.label, r.value])).toEqual([['A', '50%'], ['B', '30%'], ['Other', '20%']]);
    });

    test('no Other row when every value has a row', () => {
        if (!OWA) return;

        const rows = draw(resultSet([['a', 'A', 3], ['b', 'B', 1]]));

        expect(rows.map((r) => r.label)).toEqual(['A', 'B']);
        expect(rows.map((r) => r.value)).toEqual(['75%', '25%']);
    });

    /*
     * A valueLabels map may fold two raw values onto one name; they are one
     * row with their sum, not two rows with the same name.
     */
    test('rows folded onto one label are one row carrying their total', () => {
        if (!OWA) return;

        const rows = draw(resultSet([['1', 'Yes', 6], ['0', 'No', 3], [null, '', 1]]),
            { valueLabels: { '1': 'Repeat', '0': 'New', '': 'New' } });

        expect(rows.map((r) => [r.label, r.value])).toEqual([['Repeat', '60%'], ['New', '40%']]);
    });

    test('a share under half a percent reads <1%, not 0%', () => {
        if (!OWA) return;

        expect(OWA.shareChart.percent(0.004)).toBe('<1%');
        expect(OWA.shareChart.percent(0)).toBe('0%');
        expect(OWA.shareChart.percent(0.415)).toBe('42%');
    });

    test('no rows says there is no data', () => {
        if (!OWA) return;

        expect(draw(resultSet([]))).toEqual([]);
        expect(document.querySelector('#share-under-test .owa_gridEmpty').textContent)
            .toContain('No data');
    });

    test('a new result set redraws in place rather than adding a second list', () => {
        if (!OWA) return;

        draw(resultSet([['a', 'A', 1]]));
        window.jQuery('#share-under-test').trigger('new_result_set', [resultSet([['b', 'B', 2], ['c', 'C', 2]])]);

        expect(document.querySelectorAll('#share-under-test .owa_shareChart')).toHaveLength(1);
        expect([...document.querySelectorAll('.owa_shareLabel')].map((e) => e.textContent)).toEqual(['B', 'C']);
    });
});
