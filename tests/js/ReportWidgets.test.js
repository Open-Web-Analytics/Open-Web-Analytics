// The explorer and the report import jQuery as a namespace and call it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');
require('../../modules/Base/src/reporting/owa.report.js');

const { render, publish } = require('../../modules/Base/src/reporting/owa.reportWidgets.js');

/**
 * A report's widgets built from the descriptions report_widgets.php hands over
 * (owa.reportWidgets.js): the globals each widget declares, what each explorer
 * is given, and the order sets and tabs are wired in.
 */

const json = (hook, data) => `<script type="application/json" data-ui="${hook}">${JSON.stringify(data)}</script>`;

const trend = {
    name: 'trend', container: 'trend', url: 'https://owa.test/api/index.php?do=reports&dimensions=date&nonce=n',
    options: { sparkline: { metric: 'sessions' }, areaChart: { showTitle: false }, metricBoxes: { width: '150px' } },
    queue: [['makeAreaChart', [{ x: 'date', y: 'visits' }], 'trend'], ['makeMetricBoxes', 'trend-metrics']],
    load: true,
};

const grid = {
    name: 'dim', container: 'dim', url: 'https://owa.test/api/index.php?do=reports&dimensions=pagePath&nonce=n',
    options: { grid: { excludeColumns: ['pageUrl'] } },
    queue: [['refreshGrid']],
    load: true,
};

let loads;

function page(html) {
    document.body.innerHTML = html;
}

beforeEach(() => {
    loads = [];
    jest.spyOn(OWA.resultSetExplorer.prototype, 'load').mockImplementation(function () { loads.push(this.dom_id); });
    OWA.items.report = new OWA.report();
    OWA.items.report.dom_id = 'report';
});

afterEach(() => {
    jest.restoreAllMocks();
    delete OWA.items.report;
    for (const k of ['trend', 'trendurl', 'trendBreakdown', 'trendBreakdownurl', 'dim', 'dimurl', 'dimlink',
        'site_usage_trend', 'site_usage_trendurl', 'site_usage_dim', 'site_usage_dimurl', 'owaSet_site_usage',
        'w1', 'w1url', 'after', 'afterurl', 'topurl']) {
        delete window[k];
    }
    delete window.reportError;
    document.body.innerHTML = '';
});

describe('a widget', () => {

    test('is an explorer published under its name, with its URL beside it', () => {
        page(`<div id="trend"></div>${json('report-widget:config', trend)}`);

        const made = render(document);

        expect(window.trend).toBeInstanceOf(OWA.resultSetExplorer);
        expect(window.trend).toBe(made.trend);
        expect(window.trend.dom_id).toBe('trend');
        expect(window.trendurl).toBe(trend.url);
        expect(window.trend.loadUrl).toBe(trend.url);
    });

    test('is given each option and queues each command, in order, without replacing the rest of its options', () => {
        page(json('report-widget:config', trend));

        render(document);

        expect(window.trend.options.sparkline.metric).toBe('sessions');
        expect(window.trend.options.areaChart.showTitle).toBe(false);
        expect(window.trend.options.areaChart.fill).toBe(true);
        expect(window.trend.options.metricBoxes.width).toBe('150px');
        expect(window.trend.asyncQueue).toEqual(trend.queue);
    });

    test('loads once, unless a tab is to load it', () => {
        page(json('report-widget:config', trend) + json('report-widget:config', { ...grid, load: false }));

        render(document);

        expect(loads).toEqual(['trend']);
    });

    test('a broken-out trend makes its grid, linked to it, and loads the trend first', () => {
        jest.spyOn(OWA, 'linkTrendToBreakdownGrid').mockImplementation(() => {});
        page(json('report-widget:config', {
            ...trend,
            breakdown: { name: 'trendBreakdown', container: 'trend-breakdown', url: 'https://owa.test/b', queue: [['refreshGrid']] },
        }));

        render(document);

        expect(window.trendBreakdown).toBeInstanceOf(OWA.resultSetExplorer);
        expect(window.trendBreakdown.dom_id).toBe('trend-breakdown');
        expect(window.trendBreakdownurl).toBe('https://owa.test/b');
        expect(window.trendBreakdown.asyncQueue).toEqual([['refreshGrid']]);
        expect(OWA.linkTrendToBreakdownGrid).toHaveBeenCalledWith(window.trend, window.trendBreakdown);
        expect(loads).toEqual(['trend', 'trend-breakdown']);
    });

    test('a grid links a column, with its URL published as <name>link', () => {
        page(json('report-widget:config', {
            ...grid,
            columnLink: { column: 'pagePath', url: 'https://owa.test/?do=base.report&pagePath=%s', values: { pagePath: 'pagePath' }, except: ['(exit)'] },
        }));

        render(document);

        expect(window.dimlink).toBe('https://owa.test/?do=base.report&pagePath=%s');
        expect(window.dim.columnLinks.pagePath).toEqual({
            name: 'pagePath', template: window.dimlink, params: { pagePath: 'pagePath' }, except: ['(exit)'],
        });
        expect(window.dim.options.grid.excludeColumns).toEqual(['pageUrl']);
    });
});

describe('a metric set', () => {

    test('links its trend to its grid once both exist', () => {
        jest.spyOn(OWA, 'linkTrendToGrid').mockImplementation(() => {});
        page(json('report-widget:config', trend) + json('report-widget:config', grid)
            + json('report-widgets:set', { report: 'report', link: ['trend', 'dim'] }));

        render(document);

        expect(OWA.linkTrendToGrid).toHaveBeenCalledWith(window.trend, window.dim);
    });

    test('drawn per set, is a tab holding its result sets by key, in order, added to the report; the tabs are built last', () => {
        const order = [];
        jest.spyOn(OWA.items.report, 'addTab').mockImplementation(function (tab) { order.push('addTab'); this.tabs[tab.dom_id] = tab; });
        jest.spyOn(OWA.items.report, 'createTabs').mockImplementation(() => order.push('createTabs'));

        page(json('report-widget:config', { ...trend, name: 'site_usage_trend', load: false })
            + json('report-widget:config', { ...grid, name: 'site_usage_dim', load: false })
            + json('report-widgets:set', { report: 'report', tab: {
                name: 'owaSet_site_usage', panel: 'tab_site_usage', label: 'Site Usage & more',
                rses: [['trend', 'site_usage_trend'], ['1', 'site_usage_dim']],
            } })
            + json('report-widgets:tabs', { report: 'report' }));

        render(document);

        const tab = window.owaSet_site_usage;

        expect(tab).toBeInstanceOf(OWA.report.tab);
        expect(tab.dom_id).toBe('tab_site_usage');
        expect(tab.label).toBe('Site Usage & more');
        expect(Object.keys(tab.resultSetExplorers)).toEqual(['1', 'trend']);
        expect(tab.resultSetExplorers.trend).toBe(window.site_usage_trend);
        expect(tab.resultSetExplorers['1']).toBe(window.site_usage_dim);
        expect(OWA.items.report.tabs.tab_site_usage).toBe(tab);
        expect(order).toEqual(['addTab', 'createTabs']);
        expect(loads).toEqual([]);

        tab.load();

        expect(loads.sort()).toEqual(['dim', 'trend']);
    });
});

describe('what a page cannot break', () => {

    test('a name window holds read-only still makes a widget, and the set links the widget itself', () => {
        jest.spyOn(OWA, 'linkTrendToGrid').mockImplementation(() => {});
        page(json('report-widget:config', { ...trend, name: 'top' }) + json('report-widget:config', grid)
            + json('report-widgets:set', { report: 'report', link: ['top', 'dim'] }));

        const made = render(document);

        expect(made.top).toBeInstanceOf(OWA.resultSetExplorer);
        expect(window.top).not.toBe(made.top);
        expect(OWA.linkTrendToGrid).toHaveBeenCalledWith(made.top, window.dim);
    });

    test('a widget that fails stops only itself, and is reported', () => {
        window.reportError = jest.fn();
        page('<script type="application/json" data-ui="report-widget:config">{"name": </script>'
            + json('report-widget:config', { ...grid, name: 'after' }));

        render(document);

        expect(window.reportError).toHaveBeenCalledTimes(1);
        expect(window.after).toBeInstanceOf(OWA.resultSetExplorer);
    });

    test('publish() refuses a read-only name without throwing', () => {
        expect(() => publish('top', 1)).not.toThrow();
        expect(window.top).not.toBe(1);
    });
});

describe('the report controls (report.php)', () => {

    test('start the picker with its config, the as-of line and the site filter', () => {
        const order = [];
        jest.spyOn(OWA.items.report, 'displayTimePeriodPicker').mockImplementation(() => order.push('picker'));
        jest.spyOn(OWA.items.report, 'showAsOf').mockImplementation(() => order.push('asOf'));
        jest.spyOn(OWA.items.report, 'showSiteFilter').mockImplementation(() => order.push('sites'));

        page(json('report:controls', { report: 'report', siteFilter: true, periodPicker: { period: 'last_28_days' } }));

        render(document);

        expect(OWA.items.report.displayTimePeriodPicker).toHaveBeenCalledWith('#owa_timePeriodControl', { period: 'last_28_days' });
        expect(OWA.items.report.showAsOf).toHaveBeenCalledWith('#owa_reportAsOf');
        expect(order).toEqual(['picker', 'asOf', 'sites']);
    });

    test('hidden time controls start neither the picker nor the as-of line', () => {
        jest.spyOn(OWA.items.report, 'displayTimePeriodPicker').mockImplementation(() => {});
        jest.spyOn(OWA.items.report, 'showAsOf').mockImplementation(() => {});
        jest.spyOn(OWA.items.report, 'showSiteFilter').mockImplementation(() => {});

        page(json('report:controls', { report: 'report', siteFilter: false }));

        render(document);

        expect(OWA.items.report.displayTimePeriodPicker).not.toHaveBeenCalled();
        expect(OWA.items.report.showAsOf).not.toHaveBeenCalled();
        expect(OWA.items.report.showSiteFilter).not.toHaveBeenCalled();
    });
});
