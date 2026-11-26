import { OWA } from './owa.format.js';

/**
 * A report's widgets (report_widgets.php) and its controls (report.php),
 * started from the data the templates hand over.
 *
 * Each widget's container holds its description:
 *
 *   <script type="application/json" data-ui="report-widget:config">
 *     { "name": "trend", "container": "trend", "url": "...",
 *       "options": { "areaChart": { "showTitle": false } },
 *       "queue": [ [ "makeAreaChart", [ { "x": "date", "y": "visits" } ], "trend" ] ],
 *       "columnLink": { "column": "pagePath", "url": "...", "values": {}, "except": [] },
 *       "breakdown": { "name": "trendBreakdown", "container": "trend-breakdown",
 *                      "url": "...", "queue": [ [ "refreshGrid" ] ] },
 *       "load": true }
 *   </script>
 *
 * After a metric set's widgets, a `report-widgets:set` element names the trend
 * and the grid that are linked, and in a report drawn per metric set the tab
 * the set's result sets belong to. After the last set, `report-widgets:tabs`
 * names the report whose tabs are built.
 *
 * THE GLOBALS STAY. The page, e2e specs and code outside this repository read
 * each widget's variables by name: the explorer as `<name>`, its data URL as `<name>url`, a grid's
 * column link as `<name>link`, a broken-out trend's grid as `<name>Breakdown`
 * (with `<name>Breakdownurl`), and a metric set's tab as `owaSet_<set>`. They
 * are published on window under those names.
 *
 * They are created on DOMContentLoaded rather than while the page parses. The
 * listener is registered when the bundle loads in the head, so it runs before
 * any listener registered by a script loaded after it.
 */

/** A widget's description. */
export const WIDGET_SELECTOR = '[data-ui="report-widget:config"]';

/** A metric set's links and tab. */
export const SET_SELECTOR = '[data-ui="report-widgets:set"]';

/** The report whose tabs are built once every set is registered. */
export const TABS_SELECTOR = '[data-ui="report-widgets:tabs"]';

/** The report's period picker, "as of" line and site filter (report.php). */
export const CONTROLS_SELECTOR = '[data-ui="report:controls"]';

/**
 * Set a global as a top-level `var` did.
 *
 * A name a window property already holds read-only (`top`, `parent`) refused
 * the assignment silently in an inline script; a module is strict and would
 * throw, so it is refused silently here too. The renderer keeps its own
 * reference to every object, so the links between them do not depend on it.
 *
 * @param {string} name
 * @param {*}      value
 */
export function publish( name, value ) {

    try {
        window[ name ] = value;
    } catch ( e ) {
        // read-only on window
    }
}

/**
 * A result-set explorer for a widget, with its options set and its render
 * commands queued, published as `<name>` and its URL as `<name>url`.
 *
 * `options` is { group: { key: value } }, each value assigned to
 * explorer.options[group][key]; the queue's entries are pushed as given and run
 * when the data arrives.
 *
 * @param  {Object} desc  { name, container, url, options, queue }
 * @return {Object}       the OWA.resultSetExplorer
 */
export function explorer( desc ) {

    var options = desc.options || {};

    publish( desc.name + 'url', desc.url );

    var rse = new OWA.resultSetExplorer( desc.container );

    publish( desc.name, rse );

    rse.setDataLoadUrl( desc.url );

    Object.keys( options ).forEach( function ( group ) {

        Object.keys( options[ group ] ).forEach( function ( key ) {

            rse.options[ group ][ key ] = options[ group ][ key ];
        } );
    } );

    ( desc.queue || [] ).forEach( function ( command ) {

        rse.asyncQueue.push( command );
    } );

    return rse;
}

/**
 * One widget: its explorer, a grid's column link, a broken-out trend's grid,
 * and the first load unless a tab loads it.
 *
 * @param  {Object} desc  the widget's description
 * @param  {Object} made  name => explorer, added to
 * @return {Object}       the widget's explorer
 */
export function renderWidget( desc, made ) {

    var rse = explorer( desc );

    made[ desc.name ] = rse;

    if ( desc.columnLink ) {

        publish( desc.name + 'link', desc.columnLink.url );

        rse.addLinkToColumn( desc.columnLink.column, desc.columnLink.url,
            desc.columnLink.values, desc.columnLink.except );
    }

    var breakdown = null;

    if ( desc.breakdown ) {

        breakdown = explorer( desc.breakdown );

        made[ desc.breakdown.name ] = breakdown;

        OWA.linkTrendToBreakdownGrid( rse, breakdown );
    }

    if ( desc.load ) {

        rse.load();

        if ( breakdown ) {
            breakdown.load();
        }
    }

    return rse;
}

/**
 * A metric set, after its widgets: the trend drawing the grid's top rows, and
 * the set's tab with the result sets it loads.
 *
 * @param {Object} desc  { report, link: [ trend, grid ], tab: { name, panel, label, rses: [ [ key, name ] ] } }
 * @param {Object} made  name => explorer
 */
export function renderSet( desc, made ) {

    if ( desc.link && made[ desc.link[0] ] && made[ desc.link[1] ] ) {

        OWA.linkTrendToGrid( made[ desc.link[0] ], made[ desc.link[1] ] );
    }

    if ( desc.tab ) {

        var tab = new OWA.report.tab( desc.tab.panel );

        publish( desc.tab.name, tab );

        tab.setLabel( desc.tab.label );

        ( desc.tab.rses || [] ).forEach( function ( pair ) {

            tab.addRse( pair[0], made[ pair[1] ] );
        } );

        OWA.report.item( desc.report ).addTab( tab );
    }
}

/**
 * The report's period picker, "as of" line and site filter.
 *
 * @param {Object} desc  { report, periodPicker, siteFilter }; periodPicker absent when the time controls are hidden
 */
export function startControls( desc ) {

    var report = OWA.report.item( desc.report );

    if ( desc.periodPicker ) {

        report.displayTimePeriodPicker( '#owa_timePeriodControl', desc.periodPicker );
        report.showAsOf( '#owa_reportAsOf' );
    }

    if ( desc.siteFilter ) {
        report.showSiteFilter();
    }
}

/**
 * Run one step, so that a step that throws stops only itself, as one inline
 * script throwing did. The error is still reported as uncaught.
 *
 * @param {Function} fn
 */
function isolated( fn ) {

    try {
        fn();
    } catch ( e ) {

        if ( typeof window.reportError === 'function' ) {
            window.reportError( e );
        } else {
            window.setTimeout( function () { throw e; } );
        }
    }
}

/**
 * Every widget, set and tab list under root, in document order, then the
 * report controls.
 *
 * @param  {ParentNode} root
 * @return {Object}     name => explorer, for every explorer made
 */
export function render( root ) {

    var made = {};
    var all  = root.querySelectorAll( [ WIDGET_SELECTOR, SET_SELECTOR, TABS_SELECTOR, CONTROLS_SELECTOR ].join( ', ' ) );

    Array.prototype.forEach.call( all, function ( el ) {

        isolated( function () {

            var desc = JSON.parse( el.textContent || '{}' );

            if ( el.matches( WIDGET_SELECTOR ) ) {
                renderWidget( desc, made );
            } else if ( el.matches( SET_SELECTOR ) ) {
                renderSet( desc, made );
            } else if ( el.matches( TABS_SELECTOR ) ) {
                OWA.report.item( desc.report ).createTabs();
            } else {
                startControls( desc );
            }
        } );
    } );

    return made;
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { render( document ); } );
    } else {
        render( document );
    }
}

export { OWA };
