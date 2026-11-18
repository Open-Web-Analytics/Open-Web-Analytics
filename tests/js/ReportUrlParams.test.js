/**
 * What the reporting code reads from a result set's URL, and the URL it asks
 * for next.
 *
 * The URLs are a result set's `self`, as PaginatedResultSet::setResultSetUrls()
 * builds it: http_build_query(), so a space is +, a literal + is %2B and a % is
 * %25. A value is read decoded once. A requery is checked by the parameters
 * PHP reads from it, in order, since that is what the server acts on.
 */

jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/owa.kpibox.js' );
require( '../../modules/Base/src/reporting/owa.areachart.js' );
require( '../../modules/Base/src/reporting/owa.resultSetExplorer.js' );

const API = 'https://owa.test/owa/api/index.php';

// http_build_query() of SELF_PARAMS.
const SELF = API + '?metrics=visits%2CpageViews&dimensions=date%2Cmedium'
    + '&constraints=medium%3D%3Dreferral%2CpageUrl%3D%40a%2Bb+c%2Ccity%3D%3DZ%C3%BCrich%2CpageTitle%3D%3D50%2525+off%252C+now%7Cx%3Ay'
    + '&period=last_seven_days&sort=visits-&do=reports&module=base&version=v1&page=3&resultsPerPage=25';

const CONSTRAINTS = 'medium==referral,pageUrl=@a+b c,city==Zürich,pageTitle==50%25 off%2C now|x:y';

const SELF_PARAMS = [
    [ 'metrics', 'visits,pageViews' ],
    [ 'dimensions', 'date,medium' ],
    [ 'constraints', CONSTRAINTS ],
    [ 'period', 'last_seven_days' ],
    [ 'sort', 'visits-' ],
    [ 'do', 'reports' ],
    [ 'module', 'base' ],
    [ 'version', 'v1' ],
    [ 'page', '3' ],
    [ 'resultsPerPage', '25' ],
];

// http_build_query() of { metrics: 'visits+x,%41', dimensions: 'browserType+1,osType%25', do: 'reports' }.
const ENCODED = API + '?metrics=visits%2Bx%2C%2541&dimensions=browserType%2B1%2CosType%2525&do=reports';

/** The parameters PHP's $_GET holds for a URL, in order: + is a space, then %XX. */
function php( url ) {
    const query = url.split( '#' )[0].split( '?' ).slice( 1 ).join( '?' );
    const dec   = ( s ) => decodeURIComponent( s.replace( /\+/g, '%20' ) );
    const out   = [];

    query.split( '&' ).forEach( ( pair ) => {
        if ( pair === '' ) {
            return;
        }
        const at = pair.indexOf( '=' );
        out.push( at === -1 ? [ dec( pair ), '' ] : [ dec( pair.slice( 0, at ) ), dec( pair.slice( at + 1 ) ) ] );
    } );

    return out;
}

/** SELF_PARAMS with these set (in place, or appended) and those removed. */
function params( set, remove = [] ) {
    const out = SELF_PARAMS.map( ( p ) => p.slice() ).filter( ( [ k ] ) => remove.indexOf( k ) === -1 );

    Object.keys( set ).forEach( ( k ) => {
        const at = out.findIndex( ( [ name ] ) => name === k );
        if ( at === -1 ) {
            out.push( [ k, set[k] ] );
        } else {
            out[at][1] = set[k];
        }
    } );

    return out;
}

afterEach( () => jest.restoreAllMocks() );

describe( 'a value read from a result set\'s URL', () => {

    test( 'the constraints are decoded once: + and %2B as the server sent them, the constraint escapes kept', () => {
        const parse = jest.spyOn( OWA.dataGrid, 'parseConstraints' );

        OWA.dataGrid.prototype.constraints( { self: SELF } );
        OWA.dataGrid.emptyText( { self: SELF }, '' );

        expect( parse ).toHaveBeenCalledWith( CONSTRAINTS );
        expect( parse.mock.calls.filter( ( c ) => c[0] === CONSTRAINTS ) ).toHaveLength( 2 );
    } );

    test( 'a URL without constraints has none', () => {
        expect( OWA.dataGrid.prototype.constraints( { self: API + '?dimensions=date' } ) ).toEqual( [] );
        expect( OWA.dataGrid.emptyText( { self: API + '?dimensions=date' }, '' ) ).toBe( 'No data for this period.' );
    } );

    test( 'a grid\'s dimensions', () => {
        expect( OWA.dataGrid.prototype.getDimensions( { self: SELF } ) ).toEqual( [ 'date', 'medium' ] );
        expect( OWA.dataGrid.prototype.getDimensions( { self: API + '?metrics=visits' } ) ).toEqual( [ '' ] );
        expect( OWA.dataGrid.prototype.getDimensions( { self: ENCODED } ) ).toEqual( [ 'browserType+1', 'osType%25' ] );
    } );

    test( 'a relative URL is read the same', () => {
        expect( OWA.dataGrid.prototype.getDimensions( { self: '/owa/api/index.php?dimensions=date%2Cmedium' } ) ).toEqual( [ 'date', 'medium' ] );
    } );

    test( 'a card names its first metric and its first dimension', () => {
        const that = { options: {}, labelOf: ( rs, name ) => '[' + name + ']' };
        const rs   = { self: SELF, aggregates: { visits: { value: 5, formatted_value: '5', data_type: 'integer' } } };

        const $summary = OWA.dataGrid.prototype.cardSummary.call( that, rs );

        expect( $summary.find( '[data-ui="card-explain"]' ).text() ).toBe( '[visits] by [date]' );
    } );

    /*
     * kpiBox.queryMetrics(), linkTrendToGrid() and the trend's granularity and
     * breakdown controls read the value decoded ONCE, as the server encoded it
     * once: a + sent as %2B stays a +, and %2541 stays %41.
     */
    test( 'the metric boxes read the metrics as the server sent them', () => {
        expect( OWA.kpiBox.prototype.queryMetrics( { self: SELF } ) ).toEqual( [ 'visits', 'pageViews' ] );
        expect( OWA.kpiBox.prototype.queryMetrics( { self: ENCODED } ) ).toEqual( [ 'visits+x', '%41' ] );
        expect( OWA.kpiBox.prototype.queryMetrics( { self: API + '?dimensions=date' } ) ).toEqual( [] );
    } );

    test( 'the trend linked to a grid reads the grid\'s dimensions as the server sent them', () => {
        const row = {
            'browserType+1': { value: 'Chrome', formatted_value: 'Chrome' },
            'osType%25': { value: 'Mac', formatted_value: 'Mac' },
        };
        const g = { resultSet: { self: ENCODED, resultsRows: [ row ] }, setResultSet( rs ) { this.resultSet = rs; } };
        const trendExplorer = { areaChart: { plotRows: jest.fn() } };

        OWA.linkTrendToGrid( trendExplorer, g );

        expect( trendExplorer.areaChart.plotRows.mock.calls[0][0] ).toEqual( [ 'browserType+1', 'osType%25' ] );
    } );
} );

describe( 'the URL a grid asks for next', () => {

    let explorer;
    let requested;

    beforeEach( () => {
        document.body.innerHTML = '<div id="grid"></div>';
        explorer = new OWA.resultSetExplorer( 'grid' );
        explorer.resultSet = { self: SELF };
        requested = null;
        jest.spyOn( explorer, 'getNewResultSet' ).mockImplementation( ( u ) => { requested = u; } );
    } );

    test( 'a sort keeps the rest of the query and drops the page', () => {
        explorer.changeSort( 'pageViews', 'desc' );
        expect( requested.split( '?' )[0] ).toBe( API );
        expect( php( requested ) ).toEqual( params( { sort: 'pageViews-' }, [ 'page' ] ) );

        explorer.changeSort( 'date', 'asc' );
        expect( php( requested ) ).toEqual( params( { sort: 'date' }, [ 'page' ] ) );
    } );

    test( 'a swapped dimension replaces it; a new one is added', () => {
        explorer.changeDimension( 'medium', 'source' );
        expect( php( requested ) ).toEqual( params( { dimensions: 'date,source' }, [ 'page' ] ) );

        explorer.changeDimension( '', 'browserType' );
        expect( php( requested ) ).toEqual( params( { dimensions: 'date,medium,browserType' }, [ 'page' ] ) );
    } );

    test( 'a swapped dimension on a URL with no dimensions asks for nothing, and does not throw', () => {
        explorer.resultSet = { self: API + '?metrics=visits&do=reports' };

        expect( () => explorer.changeDimension( 'medium', 'source' ) ).not.toThrow();
        expect( requested ).toBeNull();
    } );

    test( 'a dimension list', () => {
        explorer.setDimensionList( [ 'pageUrl', 'browserType' ] );
        expect( php( requested ) ).toEqual( params( { dimensions: 'pageUrl,browserType' }, [ 'page' ] ) );
    } );

    test( 'constraints reach the server as they were given, + & = # ; and all', () => {
        const c = 'pageUrl=@a+b c,city==Zürich,pageTitle==50%25 off%2C now|x:y,n==1;2&3=4#5*~!\'()';

        explorer.changeConstraints( c );
        expect( requested.split( '?' )[0] ).toBe( API );
        expect( php( requested ) ).toEqual( params( { constraints: c }, [ 'page' ] ) );
    } );

    test( 'a page, and a page size', () => {
        explorer.changePage( 4 );
        expect( php( requested ) ).toEqual( params( { page: '4' } ) );

        explorer.changePage( 2, 50 );
        expect( php( requested ) ).toEqual( params( { page: '2', resultsPerPage: '50' } ) );
    } );

    test( 'a relative URL is asked for on the page\'s own origin', () => {
        explorer.resultSet = { self: '/owa/api/index.php?dimensions=date&page=2' };

        explorer.changeSort( 'visits', 'desc' );

        expect( requested ).toBe( window.location.origin + '/owa/api/index.php?dimensions=date&sort=visits-' );
    } );
} );

describe( 'the URL a trend asks for next', () => {

    function chart() {
        const c = new OWA.areaChart();
        c.xDimension = 'date';
        c.explorer = { resultSet: { self: SELF }, getNewResultSet: jest.fn() };
        return c;
    }

    function capture() {
        const asked = [];
        const answers = [];
        jest.spyOn( $, 'getJSON' ).mockImplementation( ( u, d, done ) => {
            asked.push( u );
            answers.push( done );
            return $.Deferred().promise();
        } );
        return { asked, answers };
    }

    test( 'a broken-out trend\'s total is the query by its axis alone', () => {
        const { asked } = capture();

        expect( chart().totalsOf( { self: SELF }, 'date', 'visits' ) ).toBeNull();

        expect( asked ).toHaveLength( 1 );
        expect( asked[0].split( '?' )[0] ).toBe( API );
        expect( php( asked[0] ) ).toEqual( params( { dimensions: 'date' }, [ 'page', 'sort', 'resultsPerPage' ] ) );
    } );

    test( 'the grid\'s rows, page by page', () => {
        const { asked, answers } = capture();
        const was = OWA.getSetting( 'max_results_per_page' );
        OWA.setSetting( 'max_results_per_page', 1 );

        try {
            const c = chart();
            c.plot = { dims: [ 'browserType' ], rows: [ { values: { browserType: 'Chrome' } }, { values: { browserType: 'Safari +1 %2B' } } ] };
            c.fetchRows();

            const rows = {
                dimensions: 'date,browserType',
                rowValues: JSON.stringify( { browserType: [ 'Chrome', 'Safari +1 %2B' ] } ),
                resultsPerPage: '1',
                sort: 'date,browserType',
            };

            expect( asked ).toHaveLength( 1 );
            expect( asked[0].split( '?' )[0] ).toBe( API );
            expect( php( asked[0] ) ).toEqual( params( rows, [ 'page' ] ) );

            answers[0]( { resultsRows: [ { date: { value: '20261101' }, browserType: { value: 'Chrome' } } ] } );

            expect( asked ).toHaveLength( 2 );
            expect( php( asked[1] ) ).toEqual( params( rows, [ 'page' ] ).concat( [ [ 'page', '2' ] ] ) );
        } finally {
            OWA.setSetting( 'max_results_per_page', was );
        }
    } );

    test( 'a different grain swaps the axis and sorts by it', () => {
        const c = chart();
        c.options.series = [ { x: 'date' } ];

        c.changeGranularity( 'month' );

        const u = c.explorer.getNewResultSet.mock.calls[0][0];
        expect( u.split( '?' )[0] ).toBe( API );
        expect( php( u ) ).toEqual( params( { dimensions: 'month,medium', sort: 'month' }, [ 'page' ] ) );
    } );

    test( 'a breakdown is the axis and that dimension, a page of up to 1000 rows', () => {
        const c = chart();
        c.options.series = [ { series: 'medium' } ];

        expect( c.changeBreakdown( 'browserType' ) ).toBe( true );

        const u = c.explorer.getNewResultSet.mock.calls[0][0];
        expect( php( u ) ).toEqual( params( { dimensions: 'date,browserType', resultsPerPage: '1000' }, [ 'page' ] ) );
    } );

    test( 'the grain and the breakdown read the URL\'s dimensions as the server sent them', () => {
        const c = chart();
        c.explorer.resultSet.self = ENCODED;
        c.xDimension = 'browserType+1';
        c.options.series = [ { x: 'browserType+1' } ];

        c.changeGranularity( 'month' );

        expect( php( c.explorer.getNewResultSet.mock.calls[0][0] ) ).toEqual( [
            [ 'metrics', 'visits+x,%41' ],
            [ 'dimensions', 'month,osType%25' ],
            [ 'do', 'reports' ],
            [ 'sort', 'month' ],
        ] );
    } );
} );
