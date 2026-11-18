// The modules import jQuery as the default and the explorer as a namespace;
// marked an ES module with its own default, both are callable.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    jq.default = jq;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');
require('../../modules/Base/src/reporting/owa.filterpills.js');
require('../../modules/Base/src/reporting/owa.dialog.js');

const { startRowsReport, reloadUrl, navigate } = require('../../modules/Base/src/reporting/owa.rowsReport.js');
const { startRealtime } = require('../../modules/Base/src/reporting/owa.realtime.js');
const { startNewReportDialog } = require('../../modules/Base/src/reporting/owa.customReports.js');
const { fadeTransientStatus, SHOWN_FOR } = require('../../modules/Base/src/reporting/owa.transientStatus.js');
const { filterUsers, startUserFilter } = require('../../modules/Base/src/reporting/owa.propertyAccess.js');

/**
 * The screens whose templates now only hand over data: a report of rows the
 * server computed (owa.rowsReport.js), the realtime report, the custom
 * reports list's New dialog, the fading success message and the Property
 * users filter.
 */

const pills = (p) => `<div id="${p}Filters"></div><button id="${p}AddFilter"></button>
    <div id="${p}FilterForm"><select id="${p}FilterDimension"></select><select id="${p}FilterOperator"></select>
    <input id="${p}FilterValue"><button id="${p}FilterAdd"></button><button id="${p}FilterCancel"></button></div>`;

const rows = { resultsRows: [{ step: { value: 'Basket', result_type: 'dimension', name: 'step' } }] };
const filter = { dimensions: [], metrics: [], constraints: 'medium==email' };

let went;

beforeEach(() => {
    OWA.setSetting('constraint_operators', ['==', '!=', '>=', '<=', '>', '<', '=~', '!~', '=@', '!@']);
    $.fn.chosen = function () { return this; };
    went = [];
    jest.spyOn(navigate, 'to').mockImplementation((u) => went.push(u));
    jest.spyOn(OWA.resultSetExplorer.prototype, 'refreshGrid').mockImplementation(() => {});
});

afterEach(() => {
    jest.restoreAllMocks();
    jest.useRealTimers();
    for (const k of ['funnelSteps', 'funnelConstraints', 'domstreams', 'domstreamConstraints']) {
        delete OWA.items[k];
    }
    $(document).off();
    document.body.innerHTML = '';
});

describe('a reload keeps the rest of the URL', () => {

    test('sets the parameters it is given', () => {
        expect(reloadUrl('https://owa.test/index.php?owa_period=last_7_days&page=3', { owa_constraints: 'a==b' }))
            .toBe('https://owa.test/index.php?owa_period=last_7_days&page=3&owa_constraints=a%3D%3Db');
    });

    test('and goes back to the first page when asked', () => {
        expect(reloadUrl('https://owa.test/index.php?page=3&owa_x=1', { owa_x: '2' }, true))
            .toBe('https://owa.test/index.php?owa_x=2');
    });
});

const funnel = (extra) => ({
    grid: 'funnel-steps-grid', item: 'funnelSteps', table: rows,
    filter: { ids: 'funnel', item: 'funnelConstraints', firstPage: false, ...filter },
    reloadOn: [{ input: 'funnel-scope-visitor', params: { owa_funnelScope: 'visitor' } },
        { input: 'funnel-scope-session', params: { owa_funnelScope: 'session' } }],
    ...extra,
});

describe('a report of computed rows (the funnel)', () => {

    test('puts its rows in the standard grid without explorer controls, as OWA.items[item]', () => {
        document.body.innerHTML = `<div id="funnel-steps-grid"></div>${pills('funnel')}`;

        startRowsReport(funnel());

        expect(OWA.items.funnelSteps).toBeInstanceOf(OWA.resultSetExplorer);
        expect(OWA.items.funnelSteps.dom_id).toBe('funnel-steps-grid');
        expect(OWA.items.funnelSteps.options.grid.showExplorerControls).toBe(false);
        expect(OWA.items.funnelSteps.resultSet.resultsRows).toEqual(rows.resultsRows);
        expect(OWA.resultSetExplorer.prototype.refreshGrid).toHaveBeenCalledTimes(1);
    });

    test('draws no grid without rows, and starts its filter on the constraints it was handed, as OWA.items[filter.item]', () => {
        document.body.innerHTML = pills('funnel');

        startRowsReport(funnel({ table: null }));

        expect(OWA.items.funnelSteps).toBeUndefined();
        expect(OWA.items.funnelConstraints).toBeInstanceOf(OWA.filterPills);
        expect(OWA.items.funnelConstraints.get()).toBe('medium==email');
    });

    test('a reloadOn input reloads with its parameters, keeping the page', () => {
        document.body.innerHTML = `${pills('funnel')}<input type="radio" id="funnel-scope-visitor"><input type="radio" id="funnel-scope-session">`;

        startRowsReport(funnel({ table: null }));
        $('#funnel-scope-session').trigger('change');

        expect(new URL(went[0]).searchParams.get('owa_funnelScope')).toBe('session');
    });
});

describe('a report of computed rows (a module\'s list)', () => {

    const list = (extra) => ({
        grid: 'list-grid', item: 'listGrid', table: rows,
        gridOptions: { columnFormatters: { play: 'overlayLink' } },
        filter: { ids: 'list', item: 'listConstraints', firstPage: true, ...filter },
        sizedWindow: { link: 'a.owa_overlayLink', name: 'A player' },
        ...extra,
    });

    test('gives the grid its options', () => {
        document.body.innerHTML = `<div id="list-grid"></div>${pills('list')}`;

        startRowsReport(list());

        expect(OWA.items.listGrid.options.grid.columnFormatters).toEqual({ play: 'overlayLink' });
        expect(OWA.items.listConstraints.get()).toBe('medium==email');
        delete OWA.items.listGrid;
        delete OWA.items.listConstraints;
    });

    test('opens a sized-window link in a window of its data-width by data-height', () => {
        document.body.innerHTML = `<div id="list-grid"><a class="owa_overlayLink" href="https://owa.test/play" data-height="600" data-width="800">Play</a></div>${pills('list')}`;
        window.open = jest.fn();

        startRowsReport(list({ table: null }));
        $('#list-grid a').trigger('click');

        expect(window.open).toHaveBeenCalledWith('https://owa.test/play', 'A player',
            'menubar=yes,location=yes,resizable=no,scrollbars=yes,status=yes,height=600,width=800');
        delete OWA.items.listConstraints;
    });

    test('a filter that goes back to the first page drops it', () => {
        expect(reloadUrl('https://owa.test/index.php?page=3', { owa_constraints: 'a==b' }, true))
            .toBe('https://owa.test/index.php?owa_constraints=a%3D%3Db');
    });
});

describe('the realtime report', () => {

    test('starts on #owa_realtime with the config it is handed', () => {
        const start = jest.spyOn(OWA.realtime.prototype, 'start').mockImplementation(() => {});
        const config = { apiUrl: 'https://owa.test/api?do=realtime', mapUrl: 'https://owa.test/map.svg', timezone: 'Europe/Berlin' };
        document.body.innerHTML = `<div id="owa_realtime"></div><script type="application/json" data-ui="realtime:config">${JSON.stringify(config)}</script>`;

        const realtime = startRealtime();

        expect(realtime).toBeInstanceOf(OWA.realtime);
        expect(realtime.root).toBe(document.getElementById('owa_realtime'));
        expect(realtime.apiUrl).toBe(config.apiUrl);
        expect(start).toHaveBeenCalledTimes(1);
    });

    test('a page without its data starts nothing', () => {
        expect(startRealtime()).toBeNull();
    });
});

describe('the New dialog on the custom reports list', () => {

    test('opens from every New control, titled in the page language', () => {
        OWA.config.strings = { 'What do you want to make?': 'Was möchten Sie erstellen?' };
        document.body.innerHTML = '<a class="owa_newCustomReport" href="#">New</a>'
            + '<div id="customReportNewDialog" data-ui="dialog:new-report" hidden><a href="#">Report</a></div>';

        const dialog = startNewReportDialog();
        const open = jest.spyOn(dialog, 'open').mockImplementation(() => {});

        $('.owa_newCustomReport').trigger('click');

        expect(open).toHaveBeenCalledTimes(1);
        expect(document.body.textContent).toContain('Was möchten Sie erstellen?');
        delete OWA.config.strings;
    });

    test('a list without the dialog binds nothing', () => {
        expect(startNewReportDialog()).toBeNull();
    });
});

describe('a success message', () => {

    test('fades after it has been read', () => {
        jest.useFakeTimers();
        const fade = jest.spyOn($.fn, 'fadeOut').mockImplementation(function () { return this; });
        document.body.innerHTML = '<div class="status owa_transientStatus" role="status">Saved</div>';

        expect(fadeTransientStatus()).toBe(true);
        jest.advanceTimersByTime(SHOWN_FOR - 1);
        expect(fade).not.toHaveBeenCalled();
        jest.advanceTimersByTime(1);
        expect(fade).toHaveBeenCalledWith(600);
    });

    test('a page without one schedules nothing', () => {
        expect(fadeTransientStatus()).toBe(false);
    });
});

describe('the Property users filter', () => {

    test('shows the rows containing what is typed, ignoring case', () => {
        document.body.innerHTML = '<input id="owa-user-filter"><table class="owa-allowed-users">'
            + '<tr class="owa-user-row"><td>Ana Admin</td></tr><tr class="owa-user-row"><td>Viewer Vic</td></tr></table>';

        startUserFilter();
        $('#owa-user-filter').val('ADMIN').trigger('keyup');

        const shown = $('tr.owa-user-row').map(function () { return this.style.display !== 'none'; }).get();

        expect(shown).toEqual([true, false]);

        filterUsers('');

        expect($('tr.owa-user-row').filter(function () { return this.style.display === 'none'; }).length).toBe(0);
    });
});
