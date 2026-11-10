/**
 * A dimension report's trend draws the top rows of the grid under it: a line
 * per row over the trend's own total, each with its comparison when the report
 * is compared -- shown only while its line is hovered.
 */

jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/v1/owa.areachart.js' );
require( '../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js' );

const day = ( ymd ) => Date.UTC( +ymd.substr( 0, 4 ), +ymd.substr( 4, 2 ) - 1, +ymd.substr( 6, 2 ) );

const COMPARE = { compare: 'previous_period', startDate: '20261026', endDate: '20261027' };

/** The trend's own answer: one row per day, the total. */
function trend( rows, compareRows ) {
    return {
        timePeriod: { startDate: '20261102', endDate: '20261103' },
        resultsRows: rows.map( ( [ d, v ] ) => ( { date: { value: d, data_type: 'yyyymmdd' }, visits: { value: v, data_type: 'integer' } } ) ),
        compare: compareRows && Object.assign( { rows: compareRows.map( ( [ d, real, v ] ) => ( {
            date: { value: d, compare_period_value: real, data_type: 'yyyymmdd' },
            visits: { value: v, data_type: 'integer' },
        } ) ) }, COMPARE ),
        relatedMetrics: { Usage: [ { name: 'visits', metric_type: 'count' } ] },
        getMetricLabel: () => 'Visits',
    };
}

/** The rows' answer: by day and browser, restricted to the plotted rows. */
function byBrowser( rows, compareRows ) {
    const row = ( [ d, b, v, real ] ) => ( {
        date: Object.assign( { value: d, data_type: 'yyyymmdd' }, real ? { compare_period_value: real } : {} ),
        browserType: { value: b, data_type: 'string' },
        visits: { value: v, data_type: 'integer' },
    } );
    return {
        resultsRows: rows.map( row ),
        compare: compareRows ? Object.assign( { rows: compareRows.map( row ) }, COMPARE ) : null,
    };
}

const ROWS = [ { values: { browserType: 'Chrome' }, label: 'Chrome' }, { values: { browserType: 'Safari' }, label: 'Safari' } ];

function chartWithRows( main, rows ) {
    const chart = new OWA.areaChart();
    chart.plot = { dims: [ 'browserType' ], rows: ROWS };
    chart.rowsResultSet = rows;
    chart.rowsX = 'date';
    return chart.withGridRows( chart.singleSeries( main, 'date', 'visits' ), main, 'date', 'visits' );
}

describe( 'the grid\'s rows as lines', () => {

    test( 'a line per row in the grid\'s order, over the trend\'s own total; a missing day is a zero', () => {
        const built = chartWithRows( trend( [ [ '20261102', 10 ], [ '20261103', 20 ] ] ),
            byBrowser( [ [ '20261102', 'Safari', 2 ], [ '20261102', 'Chrome', 6 ], [ '20261103', 'Chrome', 9 ] ] ) );

        expect( built.series.map( ( s ) => s.label ) ).toEqual( [ 'Total', 'Chrome', 'Safari' ] );
        expect( built.series[0].data ).toEqual( [ [ day( '20261102' ), 10 ], [ day( '20261103' ), 20 ] ] );
        expect( built.series[1].data ).toEqual( [ [ day( '20261102' ), 6 ], [ day( '20261103' ), 9 ] ] );
        expect( built.series[2].data ).toEqual( [ [ day( '20261102' ), 2 ], [ day( '20261103' ), 0 ] ] );
        expect( built.series[1].lines.fill ).toBe( false );
    } );

    test( 'compared, every line has a dashed comparison, after all the lines', () => {
        const built = chartWithRows(
            trend( [ [ '20261102', 10 ], [ '20261103', 20 ] ], [ [ '20261102', '20261026', 5 ], [ '20261103', '20261027', 8 ] ] ),
            byBrowser( [ [ '20261102', 'Chrome', 6 ] ], [ [ '20261102', 'Chrome', 3, '20261026' ], [ '20261103', 'Safari', 1, '20261027' ] ] ) );

        const kinds = built.series.map( ( s ) => ( s.isCompare ? 'cmp:' + s.compareOf : s.label ) );
        expect( kinds ).toEqual( [ 'Total', 'Chrome', 'Safari', 'cmp:0', 'cmp:1', 'cmp:2' ] );

        const chrome = built.series[4];
        expect( chrome.dashed ).toBe( true );
        expect( chrome.label ).toBeUndefined();
        expect( chrome.data ).toEqual( [ [ day( '20261102' ), 3 ], [ day( '20261103' ), 0 ] ] );
        expect( chrome.compareDates[ day( '20261102' ) ] ).toBe( '20261026' );
    } );

    test( 'rows fetched for another axis are not drawn: they are fetched again', () => {
        const chart = new OWA.areaChart();
        chart.plot = { dims: [ 'browserType' ], rows: ROWS };
        chart.rowsResultSet = byBrowser( [] );
        chart.rowsX = 'yearMonth';
        chart.fetchRows = jest.fn();

        const main  = trend( [ [ '20261102', 10 ] ] );
        const built = chart.withGridRows( chart.singleSeries( main, 'date', 'visits' ), main, 'date', 'visits' );

        expect( built.series ).toHaveLength( 1 );
        expect( chart.fetchRows ).toHaveBeenCalled();
    } );
} );

describe( 'asking for the rows', () => {

    test( 'the trend\'s query, by its axis and the grid\'s dimensions, restricted to the rows', () => {
        const chart = new OWA.areaChart();
        chart.explorer = { resultSet: { self: 'https://owa.test/api/index.php?metrics=visits&dimensions=date&page=2&sort=date&compare=previous_period' } };
        chart.xDimension = 'date';

        const asked = [];
        const getJSON = $.getJSON;
        $.getJSON = ( url ) => asked.push( url );

        try {
            expect( chart.plotRows( [ 'browserType' ], ROWS ) ).toBe( true );
            // The same rows again ask for nothing.
            expect( chart.plotRows( [ 'browserType' ], ROWS ) ).toBe( false );
        } finally {
            $.getJSON = getJSON;
        }

        expect( asked ).toHaveLength( 1 );
        const url = new URL( asked[0] );
        expect( url.searchParams.get( 'dimensions' ) ).toBe( 'date,browserType' );
        expect( JSON.parse( url.searchParams.get( 'rowValues' ) ) ).toEqual( { browserType: [ 'Chrome', 'Safari' ] } );
        expect( url.searchParams.get( 'compare' ) ).toBe( 'previous_period' );
        expect( url.searchParams.has( 'page' ) ).toBe( false );
    } );
} );

describe( 'a line\'s comparison shows with its line', () => {

    function drawn( hovered, selected = null ) {
        const chart = new OWA.areaChart();
        chart.dataseries = chartWithRows(
            trend( [ [ '20261102', 10 ], [ '20261103', 20 ] ], [ [ '20261102', '20261026', 5 ] ] ),
            byBrowser( [ [ '20261102', 'Chrome', 6 ] ], [ [ '20261102', 'Chrome', 3, '20261026' ] ] ) ).series;
        chart.dataseries.forEach( ( s, i ) => { s.color = '#00000' + i; } );
        chart.flotOptions = { legend: { show: true } };
        chart.domSelector = '#none';
        chart.dom_id = 'none';
        chart.hovered = hovered;
        chart.selected = selected;

        const plot = $.plot;
        $.plot = jest.fn();
        try {
            chart.draw();
        } finally {
            $.plot = plot;
        }
        return chart.plotted.map( ( s ) => ( s.isCompare ? 'cmp:' + s.compareOf : s.label ) );
    }

    test( 'nothing hovered: the lines alone', () => {
        expect( drawn( null ) ).toEqual( [ 'Total', 'Chrome', 'Safari' ] );
    } );

    test( 'Chrome hovered: Chrome\'s comparison too', () => {
        expect( drawn( 1 ) ).toEqual( [ 'Total', 'Chrome', 'Safari', 'cmp:1' ] );
    } );

    test( 'a line selected in the legend keeps its comparison', () => {
        expect( drawn( null, 0 ) ).toEqual( [ 'Total', 'Chrome', 'Safari', 'cmp:0' ] );
    } );
} );

describe( 'the compared tooltip', () => {

    test( 'the day against its comparison day, then each line\'s value and change', () => {
        const chart = new OWA.areaChart();
        chart.xType = 'yyyymmdd';
        chart.rowsResultSet = byBrowser( [], [] );
        chart.dataseries = chartWithRows(
            trend( [ [ '20261102', 10 ], [ '20261103', 20 ] ], [ [ '20261102', '20261026', 5 ], [ '20261103', '20261027', 8 ] ] ),
            byBrowser( [ [ '20261102', 'Chrome', 6 ] ], [ [ '20261102', 'Chrome', 3, '20261026' ] ] ) ).series;
        chart.dataseries.forEach( ( s ) => { s.color = '#1874CD'; } );

        document.body.innerHTML = chart.comparedTooltip( day( '20261102' ) );

        expect( document.querySelector( '.owa_chartTipHead' ).textContent ).toMatch( / vs / );
        // Each line: its value, the compared day's, the change.
        const cells = [ ...document.querySelectorAll( '.owa_chartTip tr' ) ].map( ( tr ) => [ ...tr.children ].map( ( c ) => c.textContent ) );
        expect( cells ).toEqual( [ [ 'Total', '10', '5', '▲ 100.0%' ], [ 'Chrome', '6', '3', '▲ 100.0%' ], [ 'Safari', '0', '0', '—' ] ] );
    } );

    test( 'the compared day is worked out from the two starts, data or not', () => {
        const chart = new OWA.areaChart();
        chart.explorer = { resultSet: { timePeriod: { startDate: '20261102' } } };

        chart.xType = 'yyyymmdd';
        expect( chart.comparedX( day( '20261104' ), { startDate: '20261026' } ) ).toBe( day( '20261028' ) );

        chart.xType = 'yyyymm';
        expect( chart.comparedX( day( '20261101' ), { startDate: '20251101' } ) ).toBe( day( '20251101' ) );

        expect( chart.comparedX( day( '20261104' ), null ) ).toBeNull();
    } );

    describe( 'it goes beside the pointer, inside the window', () => {
        const view = { left: 0, top: 100, width: 1000, height: 600 };

        test( 'right of the pointer and below', () => {
            expect( OWA.areaChart.besidePointer( { x: 200, y: 300 }, 250, 150, view ) ).toEqual( { left: 212, top: 312 } );
        } );

        test( 'left of the pointer near the right edge', () => {
            expect( OWA.areaChart.besidePointer( { x: 900, y: 300 }, 250, 150, view ).left ).toBe( 900 - 12 - 250 );
        } );

        test( 'above the pointer only where below would leave the window', () => {
            expect( OWA.areaChart.besidePointer( { x: 200, y: 650 }, 250, 150, view ).top ).toBe( 650 - 12 - 150 );
        } );

        test( 'wider than either side, it is held inside the window', () => {
            expect( OWA.areaChart.besidePointer( { x: 500, y: 300 }, 600, 150, view ).left ).toBe( 8 );
        } );
    } );

    test( 'nothing compared, no compared tooltip', () => {
        const chart = new OWA.areaChart();
        chart.dataseries = [ { label: 'Visits', data: [ [ 1, 2 ] ] } ];
        expect( chart.comparedTooltip( 1 ) ).toBeNull();
    } );
} );

describe( 'the active point follows the pointer', () => {

    const chart = () => {
        const c = new OWA.areaChart();
        c.xType = 'yyyymmdd';
        c.dataseries = [
            { label: 'Total', color: '#111', data: [ [ day( '20261102' ), 9 ], [ day( '20261103' ), 4 ] ] },
            { label: 'Chrome', color: '#222', data: [ [ day( '20261102' ), 6 ], [ day( '20261103' ), 1 ] ] },
        ];
        return c;
    };

    test( 'anywhere along the axis, the nearest day is the active one', () => {
        expect( chart().nearestX( day( '20261102' ) + 3600e3 ) ).toBe( day( '20261102' ) );
        expect( chart().nearestX( day( '20261103' ) - 3600e3 ) ).toBe( day( '20261103' ) );
    } );

    test( 'off every line, the total is the line followed', () => {
        const c = chart();
        c.dataseries.unshift( { isCompare: true, compareOf: 1, data: [] } );
        expect( c.firstLine() ).toBe( 1 );
    } );

    test( 'uncompared, the tooltip is the day and every line\'s value', () => {
        document.body.innerHTML = chart().pointTooltip( day( '20261103' ) );
        expect( document.querySelector( '.owa_chartTipHead' ).textContent ).not.toMatch( / vs / );
        const cells = [ ...document.querySelectorAll( '.owa_chartTip tr' ) ].map( ( tr ) => [ ...tr.children ].map( ( td ) => td.textContent ) );
        expect( cells ).toEqual( [ [ 'Total', '4' ], [ 'Chrome', '1' ] ] );
    } );

    test( 'a vertical line marks the active day, the height of the plot', () => {
        const c = chart();
        const calls = [];
        const ctx = new Proxy( {}, { get: ( t, k ) => ( k in t ? t[k] : ( ...a ) => calls.push( [ k, ...a ] ) ), set: ( t, k, v ) => { t[k] = v; return true; } } );
        const plot = {
            getPlotOffset: () => ( { left: 40, top: 10 } ),
            getAxes: () => ( { xaxis: { p2c: ( x ) => ( x === day( '20261103' ) ? 200 : 0 ) } } ),
            width: () => 500, height: () => 250,
        };

        c.drawCrosshair( plot, ctx );
        expect( calls ).toHaveLength( 0 );

        c.activeX = day( '20261103' );
        c.drawCrosshair( plot, ctx );
        expect( calls.filter( ( a ) => a[0] === 'moveTo' || a[0] === 'lineTo' ) ).toEqual( [ [ 'moveTo', 240.5, 10 ], [ 'lineTo', 240.5, 260 ] ] );
    } );

    test( 'clicking a point follows its line; clicking it again, the total', () => {
        const c = chart();
        c.selected = null;
        c.draw = jest.fn();
        const item = { series: { index: 1 }, datapoint: [ day( '20261102' ), 6 ] };

        c.onPointClick( item );
        expect( c.selected ).toBe( 1 );
        c.onPointClick( item );
        expect( c.selected ).toBeNull();
    } );
} );

describe( 'a card\'s chart', () => {

    test( 'the total is a filled area on a trend, a plain line on a card', () => {
        const c = new OWA.areaChart();
        expect( c.areaSeries( 'Visits', [ [ 1, 2 ], [ 2, 3 ] ] ).lines.fill ).toBe( true );
        c.options.fill = false;
        expect( c.areaSeries( 'Visits', [ [ 1, 2 ], [ 2, 3 ] ] ).lines.fill ).toBe( false );
    } );
} );

describe( 'the chart\'s title', () => {

    const chart = ( plot ) => {
        const c = new OWA.areaChart();
        c.options.series = [ { x: 'date', y: 'visits' } ];
        c.explorer = { resultSet: { aggregates: { visits: { label: 'Visits' } }, getMetricLabel: () => 'Visits' } };
        c.plot = plot;
        return c;
    };

    test( 'the metric over time', () => {
        expect( chart( null ).chartTitle() ).toBe( 'Visits over time' );
    } );

    test( 'by the grid\'s dimensions when it draws the grid\'s rows', () => {
        expect( chart( { dims: [ 'browserType' ], rows: [ { values: {}, label: 'Chrome' } ], label: 'Browser' } ).chartTitle() )
            .toBe( 'Visits by Browser over time' );
    } );

    test( 'none where the widget has a heading of its own', () => {
        document.body.innerHTML = '<div id="w"><div class="owa_chartHead"><div class="owa_chartTitle">x</div></div></div>';
        const c = chart( null );
        c.options.showTitle = false;
        c.drawTitle( 'w' );
        expect( document.querySelector( '.owa_chartTitle' ).textContent ).toBe( '' );
    } );
} );

describe( 'the hovered line', () => {

    const chart = new OWA.areaChart();
    chart.dataseries = [
        { label: 'Total', data: [ [ 1, 0 ], [ 2, 7 ] ] },
        { label: 'Chrome', data: [ [ 1, 0 ], [ 2, 4 ] ] },
        { label: 'Safari', data: [ [ 1, 0 ], [ 2, 3 ] ] },
        { isCompare: true, compareOf: 0, data: [ [ 1, 24 ], [ 2, 9 ] ] },
    ];
    const hover = ( index, x, y ) => chart.hoveredLine( { series: { index }, datapoint: [ x, y ] } );

    test( 'where lines meet, as on a day with nothing, it is the first: the total', () => {
        expect( hover( 2, 1, 0 ) ).toBe( 0 );
    } );

    test( 'otherwise the one under the pointer', () => {
        expect( hover( 2, 2, 3 ) ).toBe( 2 );
    } );
} );

describe( 'OWA.linkTrendToGrid', () => {

    function grid( rows ) {
        return {
            resultSet: {
                self: 'https://owa.test/api/index.php?metrics=visits&dimensions=browserType%2CosType',
                resultsRows: rows.map( ( [ b, o ] ) => ( {
                    browserType: { value: b, formatted_value: b },
                    osType: { value: o, formatted_value: o },
                } ) ),
            },
            setResultSet( rs ) { this.resultSet = rs; },
        };
    }

    test( 'hands the trend the top rows as the grid shows them, skipping a row with an empty value', () => {
        const g     = grid( [ [ 'Chrome', 'Mac' ], [ '', 'Linux' ], [ 'Safari', 'iOS' ], [ 'Edge', 'Win' ], [ 'Opera', 'Win' ], [ 'Brave', 'Mac' ], [ 'Vivaldi', 'Win' ] ] );
        const trendExplorer = { areaChart: { plotRows: jest.fn() } };

        OWA.linkTrendToGrid( trendExplorer, g );

        const [ dims, rows ] = trendExplorer.areaChart.plotRows.mock.calls[0];
        expect( dims ).toEqual( [ 'browserType', 'osType' ] );
        expect( rows.map( ( r ) => r.label ) ).toEqual( [ 'Chrome / Mac', 'Safari / iOS', 'Edge / Win', 'Opera / Win', 'Brave / Mac' ] );
        expect( rows[0].values ).toEqual( { browserType: 'Chrome', osType: 'Mac' } );
    } );

    test( 'a new page, sort or filter on the grid is handed over again', () => {
        const g = grid( [ [ 'Chrome', 'Mac' ] ] );
        const trendExplorer = { areaChart: { plotRows: jest.fn() } };

        OWA.linkTrendToGrid( trendExplorer, g );
        g.setResultSet( grid( [ [ 'Firefox', 'Linux' ] ] ).resultSet );

        expect( trendExplorer.areaChart.plotRows ).toHaveBeenCalledTimes( 2 );
        expect( trendExplorer.areaChart.plotRows.mock.calls[1][1][0].label ).toBe( 'Firefox / Linux' );
    } );

    describe( 'the chart\'s metric and the grid\'s sort are one choice', () => {

        const sortedBy = ( column ) => ( {
            self: 'https://owa.test/api/index.php?metrics=visits,pageViews&dimensions=browserType',
            sortColumn: column, sortOrder: 'desc',
            resultsRows: [ {
                browserType: { value: 'Chrome', formatted_value: 'Chrome', result_type: 'dimension' },
                visits: { value: 3, result_type: 'metric' },
                pageViews: { value: 9, result_type: 'metric' },
            } ],
        } );

        function linked() {
            const g = { resultSet: sortedBy( 'pageViews' ), setResultSet( rs ) { this.resultSet = rs; }, changeSort: jest.fn() };
            const trendExplorer = {
                areaChart: { plotRows: jest.fn(), changeMetric: jest.fn( () => true ) },
                markChartedMetric: jest.fn(),
            };
            OWA.linkTrendToGrid( trendExplorer, g );
            return { g, trendExplorer };
        }

        test( 'the first load leaves the chart on the metric the report names', () => {
            expect( linked().trendExplorer.areaChart.changeMetric ).not.toHaveBeenCalled();
        } );

        test( 'sorting the grid by another metric charts it', () => {
            const { g, trendExplorer } = linked();
            g.setResultSet( sortedBy( 'visits' ) );
            expect( trendExplorer.areaChart.changeMetric ).toHaveBeenCalledWith( 'visits' );
            expect( trendExplorer.markChartedMetric ).toHaveBeenCalled();
        } );

        test( 'sorting by a dimension leaves the chart\'s metric alone', () => {
            const { g, trendExplorer } = linked();
            g.setResultSet( sortedBy( 'browserType' ) );
            expect( trendExplorer.areaChart.changeMetric ).not.toHaveBeenCalled();
        } );

        test( 'charting a metric from its box sorts the grid by it, largest first', () => {
            const { g, trendExplorer } = linked();
            trendExplorer.onChartMetric( 'visits' );
            expect( g.changeSort ).toHaveBeenCalledWith( 'visits', 'desc' );

            // Already sorted by it, or not in the grid: no new sort.
            trendExplorer.onChartMetric( 'pageViews' );
            trendExplorer.onChartMetric( 'bounces' );
            expect( g.changeSort ).toHaveBeenCalledTimes( 1 );
        } );
    } );

    test( 'before the trend has a chart, the rows wait on it', () => {
        const trendExplorer = {};
        OWA.linkTrendToGrid( trendExplorer, grid( [ [ 'Chrome', 'Mac' ] ] ) );
        expect( trendExplorer.plotGridRows.rows ).toHaveLength( 1 );
    } );
} );
