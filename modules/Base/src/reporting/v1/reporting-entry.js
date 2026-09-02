/**
 * Reporting bundle entry point.
 *
 * The reporting bundle is a real webpack module graph (formerly a flat
 * WebpackConcatPlugin concat), emitted as owa.reporting-combined-min.js (same
 * filename as before -> zero PHP churn; report templates keep loading one file).
 *
 * IMPORT ORDER IS LOAD-BEARING. The jQuery plugin ecosystem registers onto $.fn
 * as a side effect at module-eval time, so the sequence below reproduces the exact
 * order the concat used:
 *   - jquery + jquery-migrate first (migrate bridges the 1.x->3.x API removals the
 *     legacy plugins still rely on).
 *   - flot core -> time -> resize -> pie: 0.8 EXTRACTED the time axis out of core
 *     into jquery.flot.time.js, and owa.areachart.js uses xaxis.mode:"time", so
 *     time.js MUST load before the OWA area chart or its date axis breaks.
 *
 * The VENDOR plugins still reference a bare `jQuery`/`$` at eval time (they can't be
 * edited), so vendor-jquery-global.js -- the FIRST import below -- publishes jQuery on
 * window before any of them evaluate. ES modules run in source order, so that shim
 * completes (setting window.jQuery/$) before the vendor bodies execute and their free
 * `jQuery` references resolve to the global. This replaced webpack.ProvidePlugin, which
 * used to rewrite those free identifiers -- so the reporting and tracker webpack configs
 * are now a single shared config again (no per-product ProvidePlugin to scope).
 *
 * The seven OWA reporting files are REAL ES modules (Phase 4 renovation): owa.js exports
 * the OWA namespace and the six augmenters import it and mutate the same object, and every
 * file imports jQuery explicitly. The import graph orders owa.js before its augmenters
 * automatically; the side-effect imports below still fix the relative order of the
 * augmenters among themselves. owa.js also publishes window.OWA (see its tail) for the
 * report templates' inline <script> blocks.
 */

// FIRST: publish jQuery on window for the vendor plugins that read a free `jQuery`/`$`
// at eval time (jquery-ui, chosen, sparkline, flot, jQote2) and for the report
// templates' inline <script> blocks. Must precede every vendor import below.
import './vendor-jquery-global.js';

// jQuery 1.x->3.x compat bridge (requires jquery itself via its own require('jquery')).
import 'jquery-migrate';

// jQuery plugin ecosystem (order preserved from the retired concat).
import 'jquery-ui-dist/jquery-ui.js';
import 'chosen-js/chosen.jquery.js';
import 'jquery-sparkline';
import 'jquery.flot';                       // core
import 'jquery.flot/jquery.flot.time.js';   // must precede owa.areachart (xaxis.mode:"time")
/*
 * jquery.flot.resize.js is DELIBERATELY NOT IMPORTED. Two independent reasons,
 * and either alone would be enough:
 *
 * 1. IT DOES NOT WORK IN THIS BUNDLE. It inlines a 2010 "jQuery resize event"
 *    shim written as `(function($,e,t){...})(jQuery,this)`, taking the window
 *    from top-level `this`. Top-level `this` in an ES module is NOT the window,
 *    so its requestAnimationFrame polyfill called `e.setTimeout` on something
 *    that has no setTimeout and threw on every resize -- measured, before any
 *    of this work, as two uncaught TypeErrors per window resize.
 *
 * 2. IT COULD NOT HAVE WORKED ANYWAY. It polls a list of elements it was told
 *    about, and OWA.areaChart.setupAreaChart() REPLACES the chart element on
 *    every redraw -- every metric change, granularity change and refetch. The
 *    node it registered is then detached, a detached node reads as invisible,
 *    and it stops watching. The symptom was a chart that shrank with the window
 *    and never grew back.
 *
 * OWA.onWidthChange replaces it: a ResizeObserver on the widget CONTAINER,
 * which is the element the layout sizes and the one thing never replaced. It
 * also covers what this plugin never could -- a widget whose width changed
 * without the window changing, which is most of them on a container-query grid.
 */
import 'jquery.flot/jquery.flot.pie.js';
import 'free-jqgrid/dist/jquery.jqgrid.min.js';
// jQote2 has no npm package -> stays vendored, imported as a side-effect module.
import './includes/jquery/jQote2/jquery.jqote2.min.js';

// OWA reporting namespace: owa.js defines it (exports OWA + publishes window.OWA), the
// other six import { OWA } and augment it. Import order is load-bearing -- owa.js first.
import './owa.js';
import './owa.report.js';
import './owa.resultSetExplorer.js';
import './owa.sparkline.js';
import './owa.areachart.js';
import './owa.piechart.js';
import './owa.kpibox.js';
// The confirmation an irreversible action gets. Imported after owa.js like the
// rest; it augments OWA and delegates one document-level handler.
import './owa.confirm.js';

// window.jQuery / window.$ are published by vendor-jquery-global.js (first import);
// window.OWA (the ~166 template references) is published by owa.js itself.
