import jQuery from 'jquery';
import { OWA } from './owa.format.js';

/**
 * A report whose rows the server computed, rather than the reporting API
 * returning them: the funnel (visualization_funnel.php), and a module's
 * report built the same way. The template hands over everything this needs:
 *
 *   <script type="application/json" data-ui="rows-report:config">
 *     { "grid": "funnel-steps-grid", "item": "funnelSteps", "table": { "resultsRows": [ ... ] },
 *       "gridOptions": { "columnFormatters": { ... } },
 *       "filter": { "ids": "funnel", "item": "funnelConstraints", "firstPage": false,
 *                   "dimensions": [ ... ], "metrics": [ ... ], "constraints": "..." },
 *       "reloadOn": [ { "input": "funnel-scope-visitor", "params": { "owa_funnelScope": "visitor" } } ],
 *       "sizedWindow": { "link": "a.owa_overlayLink", "name": "..." } }
 *   </script>
 *
 * The grid is the standard one, given the rows instead of a URL, published as
 * OWA.items[item]. The filter pills (filter_pills.php's elements, named from
 * `ids`) and each `reloadOn` input reload the page, because both are
 * server-rendered state on the URL; the pills are published as
 * OWA.items[filter.item]. A `sizedWindow` link opens in a window of its
 * data-width by data-height.
 */

/** A report's data. */
export const ROWS_REPORT_SELECTOR = '[data-ui="rows-report:config"]';

/**
 * The page's URL with these parameters set, every other one -- period,
 * site, segment -- kept.
 *
 * @param  {string}  href
 * @param  {Object}  params     name => value
 * @param  {boolean} firstPage  drop `page`: a new filter means a new first page
 * @return {string}
 */
export function reloadUrl( href, params, firstPage ) {

    var url = new URL( href );

    Object.keys( params ).forEach( function ( k ) {

        url.searchParams.set( k, params[ k ] );
    } );

    if ( firstPage ) {
        url.searchParams.delete( 'page' );
    }

    return url.toString();
}

/** Go to a URL. An object so a test can watch it. */
export var navigate = {
    to: function ( url ) {
        window.location.href = url;
    }
};

/**
 * The standard grid, given rows instead of a URL, with its explorer controls
 * off: they re-query the result set's own URL, and these rows have none.
 *
 * @param  {string} domId
 * @param  {Object} table        a result set
 * @param  {Object} gridOptions  more of explorer.options.grid
 * @return {Object|null}         the explorer, or null with no rows
 */
export function rowsGrid( domId, table, gridOptions ) {

    if ( ! table || ! table.resultsRows || ! table.resultsRows.length ) {
        return null;
    }

    var rse = new OWA.resultSetExplorer( domId );

    rse.options.grid.showExplorerControls = false;

    Object.keys( gridOptions || {} ).forEach( function ( k ) {

        rse.options.grid[ k ] = gridOptions[ k ];
    } );

    rse.setResultSet( table );
    rse.refreshGrid();

    return rse;
}

/**
 * Filter pills that reload the page with the constraints when they change.
 *
 * @param  {Object} filter  { ids, dimensions, metrics, constraints, firstPage }
 * @return {Object}         the OWA.filterPills
 */
export function filterPills( filter ) {

    var applied = '';
    var ids     = {};

    // Each part's element id, as filter_pills.php draws them: funnelFilterCancel, ...
    [ [ 'pills', 'Filters' ], [ 'add', 'AddFilter' ], [ 'form', 'FilterForm' ],
        [ 'dimension', 'FilterDimension' ], [ 'operator', 'FilterOperator' ], [ 'value', 'FilterValue' ],
        [ 'addFilter', 'FilterAdd' ], [ 'cancel', 'FilterCancel' ] ].forEach( function ( part ) {

        ids[ part[0] ] = filter.ids + part[1];
    } );

    var pills = new OWA.filterPills( ids, OWA.filterPills.choices( filter.dimensions, filter.metrics ), function () {

        var constraints = pills.get();

        if ( constraints !== applied ) {
            navigate.to( reloadUrl( window.location.href, { 'owa_constraints': constraints }, filter.firstPage ) );
        }
    } );

    pills.set( filter.constraints || '' );
    applied = pills.get();

    return pills;
}

/**
 * Start one report from its data.
 *
 * @param {Object} config  see the top of this file
 */
export function startRowsReport( config ) {

    var grid = rowsGrid( config.grid, config.table, config.gridOptions );

    if ( grid && config.item ) {
        OWA.items[ config.item ] = grid;
    }

    if ( config.filter ) {

        var pills = filterPills( config.filter );

        if ( config.filter.item ) {
            OWA.items[ config.filter.item ] = pills;
        }
    }

    ( config.reloadOn || [] ).forEach( function ( on ) {

        jQuery( document.getElementById( on.input ) ).change( function () {
            navigate.to( reloadUrl( window.location.href, on.params ) );
        } );
    } );

    /*
     * Delegated, so it keeps working when the grid redraws its rows. The window
     * is the size the link names: a replay positions events against the
     * viewport it was made in.
     */
    if ( config.sizedWindow ) {

        jQuery( document.getElementById( config.grid ) ).on( 'click', config.sizedWindow.link, function ( e ) {

            e.preventDefault();

            var link = jQuery( this );

            // noopener: the page opened is the tracked site's, and gets no
            // handle on this window.
            window.open(
                link.attr( 'href' ),
                config.sizedWindow.name,
                'menubar=yes,location=yes,resizable=no,scrollbars=yes,status=yes'
                    + ',height=' + link.data( 'height' ) + ',width=' + link.data( 'width' )
                    + ',noopener'
            );
        } );
    }
}

/** Each report starts where its template has handed its data over. */
function start() {

    Array.prototype.forEach.call( document.querySelectorAll( ROWS_REPORT_SELECTOR ), function ( el ) {

        startRowsReport( JSON.parse( el.textContent || '{}' ) );
    } );
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
}

export { OWA };
