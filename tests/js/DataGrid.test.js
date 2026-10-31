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

function draw(rs, options) {
    const id = 'grid' + (++n);
    $('body').append('<div id="' + id + '"></div>');
    const grid = new OWA.dataGrid(id, Object.assign({ showRowNumbers: true, excludeColumns: [], columnFormatters: {}, showExplorerControls: true }, options || {}));
    const events = [];
    ['page_forward', 'page_back', 'page_change', 'dimension_list_change', 'sort_column_change', 'constraint_change']
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
        expect(texts($rows.first().children())).toEqual(['Total', '100', '38.00%']);
        expect(texts($root.find('tr.owa_gridRow td.owa_gridRowNum'))).toEqual(['1', '2', '3']);
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

    test('the sorted metric says so, and a description is its tooltip', () => {
        const { $root } = draw(resultSet());
        const $sessions = $root.find('thead th[data-column="sessions"]');

        expect($sessions.attr('aria-sort')).toBe('descending');
        expect($sessions.hasClass('is-sorted')).toBe(true);
        expect($sessions.find('.owa_gridSort').attr('title')).toBe('The number of sessions.');
        expect($sessions.find('.owa_gridSortLabel').hasClass('has-description')).toBe(true);
        expect($root.find('thead th[data-column="bounceRate"] .owa_gridSortLabel').hasClass('has-description')).toBe(false);
    });

    test('a metric sorts largest first, and the sorted one turns round', () => {
        const { $root, events } = draw(resultSet());

        $root.find('thead th[data-column="bounceRate"] .owa_gridSort').trigger('click');
        $root.find('thead th[data-column="sessions"] .owa_gridSort').trigger('click');

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
