// The grid imports jQuery as a namespace and calls it. Webpack makes that
// callable; babel-jest's interop does only when jQuery is marked an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js');

/**
 * The report grid (OWA.dataGrid): a table drawn from a result set, with the
 * Filter button and paging above it and dimension pickers in its headings.
 *
 * Every control publishes an event on the grid's element and the explorer
 * refetches, so these assert what is drawn and what is published.
 */

/*
 * What config_dom.php sets from ResultSetManager::CONSTRAINT_OPERATORS on every
 * page; ConstraintOperatorsJsTest checks that it does, and in this order.
 */
const SERVER_OPERATORS = ['==', '!=', '>=', '<=', '>', '<', '=~', '!~', '=@', '!@'];

beforeEach(() => {
    OWA.setSetting('constraint_operators', SERVER_OPERATORS);
});

const API = 'https://owa.test/api/index.php?do=reports&module=base&version=v1';

function url(params) {
    return API + Object.keys(params).map((k) => '&' + k + '=' + encodeURIComponent(params[k])).join('');
}

function cell(name, type, value, formatted, extra) {
    return Object.assign({ name, result_type: type, value, formatted_value: formatted ?? String(value), label: LABELS[name] }, extra || {});
}

const LABELS = {
    browserType: 'Browser', osName: 'Operating System', medium: 'Medium',
    sessions: 'Sessions', bounceRate: 'Bounce Rate', pageViews: 'Page Views',
};

function resultSet(overrides = {}) {
    const dims = overrides.dimensions || 'browserType';
    const rows = overrides.rows || [
        { browserType: cell('browserType', 'dimension', 'Chrome'), sessions: cell('sessions', 'metric', 60), bounceRate: cell('bounceRate', 'metric', 0.4, '40.00%') },
        { browserType: cell('browserType', 'dimension', 'Firefox'), sessions: cell('sessions', 'metric', 30), bounceRate: cell('bounceRate', 'metric', 0.5, '50.00%') },
        { browserType: cell('browserType', 'dimension', 'Safari'), sessions: cell('sessions', 'metric', 10), bounceRate: cell('bounceRate', 'metric', 0.2, '20.00%') },
    ];

    return Object.assign({
        self: url(Object.assign({ dimensions: dims, metrics: 'sessions,bounceRate', page: overrides.page || 1 },
            overrides.constraints ? { constraints: overrides.constraints } : {})),
        resultsRows: rows,
        resultsReturned: rows.length,
        resultsTotal: rows.length,
        resultsPerPage: 25,
        page: 1,
        total_pages: 1,
        sortColumn: 'sessions',
        sortOrder: 'desc',
        aggregates: {
            sessions: { name: 'sessions', value: 100, formatted_value: '100' },
            bounceRate: { name: 'bounceRate', value: 0.38, formatted_value: '38.00%' },
        },
        relatedDimensions: {
            Visitor: [{ name: 'browserType', label: 'Browser' }, { name: 'osName', label: 'Operating System' }],
            Visit: [{ name: 'medium', label: 'Medium' }],
        },
        relatedMetrics: {
            'Site Usage': [
                { name: 'sessions', label: 'Sessions', description: 'The number of sessions.', metric_type: 'distinct_count' },
                { name: 'bounceRate', label: 'Bounce Rate', description: '', metric_type: 'ratio' },
                { name: 'pageViews', label: 'Page Views', description: 'Pages viewed.', metric_type: 'count' },
            ],
        },
    }, overrides.rs || {});
}

let n = 0;

function draw(rs, options, $parent) {
    const id = 'grid' + (++n);
    ($parent || $('body')).append('<div id="' + id + '"></div>');
    const grid = new OWA.dataGrid(id, Object.assign({ showRowNumbers: true, excludeColumns: [], columnFormatters: {}, showExplorerControls: true }, options || {}));
    const events = [];
    ['page_forward', 'page_back', 'page_change', 'show_more', 'dimension_list_change', 'sort_column_change', 'constraint_change']
        .forEach((name) => $('#' + id).on(name, (e, ...args) => events.push([name, ...args])));
    grid.generate(rs);
    return { grid, $root: $('#' + id), events };
}

const texts = ($els) => $els.map((i, el) => $(el).text().trim()).get();

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the filters a grid is given', () => {

    test('are read as the server reads them', () => {
        expect(OWA.dataGrid.parseConstraints('pagePath=@/blog/,pageViews>50')).toEqual([
            { name: 'pagePath', operator: '=@', value: '/blog/' },
            { name: 'pageViews', operator: '>', value: '50' },
        ]);
    });

    /* The server takes the first operator in its list that the clause contains. */
    test('a value may hold an operator that comes later in the list', () => {
        expect(OWA.dataGrid.parseConstraints('pageTitle==a>b')).toEqual([
            { name: 'pageTitle', operator: '==', value: 'a>b' },
        ]);
    });

    test('an empty test keeps (not set) as part of its operator', () => {
        const list = OWA.dataGrid.parseConstraints('medium==(not set),source!=(not set)');

        expect(list.map((c) => c.operator)).toEqual(['==(not set)', '!=(not set)']);
        expect(OWA.dataGrid.formatConstraints(list)).toBe('medium==(not set),source!=(not set)');
    });

    test('nothing is no filters', () => {
        expect(OWA.dataGrid.parseConstraints('')).toEqual([]);
        expect(OWA.dataGrid.parseConstraints(undefined)).toEqual([]);
    });

    /* The order is the page's, so a clause is read by the list the server sent. */
    test('the operators are the ones the page was given', () => {
        expect(OWA.dataGrid.parseConstraints('sessions>=5')).toEqual([
            { name: 'sessions', operator: '>=', value: '5' },
        ]);

        OWA.setSetting('constraint_operators', ['>', '>=']);

        expect(OWA.dataGrid.parseConstraints('sessions>=5')).toEqual([
            { name: 'sessions', operator: '>', value: '=5' },
        ]);
    });
});

describe('which rows a page shows', () => {

    test('short of ten pages ahead, the total is exact', () => {
        expect(OWA.dataGrid.range({ resultsPerPage: 10, page: 2, resultsReturned: 10, resultsTotal: 38 }))
            .toEqual({ start: 11, end: 20, total: 48, more: false });
    });

    test('at ten pages ahead there may be more', () => {
        expect(OWA.dataGrid.range({ resultsPerPage: 10, page: 1, resultsReturned: 10, resultsTotal: 100 }))
            .toEqual({ start: 1, end: 10, total: 100, more: true });
    });
});

describe('a row\'s share', () => {

    test('is to one place', () => {
        expect(OWA.dataGrid.share(1, 3)).toBe('33.3%');
        expect(OWA.dataGrid.share(0, 7)).toBe('0.0%');
    });

    test('is written as the install\'s locale writes a percentage', () => {
        OWA.config.locale = 'de-DE';

        try {
            expect(OWA.dataGrid.share(1, 3)).toBe('33,3\u00a0%');
        } finally {
            delete OWA.config.locale;
        }
    });

    test('is nothing of a zero or missing total', () => {
        expect(OWA.dataGrid.share(4, 0)).toBe('');
        expect(OWA.dataGrid.share(4, undefined)).toBe('');
    });
});

describe('the table', () => {

    test('a Total row first, then the rows, numbered', () => {
        const { $root } = draw(resultSet());
        const $rows = $root.find('tbody tr');

        expect($rows.first().hasClass('owa_gridTotal')).toBe(true);
        expect(texts($rows.first().children())).toEqual(['', 'Total', '100', '38.00%']);
        expect(texts($root.find('tr.owa_gridRow td.owa_gridRowNum'))).toEqual(['1', '2', '3']);
    });

    /*
     * Hiding the row numbers on a phone hides a whole column: the Total row
     * has its own cell under them rather than a Total spanning both.
     */
    test('the Total row has a cell of its own under the row numbers', () => {
        const { $root } = draw(resultSet({ dimensions: 'browserType,osName', rows: [
            { browserType: cell('browserType', 'dimension', 'Chrome'), osName: cell('osName', 'dimension', 'Linux'),
              sessions: cell('sessions', 'metric', 60), bounceRate: cell('bounceRate', 'metric', 0.4, '40.00%') },
        ] }));
        const $total = $root.find('tr.owa_gridTotal');

        expect($total.children().first().is('td.owa_gridRowNum')).toBe(true);
        expect($total.find('td.owa_gridDim').attr('colspan')).toBe('2');
        expect($root.find('thead th').length).toBe($total.children().toArray()
            .reduce((n, td) => n + Number(td.getAttribute('colspan') || 1), 0));
    });

    test('the first dimension column is the lead, in the heading, the Total and every row', () => {
        const { $root } = draw(resultSet());

        expect($root.find('thead th.owa_gridLead').attr('data-column')).toBe('browserType');
        expect($root.find('tr.owa_gridTotal td.owa_gridLead').text()).toBe('Total');
        expect(texts($root.find('tr.owa_gridRow td.owa_gridLead'))).toEqual(['Chrome', 'Firefox', 'Safari']);
    });

    test('the parts a test finds carry data-ui', () => {
        const { $root } = draw(resultSet());

        expect($root.find('[data-ui="grid"]').length).toBe(1);
        expect($root.find('[data-ui="grid-scroll"] > .owa_gridScroll > table').length).toBe(1);
        expect($root.find('[data-ui="grid-total"]').length).toBe(1);
        expect($root.find('[data-ui="grid-row"]').length).toBe(3);
    });

    test('row numbers continue from the page before', () => {
        const { $root } = draw(resultSet({ rs: { page: 3, resultsPerPage: 25, total_pages: 3, previous: 'x' } }));

        expect($root.find('tr.owa_gridRow td.owa_gridRowNum').first().text()).toBe('51');
    });

    test('every second row is shaded', () => {
        const { $root } = draw(resultSet());

        expect($root.find('tr.owa_gridRow').map((i, el) => $(el).hasClass('is-alt')).get()).toEqual([false, true, false]);
    });

    test('a count shows its share of the total, a ratio does not', () => {
        const { $root } = draw(resultSet());
        const first = $root.find('tr.owa_gridRow').first();

        expect(first.find('td[data-column="sessions"]').text()).toBe('60 (60.0%)');
        expect(first.find('td[data-column="bounceRate"]').text()).toBe('40.00%');
    });

    test('a rule after the last dimension column', () => {
        const { $root } = draw(resultSet());

        expect($root.find('thead th.owa_gridDimEnd').attr('data-column')).toBe('browserType');
        expect($root.find('tr.owa_gridRow').first().find('td.owa_gridDimEnd').attr('data-column')).toBe('browserType');
    });

    test('values are text, not markup', () => {
        const rs = resultSet({ rows: [
            { browserType: cell('browserType', 'dimension', '<img src=x onerror=y>'), sessions: cell('sessions', 'metric', 1), bounceRate: cell('bounceRate', 'metric', 0, '0%') },
        ] });
        const { $root } = draw(rs);

        expect($root.find('img').length).toBe(0);
        expect($root.find('td[data-column="browserType"]').text()).toBe('<img src=x onerror=y>');
    });

    /* A ratio over nothing is NULL on purpose; it read "null" on the dashboard. */
    test('a metric with no value is a dash, in its row and in the Total', () => {
        const none = { name: 'bounceRate', result_type: 'metric', value: null, formatted_value: null };
        const { $root } = draw(resultSet({
            rows: [{ browserType: cell('browserType', 'dimension', 'Chrome'), sessions: cell('sessions', 'metric', 60), bounceRate: none }],
            rs: { aggregates: { sessions: { name: 'sessions', value: 60, formatted_value: '60' }, bounceRate: none } },
        }));

        expect($root.find('tr.owa_gridRow td[data-column="bounceRate"]').text()).toBe('\u2014');
        expect($root.find('tr.owa_gridTotal td[data-column="bounceRate"]').text()).toBe('\u2014');
        expect($root.text()).not.toContain('null');
    });

    test('a column a report excludes is not drawn', () => {
        const { $root } = draw(resultSet(), { excludeColumns: ['bounceRate'] });

        expect($root.find('[data-column="bounceRate"]').length).toBe(0);
    });

    test('a card has no row numbers, no Total row and no shares', () => {
        const { $root } = draw(resultSet(), { compact: true, showExplorerControls: false });

        expect($root.find('.owa_gridRowNum, .owa_gridTotal, .owa_gridShare').length).toBe(0);
        expect($root.find('tr.owa_gridRow').first().find('td[data-column="sessions"]').text()).toBe('60');
    });

    test('a card draws each row\'s first metric as a bar against the largest', () => {
        const { $root } = draw(resultSet(), { compact: true, showExplorerControls: false });

        expect($root.find('.owa_gridTable').hasClass('is-compact')).toBe(true);
        expect($root.find('tr.owa_gridRow').length).toBe(3);
        expect($root.find('tr.owa_gridBarRow .owa_gridBarFill').map((i, el) => el.style.width).get())
            .toEqual(['100%', '50%', '16.7%']);
        expect($root.find('tr.owa_gridBarRow td').first().attr('colspan')).toBe('3');
    });

    test('a grid that is not a card has no bars', () => {
        const { $root } = draw(resultSet());

        expect($root.find('.owa_gridBarRow').length).toBe(0);
    });

    test('no rows, and no filters: the period has no data', () => {
        const { $root } = draw(resultSet({ rows: [] }));

        expect($root.find('.owa_gridEmpty').text()).toBe('No data for this period.');
    });

    /* Top Referrers is sessions whose medium is referral; the reader set no filter. */
    test('no rows, under only the widget\'s own filter: the period has no data', () => {
        const { $root } = draw(resultSet({ rows: [], constraints: 'medium==referral' }),
            { ownConstraints: 'medium==referral' });

        expect($root.find('.owa_gridEmpty').text()).toBe('No data for this period.');
    });

    test('no rows, under the widget\'s filter and the reader\'s: the filters emptied it', () => {
        const { $root } = draw(resultSet({ rows: [], constraints: 'browserType==Lynx,medium==referral' }),
            { ownConstraints: 'medium==referral' });

        expect($root.find('.owa_gridEmpty').text()).toBe('No data matches these filters.');
    });

    /* The filter is what emptied it, so its chip stays to be removed. */
    test('no rows, under a filter: the chip is still there', () => {
        const { $root } = draw(resultSet({ rows: [], constraints: 'browserType==Lynx' }));

        expect($root.find('.owa_gridEmpty').text()).toBe('No data matches these filters.');
        expect($root.find('.owa_gridChip').length).toBe(1);
        expect($root.find('.owa_gridPager').length).toBe(0);
    });
});

describe('the headings', () => {

    test('the sorted metric says so', () => {
        const { $root } = draw(resultSet());
        const $sessions = $root.find('thead th[data-column="sessions"]');

        expect($sessions.attr('aria-sort')).toBe('descending');
        expect($sessions.hasClass('is-sorted')).toBe(true);
    });

    test('a metric sorts largest first, and the sorted one turns round', () => {
        const { $root, events } = draw(resultSet());

        $root.find('thead th[data-column="bounceRate"] .owa_gridSort').trigger('click');
        $root.find('thead th[data-column="sessions"] [data-ui="grid-sort"]').trigger('click');

        expect(events).toEqual([
            ['sort_column_change', 'bounceRate', 'desc'],
            ['sort_column_change', 'sessions', 'asc'],
        ]);
    });

    test('one dimension: a picker, no remove, and a way to add another', () => {
        const { $root } = draw(resultSet());

        expect(texts($root.find('.owa_gridDimButton'))).toEqual(['Browser']);
        expect($root.find('.owa_gridDimRemove').length).toBe(0);
        expect($root.find('.owa_gridDimAdd').length).toBe(1);
    });

    test('two dimensions: the second can be removed, and none added', () => {
        const rs = resultSet({ dimensions: 'browserType,osName', rows: [
            { browserType: cell('browserType', 'dimension', 'Chrome'), osName: cell('osName', 'dimension', 'Linux'), sessions: cell('sessions', 'metric', 1), bounceRate: cell('bounceRate', 'metric', 0, '0%') },
        ] });
        const { $root, events } = draw(rs);

        expect(texts($root.find('.owa_gridDimButton'))).toEqual(['Browser', 'Operating System']);
        expect($root.find('.owa_gridDimAdd').length).toBe(0);

        $root.find('.owa_gridDimRemove').trigger('click');

        expect(events).toEqual([['dimension_list_change', ['browserType']]]);
    });

    /* A report grouped by more than the pickers hold keeps its dimensions. */
    test('three dimensions: the headings are plain', () => {
        const rs = resultSet({ dimensions: 'browserType,osName,medium', rows: [
            { browserType: cell('browserType', 'dimension', 'Chrome'), osName: cell('osName', 'dimension', 'Linux'), medium: cell('medium', 'dimension', 'direct'), sessions: cell('sessions', 'metric', 1), bounceRate: cell('bounceRate', 'metric', 0, '0%') },
        ] });
        const { $root } = draw(rs);

        expect($root.find('.owa_gridDimButton, .owa_gridDimAdd, .owa_gridDimRemove').length).toBe(0);
        expect(texts($root.find('thead th.owa_gridDim'))).toEqual(['Browser', 'Operating System', 'Medium']);
    });

    test('choosing a dimension replaces that heading\'s, and the excluded ones travel with it', () => {
        const rs = resultSet({ dimensions: 'browserType,medium' });
        const { $root, events } = draw(rs, { excludeColumns: ['medium'] });

        $root.find('.owa_gridDimButton').trigger('click');

        const $pop = $root.find('.owa_gridPop');

        expect($pop.length).toBe(1);
        expect($pop.find('.owa_gridPopOption.is-current').attr('data-name')).toBe('browserType');

        $pop.find('.owa_gridPopOption[data-name="osName"]').trigger('click');

        expect(events).toEqual([['dimension_list_change', ['osName', 'medium']]]);
        expect($root.find('.owa_gridPop').length).toBe(0);
    });

    test('adding a dimension appends it, and one in use cannot be picked twice', () => {
        const { $root, events } = draw(resultSet());

        $root.find('.owa_gridDimAdd').trigger('click');

        const $pop = $root.find('.owa_gridPop');

        expect($pop.find('.owa_gridPopOption[data-name="browserType"]').prop('disabled')).toBe(true);

        $pop.find('.owa_gridPopOption[data-name="medium"]').trigger('click');

        expect(events).toEqual([['dimension_list_change', ['browserType', 'medium']]]);
    });

    test('the search narrows the list', () => {
        const { $root } = draw(resultSet());

        $root.find('.owa_gridDimAdd').trigger('click');
        $root.find('.owa_gridPopSearch').val('oper').trigger('input');

        const shown = $root.find('.owa_gridPopList .owa_gridPopOption')
            .filter((i, el) => el.style.display !== 'none');

        expect(texts(shown)).toEqual(['Operating System']);
    });
});

/*
 * A metric heading with a description: its name opens the description
 * (owa.metricinfo.js), on hover with a mouse and on a click or a tap, and the
 * arrow after the name sorts. A heading without one is a sort button.
 */
describe('a heading\'s description', () => {

    beforeEach(() => jest.useFakeTimers());

    afterEach(() => {
        OWA.metricInfo.close();
        jest.useRealTimers();
    });

    const th = ($root, column) => $root.find(`thead th[data-column="${column}"]`);
    const name = ($root, column) => th($root, column).find('.owa_metricDescribed');
    const arrow = ($root, column) => th($root, column).find('[data-ui="grid-sort"]');
    const shown = () => $('[data-ui="metric-description"]');

    function pointer(el, type) {
        const e = new Event(type);
        Object.defineProperty(e, 'pointerType', { value: 'mouse' });
        el.dispatchEvent(e);
    }

    test('the name is a button with no tooltip; the arrow is named for sorting', () => {
        const { $root } = draw(resultSet());

        expect(name($root, 'sessions').is('button')).toBe(true);
        expect(name($root, 'sessions').text()).toBe('Sessions');
        expect(name($root, 'sessions').attr('aria-expanded')).toBe('false');
        expect(name($root, 'sessions').attr('title')).toBeUndefined();
        expect(arrow($root, 'sessions').attr('aria-label')).toBe('Sort by Sessions');

        // No description: the name sorts, as before.
        expect(name($root, 'bounceRate').length).toBe(0);
        expect(arrow($root, 'bounceRate').length).toBe(0);
        expect(th($root, 'bounceRate').find('.owa_gridSort').text()).toBe('Bounce Rate');
    });

    test('the arrow points the way the sort goes: down unsorted, the direction when sorted', () => {
        const { $root } = draw(resultSet({ rows: [
            { browserType: cell('browserType', 'dimension', 'Chrome'), sessions: cell('sessions', 'metric', 1), pageViews: cell('pageViews', 'metric', 2) },
        ], rs: { self: url({ dimensions: 'browserType', metrics: 'sessions,pageViews', page: 1 }), sortOrder: 'asc' } }));

        expect(arrow($root, 'sessions').find('.fa-arrow-up').length).toBe(1);
        expect(arrow($root, 'pageViews').find('.fa-arrow-down').length).toBe(1);
    });

    test('a click on the name shows the description and does not sort; the arrow sorts', () => {
        const { $root, events } = draw(resultSet());

        name($root, 'sessions').trigger('click');
        jest.runAllTimers();

        const pop = document.getElementById(name($root, 'sessions').attr('aria-controls'));

        expect(name($root, 'sessions').attr('aria-expanded')).toBe('true');
        expect(pop.textContent).toBe('The number of sessions.');
        expect(pop.getAttribute('role')).toBe('status');
        expect(events).toEqual([]);

        // The heading is active while it is open: a touch screen shows the arrow.
        expect(th($root, 'sessions').hasClass('is-describing')).toBe(true);

        // A press on the arrow is in the heading, so it does not close it first...
        arrow($root, 'sessions')[0].dispatchEvent(new Event('pointerdown', { bubbles: true }));
        expect(name($root, 'sessions').attr('aria-expanded')).toBe('true');

        // ...and the arrow sorts, closing it.
        arrow($root, 'sessions').trigger('click');
        expect(events).toEqual([['sort_column_change', 'sessions', 'asc']]);
        expect(shown().length).toBe(0);
        expect(th($root, 'sessions').hasClass('is-describing')).toBe(false);
    });

    test('the name again closes it', () => {
        const { $root } = draw(resultSet());

        name($root, 'sessions').trigger('click');
        name($root, 'sessions').trigger('click');

        expect(name($root, 'sessions').attr('aria-expanded')).toBe('false');
        expect(name($root, 'sessions').attr('aria-controls')).toBeUndefined();
        expect(shown().length).toBe(0);
    });

    test('a mouse resting on the name opens it, and leaving closes it after a moment', () => {
        const { $root } = draw(resultSet());
        const el = name($root, 'sessions')[0];

        pointer(el, 'pointerenter');
        expect(shown().length).toBe(0);
        jest.advanceTimersByTime(150);
        expect(name($root, 'sessions').attr('aria-expanded')).toBe('true');

        // Into the description: it stays.
        pointer(el, 'pointerleave');
        pointer(shown()[0], 'pointerenter');
        jest.advanceTimersByTime(500);
        expect(shown().length).toBe(1);

        pointer(shown()[0], 'pointerleave');
        jest.advanceTimersByTime(250);
        expect(shown().length).toBe(0);
        expect(name($root, 'sessions').attr('aria-expanded')).toBe('false');
    });

    test('a click while hovering keeps it open after the mouse leaves', () => {
        const { $root } = draw(resultSet());
        const el = name($root, 'sessions')[0];

        pointer(el, 'pointerenter');
        jest.advanceTimersByTime(150);
        name($root, 'sessions').trigger('click');
        pointer(el, 'pointerleave');
        jest.advanceTimersByTime(1000);

        expect(name($root, 'sessions').attr('aria-expanded')).toBe('true');
    });

    test('a touch does not open it on its own: only the tap does', () => {
        const { $root } = draw(resultSet());
        const e = new Event('pointerenter');
        Object.defineProperty(e, 'pointerType', { value: 'touch' });

        name($root, 'sessions')[0].dispatchEvent(e);
        jest.advanceTimersByTime(500);

        expect(shown().length).toBe(0);
    });

    test('one is open at a time', () => {
        const { $root } = draw(resultSet({ rows: [
            { browserType: cell('browserType', 'dimension', 'Chrome'), sessions: cell('sessions', 'metric', 1), pageViews: cell('pageViews', 'metric', 2) },
        ], rs: { self: url({ dimensions: 'browserType', metrics: 'sessions,pageViews', page: 1 }) } }));

        name($root, 'sessions').trigger('click');
        name($root, 'pageViews').trigger('click');
        jest.runAllTimers();

        expect(name($root, 'sessions').attr('aria-expanded')).toBe('false');
        expect(th($root, 'sessions').hasClass('is-describing')).toBe(false);
        expect(name($root, 'pageViews').attr('aria-expanded')).toBe('true');
        expect(shown().length).toBe(1);
        expect(shown().text()).toBe('Pages viewed.');
    });

    test('Escape closes it and puts focus back on the name', () => {
        const { $root } = draw(resultSet());

        name($root, 'sessions').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(name($root, 'sessions').attr('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(name($root, 'sessions')[0]);
    });

    test('a press anywhere else closes it; a press on it does not', () => {
        const { $root } = draw(resultSet());

        name($root, 'sessions').trigger('click');
        jest.runAllTimers();

        shown()[0].dispatchEvent(new Event('pointerdown', { bubbles: true }));
        expect(name($root, 'sessions').attr('aria-expanded')).toBe('true');

        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        expect(name($root, 'sessions').attr('aria-expanded')).toBe('false');
    });
});

describe('where a description opens', () => {

    const phone = { innerWidth: 375, innerHeight: 667 };

    test('under its button, kept 8px inside a phone', () => {
        expect(OWA.metricInfo.place({ left: 300, top: 100, bottom: 120 }, 280, 60, phone))
            .toEqual({ left: 87, top: 124, bottom: null });
        expect(OWA.metricInfo.place({ left: 2, top: 100, bottom: 120 }, 280, 60, phone).left).toBe(8);
    });

    test('above its button when there is more room there', () => {
        expect(OWA.metricInfo.place({ left: 20, top: 600, bottom: 620 }, 280, 100, phone))
            .toEqual({ left: 20, top: null, bottom: 71 });
    });
});

describe('the bar', () => {

    test('a chip per filter, in words', () => {
        const { $root } = draw(resultSet({ constraints: 'browserType=@Chro,sessions>5,medium==(not set)' }));

        expect(texts($root.find('.owa_gridChipText'))).toEqual([
            'Browser contains Chro', 'Sessions greater than 5', 'Medium is not set',
        ]);
        expect($root.find('.owa_gridChipText strong').first().text()).toBe('Chro');
    });

    test('removing a chip publishes the rest', () => {
        const { $root, events } = draw(resultSet({ constraints: 'browserType=@Chro,sessions>5' }));

        $root.find('.owa_gridChipRemove').first().trigger('click');

        expect(events).toEqual([['constraint_change', 'sessions>5']]);
    });

    test('adding a filter appends it to those there are', () => {
        const { $root, events } = draw(resultSet({ constraints: 'sessions>5' }));

        $root.find('.owa_gridFilterButton').trigger('click');

        const $pop = $root.find('.owa_gridPop');

        expect($pop.find('.owa_gridFilterField').val()).toBe('browserType');

        $pop.find('input[type="radio"][value="=="]').prop('checked', true).trigger('change');
        $pop.find('.owa_gridFilterValue').val('Firefox').trigger('input');
        $pop.find('form').trigger('submit');

        expect(events).toEqual([['constraint_change', 'sessions>5,browserType==Firefox']]);
    });

    test('a metric is filtered by greater or less than', () => {
        const { $root } = draw(resultSet());

        $root.find('.owa_gridFilterButton').trigger('click');
        $root.find('.owa_gridFilterField').val('sessions').trigger('change');

        expect($root.find('.owa_gridFilterOps input').map((i, el) => el.value).get()).toEqual(['>', '<', '==']);
    });

    test('an empty test needs no value, and a comma is refused', () => {
        const { $root, events } = draw(resultSet());

        $root.find('.owa_gridFilterButton').trigger('click');

        const $apply = $root.find('.owa_gridPrimary');

        expect($apply.prop('disabled')).toBe(true);

        $root.find('.owa_gridFilterValue').val('a,b').trigger('input');
        expect($apply.prop('disabled')).toBe(true);
        expect($root.find('.owa_gridPopHint').text()).toBe('A value cannot contain a comma.');

        $root.find('input[type="radio"][value="==(not set)"]').prop('checked', true).trigger('change');
        expect($apply.prop('disabled')).toBe(false);

        $root.find('form').trigger('submit');
        expect(events).toEqual([['constraint_change', 'browserType==(not set)']]);
    });

    test('the pager names the rows, and Rows per page starts again at page one', () => {
        const rs = resultSet({ rs: { page: 2, resultsPerPage: 25, resultsReturned: 3, resultsTotal: 3, total_pages: 2, previous: 'p' } });
        const { $root, events } = draw(rs);

        expect($root.find('.owa_gridRange').text()).toBe('26–28 of 28');
        expect($root.find('.owa_gridPrev').prop('disabled')).toBe(false);
        expect($root.find('.owa_gridNext').prop('disabled')).toBe(true);

        $root.find('.owa_gridPrev').trigger('click');
        $root.find('.owa_gridPageSize').val('50').trigger('change');

        expect(events).toEqual([['page_back'], ['page_change', 1, 50]]);
    });

    test('Go to is held to the pages there are', () => {
        const { $root, events } = draw(resultSet({ rs: { resultsPerPage: 25, total_pages: 4, next: 'n' } }));

        $root.find('.owa_gridGoTo').val('9').trigger('change');

        expect(events).toEqual([['page_change', 4, 25]]);
    });

    test('a page size of its own is offered alongside the usual ones', () => {
        const { $root } = draw(resultSet({ rs: { resultsPerPage: 30 } }));

        expect($root.find('.owa_gridPageSize option').map((i, el) => el.value).get()).toEqual(['10', '25', '30', '50', '100']);
        expect($root.find('.owa_gridPageSize').val()).toBe('30');
    });

    test('without explorer controls: no filter, no pickers, and no bar for one page', () => {
        const { $root } = draw(resultSet(), { showExplorerControls: false });

        expect($root.find('.owa_gridControls').length).toBe(0);
        expect($root.find('.owa_gridDimButton').length).toBe(0);
        expect($root.find('thead th[data-column="browserType"] .owa_gridSort').length).toBe(1);
    });

    test('without explorer controls, more than one page still pages', () => {
        const { $root } = draw(resultSet({ rs: { total_pages: 2, next: 'n' } }), { showExplorerControls: false });

        expect($root.find('.owa_gridFilterButton').length).toBe(0);
        expect($root.find('.owa_gridPageSize').length).toBe(0);
        expect($root.find('.owa_gridPager').length).toBe(1);
    });
});

/**
 * A card has no pager. A bar under its rows asks for the same query again from
 * the first row with as many more rows as the card first showed.
 */
describe('a card\'s Show more', () => {

    const CARD = { compact: true, showExplorerControls: false };

    function rows(count) {
        return Array.from({ length: count }, (_, i) => ({
            browserType: cell('browserType', 'dimension', 'Browser ' + (i + 1)),
            sessions: cell('sessions', 'metric', 100 - i),
        }));
    }

    // Page one of a card: `shown` rows of `total`, as the server counts them.
    function card(shown, total, extra) {
        return resultSet({
            rows: rows(Math.min(shown, total)),
            constraints: 'medium==referral',
            rs: Object.assign({
                resultsPerPage: shown,
                resultsReturned: Math.min(shown, total),
                resultsTotal: total,
                total_pages: Math.ceil(total / shown),
                next: total > shown ? 'n' : undefined,
            }, extra || {}),
        });
    }

    const bar = ($root) => $root.find('[data-ui="grid-show-more"]');

    test('a card with more rows has the bar, and no pager or range', () => {
        const { $root } = draw(card(10, 23), CARD);

        expect($root.find('.owa_gridPager, .owa_gridPrev, .owa_gridNext, .owa_gridRange, .owa_gridPageSize').length).toBe(0);
        expect(bar($root).length).toBe(1);
        expect(bar($root).is('button[type="button"]')).toBe(true);
        expect(bar($root).attr('aria-label')).toBe('Show 10 more rows');
        expect(bar($root).find('.fa-chevron-down').attr('aria-hidden')).toBe('true');
    });

    test('the bar is under the rows', () => {
        const { $root } = draw(card(10, 23), CARD);

        expect($root.find('.owa_grid').children().last().is('[data-ui="grid-show-more"]')).toBe(true);
    });

    test('the bar names the rows that are left when fewer than a step are', () => {
        const { $root } = draw(card(10, 11), CARD);

        expect(bar($root).attr('aria-label')).toBe('Show 1 more row');
    });

    /* Past ten pages the total is a lower bound, so a whole step is coming. */
    test('a total that is a lower bound still offers a whole step', () => {
        const { $root } = draw(card(10, 100), CARD);

        expect(bar($root).attr('aria-label')).toBe('Show 10 more rows');
    });

    test('a card with every row shown has no bar', () => {
        const { $root } = draw(card(10, 7), CARD);

        expect(bar($root).length).toBe(0);
        expect($root.find('[data-ui="grid-all-shown"]').length).toBe(0);
    });

    test('pressing it asks for the rows shown and a step more', () => {
        const { $root, events } = draw(card(10, 23), CARD);

        bar($root).trigger('click');

        expect(events).toEqual([['show_more', 20]]);
    });

    test('the request stops at the most rows the server returns', () => {
        OWA.setSetting('max_results_per_page', 25);

        try {
            const { $root, events } = draw(card(20, 100), CARD);

            expect(bar($root).attr('aria-label')).toBe('Show 5 more rows');
            bar($root).trigger('click');
            expect(events).toEqual([['show_more', 25]]);
        } finally {
            OWA.setSetting('max_results_per_page', undefined);
        }
    });

    test('while the rows load the bar is busy and a second press does nothing', () => {
        const { $root, events } = draw(card(10, 23), CARD);

        bar($root).trigger('click');
        bar($root).trigger('click');

        expect(bar($root).attr('aria-busy')).toBe('true');
        expect(bar($root).attr('aria-disabled')).toBe('true');
        expect(events).toEqual([['show_more', 20]]);
    });

    test('a request that fails leaves the bar to press again', () => {
        const { $root, events } = draw(card(10, 23), CARD);

        bar($root).trigger('click');
        $root.trigger('result_set_error');

        expect(bar($root).attr('aria-busy')).toBeUndefined();
        expect(bar($root).attr('aria-disabled')).toBeUndefined();

        bar($root).trigger('click');
        expect(events).toEqual([['show_more', 20], ['show_more', 20]]);
    });

    test('the rows arrive: the step stays the first page size, the bar keeps the focus, and the status says how many', () => {
        const { $root, events } = draw(card(10, 23), CARD);

        expect($root.children('[data-ui="grid-status"]').attr('aria-live')).toBe('polite');
        expect($root.children('[data-ui="grid-status"]').text()).toBe('');

        bar($root).trigger('click');
        $root.trigger('new_result_set', [card(20, 23)]);

        expect($root.find('tr.owa_gridRow').length).toBe(20);
        expect(bar($root).attr('aria-label')).toBe('Show 3 more rows');
        expect(bar($root).attr('aria-busy')).toBeUndefined();
        expect(document.activeElement).toBe(bar($root)[0]);
        expect($root.children('[data-ui="grid-status"]').text()).toBe('Showing 20 of 23 rows');

        bar($root).trigger('click');
        expect(events).toEqual([['show_more', 20], ['show_more', 23]]);
    });

    test('the last rows arrive: the bar goes, the status says so and the grid takes the focus', () => {
        const { $root } = draw(card(10, 23), CARD);

        bar($root).trigger('click');
        $root.trigger('new_result_set', [card(23, 23)]);

        expect(bar($root).length).toBe(0);
        expect($root.find('.owa_gridShowMoreDone').length).toBe(0);
        expect($root.children('[data-ui="grid-status"]').text()).toBe('Showing 23 of 23 rows');
        expect(document.activeElement).toBe($root.find('.owa_grid')[0]);
    });

    test('with a full-report link at the card foot, the focus goes to it', () => {
        const $card = $('<div class="card"><div class="body"></div>'
            + '<p><a href="#full" data-ui="card-full-report">Full report</a></p></div>').appendTo(document.body);
        const { $root } = draw(card(10, 23), CARD, $card.find('.body'));

        bar($root).trigger('click');
        $root.trigger('new_result_set', [card(23, 23)]);

        expect(document.activeElement).toBe($card.find('[data-ui="card-full-report"]')[0]);
    });

    test('a redraw that was not asked for by the bar moves no focus', () => {
        const { $root } = draw(card(10, 23), CARD);

        $root.trigger('new_result_set', [card(10, 23, { sortColumn: 'browserType', sortOrder: 'asc' })]);

        expect(bar($root).length).toBe(1);
        expect(document.activeElement).toBe(document.body);
    });

    test('a grid that is not a card keeps its pager and has no bar', () => {
        const { $root } = draw(card(10, 23));

        expect(bar($root).length).toBe(0);
        expect($root.find('.owa_gridPager').length).toBe(1);
        expect($root.find('.owa_gridRange').text()).toBe('1–10 of 23');
    });

    test('the names are translated, in the form the count takes', () => {
        OWA.config.strings = {
            'Show %s more row': ['Afficher %s ligne de plus', 'Afficher %s lignes de plus'],
        };
        OWA.config.plural_rule = 'french';

        try {
            expect(bar(draw(card(10, 23), CARD).$root).attr('aria-label')).toBe('Afficher 10 lignes de plus');
            expect(bar(draw(card(10, 11), CARD).$root).attr('aria-label')).toBe('Afficher 1 ligne de plus');
        } finally {
            delete OWA.config.strings;
            delete OWA.config.plural_rule;
        }
    });
});

/**
 * The explorer behind a card: a press of Show more requeries the result set's
 * own URL, so the sort, the filters and the period travel.
 */
describe('a card\'s explorer', () => {

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('asks for page one with the rows shown and a step more, and keeps the rest of the query', () => {
        document.body.innerHTML = '<div id="card"></div>';
        OWA.setSetting('baseUrl', 'https://owa.test/');

        const asked = [];

        jest.spyOn($, 'getJSON').mockImplementation((u, d, done) => {
            asked.push(u);
            const q = new OWA.uri(u);
            const per = parseInt(q.getQueryParam('resultsPerPage'), 10);
            const total = 23;
            const shown = Math.min(per, total);
            done({
                guid: 'g' + asked.length,
                self: u,
                resultsRows: Array.from({ length: shown }, (_, i) => ({
                    browserType: cell('browserType', 'dimension', 'Browser ' + i),
                    sessions: cell('sessions', 'metric', 100 - i),
                })),
                resultsPerPage: per, resultsReturned: shown, resultsTotal: total, page: 1,
                total_pages: Math.ceil(total / per), sortColumn: 'sessions', sortOrder: 'desc',
            });
            return $.Deferred().resolve().promise();
        });

        const explorer = new OWA.resultSetExplorer('card');
        explorer.options.grid.compact = true;
        explorer.options.grid.showExplorerControls = false;
        explorer.asyncQueue.push(['refreshGrid']);
        explorer.load(url({
            dimensions: 'browserType', metrics: 'sessions', sort: 'sessions-',
            constraints: 'medium==referral', period: 'last_seven_days', page: 3, resultsPerPage: 10,
        }));

        $('#card [data-ui="grid-show-more"]').trigger('click');

        const q = new OWA.uri(asked[1]);

        expect(q.getQueryParam('page')).toBe('1');
        expect(q.getQueryParam('resultsPerPage')).toBe('20');
        expect(decodeURIComponent(q.getQueryParam('sort'))).toBe('sessions-');
        expect(decodeURIComponent(q.getQueryParam('constraints'))).toBe('medium==referral');
        expect(q.getQueryParam('period')).toBe('last_seven_days');
        expect($('#card tr.owa_gridRow').length).toBe(20);
        expect($('#card [data-ui="grid-show-more"]').attr('aria-label')).toBe('Show 3 more rows');
    });
});

describe('popovers', () => {

    test('one at a time, and Escape closes it', () => {
        const { $root } = draw(resultSet());

        $root.find('.owa_gridFilterButton').trigger('click');
        $root.find('.owa_gridDimAdd').trigger('click');

        expect($root.find('.owa_gridPop').length).toBe(1);
        expect($root.find('.owa_gridDimAdd').attr('aria-expanded')).toBe('true');
        expect($root.find('.owa_gridFilterButton').attr('aria-expanded')).toBe('false');

        $root.find('.owa_gridPop')[0].dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Escape' }));

        expect($root.find('.owa_gridPop').length).toBe(0);
        expect($root.find('.owa_gridDimAdd').attr('aria-expanded')).toBe('false');
    });

    test('a refetch redraws the grid and closes an open popover', () => {
        const { $root } = draw(resultSet());

        $root.find('.owa_gridFilterButton').trigger('click');
        $root.trigger('new_result_set', [resultSet({ rs: { sortColumn: 'bounceRate' } })]);

        expect($root.find('.owa_gridPop').length).toBe(0);
        expect($root.find('.owa_grid').length).toBe(1);
        expect($root.find('thead th.is-sorted').attr('data-column')).toBe('bounceRate');
    });
});

/**
 * A linked column whose FIRST row has no link.
 *
 * A link's except leaves some values unlinked, and on Page Detail's Next Pages
 * card (exit) is usually the top row. Whether the column links was read off the
 * first row alone, so no row linked; and a row without a link drew an empty
 * cell once the column did.
 */
describe('a link column with an unlinked first row', () => {

    test('links the rows that have a link and prints the rest', () => {
        const rows = [
            { browserType: cell('browserType', 'dimension', '(exit)'), sessions: cell('sessions', 'metric', 2), bounceRate: cell('bounceRate', 'metric', 0, '0.00%') },
            { browserType: cell('browserType', 'dimension', '/docs', '/docs', { link: '?pagePath=%2Fdocs' }), sessions: cell('sessions', 'metric', 1), bounceRate: cell('bounceRate', 'metric', 0, '0.00%') },
        ];

        const { $root } = draw(resultSet({ rows }));
        const $cells = $root.find('tbody tr.owa_gridRow td[data-column="browserType"]');

        expect(texts($cells)).toEqual(['(exit)', '/docs']);
        expect($cells.eq(0).find('a')).toHaveLength(0);
        expect($cells.eq(1).find('a').attr('href')).toBe('?pagePath=%2Fdocs');
    });
});

/**
 * A row's actions: "Actions" and a chevron beside its first value, opening a
 * menu of links. An action needing the grid grouped by a dimension first --
 * the heatmap, of one exact page -- is left off when it is not.
 */
describe('row actions', () => {

    const ACTIONS = [
        { id: 'heatmap', label: 'Heatmap', href: '?do=base.overlayLauncher&pagePathPlusQuery=__OWA_ROW_pagePathPlusQuery__',
          params: { pagePathPlusQuery: 'pagePathPlusQuery' }, first: 'pagePathPlusQuery', newWindow: true },
        { id: 'clicks', label: 'Element clicks', href: '?do=base.report&reportId=dom-clicks&pagePath=__OWA_ROW_pagePath__',
          params: { pagePath: 'pagePath' }, first: '', newWindow: false },
    ];

    function pages(dim, values) {
        return resultSet({
            dimensions: dim,
            rows: values.map((v) => ({
                [dim]: cell(dim, 'dimension', v),
                sessions: cell('sessions', 'metric', 1),
                bounceRate: cell('bounceRate', 'metric', 0, '0.00%'),
            })),
        });
    }

    function menuOf($root, i) {
        $root.find('tbody tr.owa_gridRow').eq(i).find('.owa_gridRowActions').trigger('click');
        return $('.owa_gridRowActionsMenu a').map((k, a) => ({
            id: $(a).attr('data-action'), href: $(a).attr('href'), target: $(a).attr('target') || '',
        })).get();
    }

    test('a page and its query string offers its heatmap, and its path to the rest', () => {
        const { $root } = draw(pages('pagePathPlusQuery', ['/?s=a b&c=1']), { rowActions: ACTIONS });

        const $button = $root.find('tbody tr.owa_gridRow').eq(0).find('td.owa_gridDim').first().find('.owa_gridRowActions');
        expect($button.text().trim()).toBe('Actions');
        expect($button.find('.fa-chevron-down')).toHaveLength(1);

        expect(menuOf($root, 0)).toEqual([
            { id: 'heatmap', href: '?do=base.overlayLauncher&pagePathPlusQuery=' + encodeURIComponent('/?s=a b&c=1'), target: '_blank' },
            { id: 'clicks', href: '?do=base.report&reportId=dom-clicks&pagePath=%2F', target: '' },
        ]);
    });

    test('a path alone offers no heatmap: it would pool every query string', () => {
        const { $root } = draw(pages('pagePath', ['/pricing']), { rowActions: ACTIONS });

        expect(menuOf($root, 0).map((a) => a.id)).toEqual(['clicks']);
    });

    test('the arrow keys, Home and End move through the menu; Escape closes it and returns to its button', () => {
        const actions = ACTIONS.concat([{ id: 'paths', label: 'Previous and next pages',
            href: '?do=base.report&reportId=document&pagePath=__OWA_ROW_pagePath__', params: { pagePath: 'pagePath' }, first: '' }]);
        const { $root } = draw(pages('pagePathPlusQuery', ['/']), { rowActions: actions });
        const $button = $root.find('.owa_gridRowActions').first();

        $button.trigger('click');

        const $items = $('.owa_gridRowActionsMenu [role="menuitem"]');
        const focused = () => $items.index(document.activeElement);
        const key = (k) => $(document.activeElement).trigger($.Event('keydown', { key: k }));

        expect($items).toHaveLength(3);
        expect(focused()).toBe(0);

        key('ArrowDown');
        expect(focused()).toBe(1);
        key('ArrowDown');
        key('ArrowDown');
        expect(focused()).toBe(0);
        key('ArrowUp');
        expect(focused()).toBe(2);
        key('Home');
        expect(focused()).toBe(0);
        key('End');
        expect(focused()).toBe(2);
        key('a');
        expect(focused()).toBe(2);

        $items.get(2).dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect($('.owa_gridRowActionsMenu')).toHaveLength(0);
        expect(document.activeElement).toBe($button[0]);
        expect($button.attr('aria-expanded')).toBe('false');
    });

    test('a grid with no actions draws no button', () => {
        const { $root } = draw(pages('pagePathPlusQuery', ['/']));

        expect($root.find('.owa_gridRowActions')).toHaveLength(0);
    });
});

describe('a grid compared to another period', () => {

    const compare = { compare: 'previous_period', startDate: '20260915', endDate: '20261012' };

    function compared() {
        const c = (name, value, formatted, cv, cf, change) =>
            cell(name, 'metric', value, formatted, { compare_value: cv, compare_formatted_value: cf, change });

        return resultSet({
            rows: [
                { browserType: cell('browserType', 'dimension', 'Chrome'), sessions: c('sessions', 60, '60', 50, '50', 0.2), bounceRate: c('bounceRate', 0.4, '40.00%', 0.5, '50.00%', -0.2) },
                { browserType: cell('browserType', 'dimension', 'Edge'), sessions: c('sessions', 40, '40', null, null, null), bounceRate: c('bounceRate', 0.3, '30.00%', null, null, null) },
            ],
            rs: {
                compare,
                aggregates: {
                    sessions: { name: 'sessions', value: 100, formatted_value: '100', compare_value: 80, compare_formatted_value: '80', change: 0.25 },
                    bounceRate: { name: 'bounceRate', value: 0.35, formatted_value: '35.00%', compare_value: 0.5, compare_formatted_value: '50.00%', change: -0.3 },
                },
            },
        });
    }

    const changeIn = ($root, row, column) => $root.find('tbody tr').eq(row).find('td[data-column="' + column + '"] .owa_gridChange');

    test('each metric cell shows its change under the value, the old value in its title', () => {
        const { $root } = draw(compared());

        // Row 0 is the total; the rows follow.
        const up = changeIn($root, 1, 'sessions');
        expect(up.text()).toBe('▲ 20.0%');
        expect(up.hasClass('is-up')).toBe(true);
        expect(up.attr('title')).toMatch(/Sep 15.*Oct 12, 2026: 50$/);

        expect(changeIn($root, 1, 'bounceRate').text()).toBe('▼ 20.0%');
        expect(changeIn($root, 1, 'bounceRate').hasClass('is-down')).toBe(true);
    });

    test('a row the compared period did not have shows no percentage', () => {
        const { $root } = draw(compared());

        expect(changeIn($root, 2, 'sessions').text()).toBe('—');
        expect(changeIn($root, 2, 'sessions').attr('title')).toMatch(/no data$/);
    });

    test('the total row is compared too', () => {
        const { $root } = draw(compared());

        expect($root.find('tr.owa_gridTotal td[data-column="sessions"] .owa_gridChange').text()).toBe('▲ 25.0%');
    });

    test('a dimension cell has no change, and an uncompared grid none at all', () => {
        const { $root } = draw(compared());
        expect($root.find('td[data-column="browserType"] .owa_gridChange')).toHaveLength(0);

        expect(draw(resultSet()).$root.find('.owa_gridChange')).toHaveLength(0);
    });
});

/**
 * The grid's sentences are whole strings with placeholders (OWA.l), so a
 * translation can order the words its own way.
 */
describe('in a translated locale', () => {

    afterEach(() => {
        delete OWA.config.strings;
    });

    test('a chip is one sentence, with the value in bold where the translation puts it', () => {
        OWA.config.strings = {
            'filter chip: field, condition, value\x04%1$s %2$s %3$s': '%3$s : %1$s (%2$s)',
            'filter chip: field, condition\x04%1$s %2$s': '%1$s — %2$s',
            'contains': 'contient',
        };

        const { $root } = draw(resultSet({ constraints: 'browserType=@Chro,medium==(not set)' }));

        expect(texts($root.find('.owa_gridChipText'))).toEqual(['Chro : Browser (contient)', 'Medium — is not set']);
        expect($root.find('.owa_gridChipText strong').first().text()).toBe('Chro');
    });

    test('Rows per page and Go to are one sentence each, with the control where the translation puts it', () => {
        const paged = () => draw(resultSet({ rs: { resultsPerPage: 25, total_pages: 4, next: 'n' } })).$root;

        let $labels = paged().find('label.owa_gridPagingItem');

        expect($labels.eq(0).text().startsWith('Rows per page ')).toBe(true);
        expect($labels.eq(0).children('select.owa_gridPageSize')).toHaveLength(1);
        expect($labels.eq(1).contents().first().text()).toBe('Go to ');
        expect($labels.eq(1).children('input.owa_gridGoTo')).toHaveLength(1);

        OWA.config.strings = {
            'grid label: %s is the page size menu\x04Rows per page %s': '%s lignes par page',
            'grid label: %s is the page number field\x04Go to %s': 'Aller à la page %s',
        };

        $labels = paged().find('label.owa_gridPagingItem');

        const parts = ($label) => $label.contents().map((i, n) => (n.nodeType === 3 ? n.textContent : '<' + n.tagName.toLowerCase() + '>')).get();

        expect(parts($labels.eq(0))).toEqual(['<select>', ' lignes par page']);
        expect(parts($labels.eq(1))).toEqual(['Aller à la page ', '<input>']);
    });

    test('the pager reads its range, and a range that may go on, from one string each', () => {
        OWA.config.strings = {
            '%1$s–%2$s of %3$s': '%1$s à %2$s sur %3$s',
            '%1$s–%2$s of %3$s+': '%1$s à %2$s sur plus de %3$s',
        };

        const paged = resultSet({ rs: { page: 2, resultsPerPage: 25, resultsReturned: 3, resultsTotal: 3, total_pages: 2, previous: 'p' } });

        expect(draw(paged).$root.find('.owa_gridRange').text()).toBe('26 à 28 sur 28');

        expect(OWA.util.sprintf(OWA.l('%1$s–%2$s of %3$s+'), 1, 10, 100)).toBe('1 à 10 sur plus de 100');
    });

    test('the remove and row-action buttons are named with the value in the sentence', () => {
        const rs = resultSet({ dimensions: 'browserType,osName', rows: [
            { browserType: cell('browserType', 'dimension', 'Chrome'), osName: cell('osName', 'dimension', 'Linux'), sessions: cell('sessions', 'metric', 1), bounceRate: cell('bounceRate', 'metric', 0, '0%') },
        ] });
        const actions = [{ id: 'clicks', label: 'Element clicks', href: '?x=__OWA_ROW_browserType__', params: { browserType: 'browserType' }, first: '' }];

        expect(draw(rs, { rowActions: actions }).$root.find('.owa_gridDimRemove').attr('aria-label')).toBe('Remove Operating System');

        OWA.config.strings = { 'Remove %s': 'Retirer %s', 'Actions for %s': 'Actions pour %s', 'Element clicks': 'Clics' };

        const { $root } = draw(rs, { rowActions: actions });

        expect($root.find('.owa_gridDimRemove').attr('aria-label')).toBe('Retirer Operating System');
        expect($root.find('.owa_gridRowActions').first().attr('aria-label')).toBe('Actions pour Chrome');

        $root.find('.owa_gridRowActions').first().trigger('click');
        expect($('.owa_gridRowActionsMenu a').last().text()).toBe('Clics');
    });
});

/*
 * A table wider than its box shows a shadow on each side it runs past, from
 * the scroll position: none when it fits, right at the start, both in the
 * middle, left at the end.
 */
describe('the scroll cue', () => {

    function sized(box, { scrollWidth, clientWidth, scrollLeft }) {
        Object.defineProperty(box, 'scrollWidth', { configurable: true, value: scrollWidth });
        Object.defineProperty(box, 'clientWidth', { configurable: true, value: clientWidth });
        box.scrollLeft = scrollLeft;
    }

    const sides = (frame) => ['has-more-left', 'has-more-right'].filter((c) => frame.classList.contains(c));

    test.each([
        ['a table that fits', 300, 0, []],
        ['at the start', 600, 0, ['has-more-right']],
        ['in the middle', 600, 150, ['has-more-left', 'has-more-right']],
        ['at the end', 600, 300, ['has-more-left']],
    ])('%s', (name, scrollWidth, scrollLeft, expected) => {
        const { $root } = draw(resultSet());
        const frame = $root.find('.owa_gridScrollFrame')[0];
        const box = frame.querySelector('.owa_gridScroll');

        sized(box, { scrollWidth, clientWidth: 300, scrollLeft });
        OWA.dataGrid.scrollCue(frame);

        expect(sides(frame)).toEqual(expected);
    });

    test('scrolling the table moves the cue', () => {
        const { $root } = draw(resultSet());
        const frame = $root.find('.owa_gridScrollFrame')[0];
        const box = frame.querySelector('.owa_gridScroll');

        sized(box, { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 });
        OWA.dataGrid.scrollCue(frame);
        expect(sides(frame)).toEqual(['has-more-right']);

        box.scrollLeft = 300;
        $(box).trigger('scroll');
        expect(sides(frame)).toEqual(['has-more-left']);
    });

    test('the left shadow starts after a sticky lead column', () => {
        const { $root } = draw(resultSet());
        const frame = $root.find('.owa_gridScrollFrame')[0];
        const lead = frame.querySelector('thead .owa_gridLead');

        lead.style.position = 'sticky';
        Object.defineProperty(lead, 'offsetWidth', { configurable: true, value: 120 });
        OWA.dataGrid.scrollCue(frame);

        expect(frame.style.getPropertyValue('--owa-grid-lead')).toBe('120px');

        lead.style.position = '';
        OWA.dataGrid.scrollCue(frame);

        expect(frame.style.getPropertyValue('--owa-grid-lead')).toBe('0px');
    });
});
