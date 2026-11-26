// The editor imports jQuery as the default; babel-jest's interop needs jQuery
// marked an ES module and its own default for that to be callable.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    jq.default = jq;
    return jq;
});

import {
    say, names, widthWord, heightWord, typeRules, newWidget,
    reportProblem, panelProblem, applyPanel, buildDefinition,
} from '../../modules/Base/src/reporting/owa.customReportEdit.js';
import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

/**
 * The custom report editor's rules and the definition it builds
 * (owa.customReportEdit.js), from data shaped as custom_report_edit.php hands
 * it over.
 */

const CONFIG = {
    maxWidgets: 20,
    maxMetrics: 8,
    maxDimensions: 3,
    columns: 12,
    fullWidthTypes: ['grid'],
    singleFieldTypes: ['grid-card', 'pie'],
    singleMetricTypes: ['grid-card', 'pie'],
    chartTypes: ['trend', 'trend-card', 'pie'],
    ungroupedTypes: ['metric-boxes'],
    defaultColspans: { 'grid-card': 4, pie: 4, 'trend-card': 4, 'metric-boxes': 12 },
    newColspans: { 'grid-card': 6, pie: 6, 'trend-card': 6 },
    fixedDimensions: { trend: 'date', 'trend-card': 'date' },
    fixedDimensionExtra: { trend: 1 },
    timeDimensions: ['date', 'month', 'hour'],
    moreLabel: 'View Full Report »',
    linkTargets: {
        browserType: [{ id: 'browser-detail', label: 'Browser', param: 'browserType' }],
    },
};

const rules = typeRules(CONFIG);

afterEach(() => {
    delete OWA.config.strings;
});

describe('the words', () => {

    test('a pattern is filled in order, or by position', () => {
        expect(say('%1$s of %2$s', 3, 12)).toBe('3 of 12');
        expect(say('%s has %d', 'w', 2)).toBe('w has 2');
        expect(say('100%%')).toBe('100%');
    });

    test('a width and a height have a word where the card has one', () => {
        expect(widthWord(6)).toBe('Half width');
        expect(widthWord(5)).toBe('');
        expect(heightWord(2)).toBe('tall');
        expect(heightWord(3)).toBe('');
    });

    test('the words are translated where the page carries a translation', () => {
        OWA.config.strings = { 'Half width': 'Halbe Breite', 'Add at least one widget before saving.': 'Erst ein Widget.' };

        expect(widthWord(6)).toBe('Halbe Breite');
        expect(reportProblem([], 'R', '')).toBe('Erst ein Widget.');
    });

    test('a comma string is a list of names', () => {
        expect(names(' visits, pageViews ,,')).toEqual(['visits', 'pageViews']);
        expect(names(['a', ' b'])).toEqual(['a', 'b']);
        expect(names('')).toEqual([]);
    });
});

describe('what each type decides', () => {

    test('a full-width type draws across the grid whatever it stored', () => {
        expect(rules.colspanOf({ type: 'grid', colspan: 4 })).toBe(12);
        expect(rules.colspanOf({ type: 'grid-card', colspan: 8 })).toBe(8);
        expect(rules.colspanOf({ type: 'grid-card' })).toBe(4);
        expect(rules.colspanOf({ type: 'unknown' })).toBe(12);
    });

    test('a trend picks only its breakdown; totals pick nothing', () => {
        expect(rules.picksDimensions('trend')).toBe(true);
        expect(rules.picksDimensions('trend-card')).toBe(false);
        expect(rules.picksDimensions('metric-boxes')).toBe(false);
        expect(rules.picksDimensions('grid')).toBe(true);

        expect(rules.maxDimensions('trend')).toBe(1);
        expect(rules.maxDimensions('trend-card')).toBe(0);
        expect(rules.maxDimensions('grid-card')).toBe(1);
        expect(rules.maxDimensions('grid')).toBe(3);
    });

    test('a full-width table takes its own dimension cap, the others keep theirs', () => {
        const wide = typeRules({ ...CONFIG, fullWidthMaxDimensions: 8 });

        expect(wide.maxDimensions('grid')).toBe(8);
        expect(wide.maxDimensions('grid-card')).toBe(1);
        expect(wide.maxDimensions('trend')).toBe(1);
    });

    test('a card or a pie takes one metric', () => {
        expect(rules.maxMetrics('pie')).toBe(1);
        expect(rules.maxMetrics('grid')).toBe(8);
    });

    test('a new widget is numbered, as wide as its type is added, and a trend is over its date', () => {
        expect(newWidget(rules, 0, 'grid-card')).toEqual({
            type: 'grid-card', title: 'Widget 1', rowspan: 1, colspan: 6, query: {},
        });

        expect(newWidget(rules, 2, 'grid')).toEqual({ type: 'grid', title: 'Widget 3', rowspan: 1, query: {} });

        expect(newWidget(rules, 1, 'trend')).toEqual({
            type: 'trend', title: 'Widget 2', rowspan: 1, colspan: 12,
            query: { dimensions: 'date', sort: 'date' },
        });
    });
});

describe('what stops a save', () => {

    const ready = [{ type: 'grid', title: 'Pages', query: { metrics: 'pageViews' } }];

    test('a widget, a name, then every widget\'s metrics', () => {
        expect(reportProblem([], 'R', '')).toBe('Add at least one widget before saving.');
        expect(reportProblem(ready, '   ', '')).toBe('Give the report a name before saving.');
        expect(reportProblem(ready.concat([{ type: 'pie', query: {} }]), 'R', ''))
            .toBe('Widget 2 has no metrics.');
        expect(reportProblem(ready, 'R', '')).toBe('');
    });

    test('a stored report metric set lets a widget inherit, as the server does', () => {
        expect(reportProblem([{ type: 'grid', query: {} }], 'R', 'visits')).toBe('');
    });

    test('the panel asks for a metric, then the one dimension a card needs', () => {
        expect(panelProblem(rules, 'pie', [], [], '')).toBe('Choose a metric.');
        expect(panelProblem(rules, 'grid', [], [], '')).toBe('Choose at least one metric.');
        expect(panelProblem(rules, 'grid-card', ['visits'], [], '')).toBe('Choose a dimension.');
        expect(panelProblem(rules, 'grid', ['visits'], [], '')).toBe('');
        expect(panelProblem(rules, 'grid', [], [], 'visits')).toBe('');
    });
});

describe('the widget the panel writes', () => {

    const panel = (over) => Object.assign({
        index: 0, title: 'Top browsers', colspan: 6, rowspan: 2,
        metrics: ['visits', 'pageViews'], dimensions: ['browserType'],
        sort: 'visits', sortDesc: true, constraints: '', linkTo: '', moreTo: '', moreLabel: '',
    }, over);

    test('a card keeps its span, its fields in the author\'s order and its sort', () => {
        const widget = applyPanel(rules, CONFIG, { type: 'grid-card', chartMetric: 'old' }, panel());

        expect(widget).toEqual({
            type: 'grid-card', title: 'Top browsers', colspan: 6, rowspan: 2,
            query: { metrics: 'visits,pageViews', dimensions: 'browserType', sort: 'visits-' },
        });
    });

    test('a trend is over its date first, sorted by it, and charts its first metric', () => {
        const widget = applyPanel(rules, CONFIG, { type: 'trend' },
            panel({ colspan: 0, dimensions: ['browserType'], sort: 'visits' }));

        expect(widget.query).toEqual({ metrics: 'visits,pageViews', dimensions: 'date,browserType', sort: 'date' });
        expect(widget.chartMetric).toBe('visits');
        expect(widget.colspan).toBe(12);
    });

    test('a row of totals has no dimensions and no sort', () => {
        const widget = applyPanel(rules, CONFIG, { type: 'metric-boxes' }, panel({ colspan: 0 }));

        expect(widget.query).toEqual({ metrics: 'visits,pageViews' });
        expect(widget.colspan).toBe(12);
    });

    test('a row link is derived from the one dimension and the destination\'s parameter', () => {
        const widget = applyPanel(rules, CONFIG, { type: 'grid-card' }, panel({ linkTo: 'browser-detail' }));

        expect(widget.link).toEqual({
            linkColumn: 'browserType',
            template: { do: 'base.report', reportId: 'browser-detail', browserType: '%s' },
            valueColumns: 'browserType',
        });
    });

    test('a link, filters and a full-report link the panel no longer holds are dropped', () => {
        const widget = applyPanel(rules, CONFIG, {
            type: 'grid',
            link: { linkColumn: 'x' }, constraints: 'a==b', more: { reportId: 'r' },
        }, panel({ linkTo: 'browser-detail' }));

        expect(widget.link).toBeUndefined();
        expect(widget.constraints).toBeUndefined();
        expect(widget.more).toBeUndefined();
        expect(widget.colspan).toBeUndefined();
    });

    test('a full-report link says the default unless the author wrote other words', () => {
        expect(applyPanel(rules, CONFIG, { type: 'grid-card' }, panel({ moreTo: 'browsers' })).more)
            .toEqual({ reportId: 'browsers', label: 'View Full Report »' });
        expect(applyPanel(rules, CONFIG, { type: 'grid-card' }, panel({ moreTo: 'browsers', moreLabel: 'All' })).more)
            .toEqual({ reportId: 'browsers', label: 'All' });
    });

    test('a nameless widget is named by its place, in the install\'s words', () => {
        expect(applyPanel(rules, CONFIG, { type: 'grid' }, panel({ title: '', index: 3 })).title).toBe('Widget 4');

        OWA.config.strings = { 'Widget %s': 'Baustein %s' };

        expect(applyPanel(rules, CONFIG, { type: 'grid' }, panel({ title: '', index: 3 })).title).toBe('Baustein 4');
    });
});

describe('the definition posted', () => {

    test('widgets in order, addressed w1, w2..., the stored metric set passed through', () => {
        const widgets = [{ type: 'grid', title: 'A' }, { type: 'pie', title: 'B' }];

        expect(buildDefinition('My report', widgets, 'visits')).toEqual({
            title: 'My report',
            metrics: 'visits',
            widgets: [
                { type: 'grid', title: 'A', id: 'w1', container: 'w1' },
                { type: 'pie', title: 'B', id: 'w2', container: 'w2' },
            ],
        });

        expect(widgets[0].id).toBeUndefined();
        expect(buildDefinition('R', [], '')).toEqual({ title: 'R', widgets: [] });
    });
});
