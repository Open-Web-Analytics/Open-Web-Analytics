/**
 * Reporting bundle entry point.
 *
 * The reporting bundle is a webpack module graph, emitted as
 * owa.reporting-combined-min.js; report templates load that one file.
 *
 * IMPORT ORDER IS LOAD-BEARING. The jQuery plugin ecosystem registers onto $.fn
 * as a side effect at module-eval time, so the order below matters:
 *   - jquery first.
 *   - flot core -> time: 0.8 EXTRACTED the time axis out of core
 *     into jquery.flot.time.js, and owa.areachart.js uses xaxis.mode:"time", so
 *     time.js MUST load before the OWA area chart or its date axis breaks.
 *
 * The VENDOR plugins still reference a bare `jQuery`/`$` at eval time (they can't be
 * edited), so vendor-jquery-global.js -- the FIRST import below -- publishes jQuery on
 * window before any of them evaluate. ES modules run in source order, so that shim
 * completes (setting window.jQuery/$) before the vendor bodies execute and their free
 * `jQuery` references resolve to the global, and the reporting and tracker bundles share
 * one webpack config.
 *
 * The seven OWA reporting files are ES modules: owa.format.js exports
 * the OWA namespace and the six augmenters import it and mutate the same object, and every
 * file imports jQuery explicitly. The import graph orders owa.format.js before its augmenters
 * automatically; the side-effect imports below still fix the relative order of the
 * augmenters among themselves. owa.format.js also publishes window.OWA (see its tail) for the
 * report templates' inline <script> blocks.
 */

// FIRST: publish jQuery on window for the vendor plugins that read a free `jQuery`/`$`
// at eval time (chosen, flot) and for the report
// templates' inline <script> blocks. Must precede every vendor import below.
import './vendor-jquery-global.js';

// jQuery plugin ecosystem (order preserved from the retired concat).
import 'chosen-js/chosen.jquery.js';
import 'jquery.flot';                       // core
import 'jquery.flot/jquery.flot.time.js';   // must precede owa.areachart (xaxis.mode:"time")
/*
 * jquery.flot.resize.js is DELIBERATELY NOT IMPORTED. Two independent reasons,
 * and either alone would be enough:
 *
 * 1. IT DOES NOT WORK IN THIS BUNDLE. It inlines a 2010 "jQuery resize event"
 *    shim written as `(function($,e,t){...})(jQuery,this)`, taking the window
 *    from top-level `this`. Top-level `this` in an ES module is NOT the window,
 *    so its requestAnimationFrame polyfill calls `e.setTimeout` on something
 *    that has no setTimeout and throws on every resize.
 *
 * 2. IT COULD NOT HAVE WORKED ANYWAY. It polls a list of elements it was told
 *    about, and OWA.areaChart.setupAreaChart() REPLACES the chart element on
 *    every redraw -- every metric change, granularity change and refetch. The
 *    node it registered is then detached, a detached node reads as invisible,
 *    and it stops watching: a chart that shrinks with the window and never
 *    grows back.
 *
 * OWA.onWidthChange does the job: a ResizeObserver on the widget CONTAINER,
 * which is the element the layout sizes and the one thing never replaced. It
 * also covers a widget whose width changes without the window changing, which
 * is most of them on a container-query grid.
 */
// OWA reporting namespace: owa.format.js defines it (exports OWA + publishes window.OWA), the
// other six import { OWA } and augment it. Import order is load-bearing -- owa.format.js first.
import './owa.format.js';
// Modal dialogs on the browser's <dialog> (OWA.dialog).
import './owa.dialog.js';
// Tabs (OWA.tabs): a report's metric sets.
import './owa.tabs.js';
import './owa.report.js';
import './owa.resultSetExplorer.js';
import './owa.sparkline.js';
import './owa.realtime.js';
import './owa.areachart.js';
import './owa.sharechart.js';
// Filters as pills, in the report editor's widget panel and the funnel builder.
import './owa.filterpills.js';
import './owa.kpibox.js';
// The confirmation an irreversible action gets. Imported after owa.format.js like the
// rest; it augments OWA and delegates one document-level handler.
import './owa.confirm.js';
// The Add dialog on the Application Passwords screen.
import './owa.appPasswords.js';
import './owa.settingsoverride.js';
// The confirmation before a Data Retention save, worded by the server.
import './owa.retention.js';
// The report nav behind a menu button on a narrow screen.
import './owa.reportnav.js';
// The copy button on a code block (Template::codeBlock()).
import './owa.copy.js';
import './owa.appmode.js';
// The add-a-Profile form: shows only the fields the answers make relevant.
import './owa.profileform.js';
// The funnel builder's steps, and the + and X of repeatable condition rows: the
// constraint rows' buttons are bound per row inside the report builder, so
// reusing the markup does not reuse the behaviour. Each step's conditions use
// the goal event definition form's condition builder (owa.goalbuilder.js).
import './owa.funnelbuilder.js';
import './owa.goalbuilder.js';
// The custom report editor (custom_report_edit.php), started from the data the template hands over.
import './owa.customReportEdit.js';
// The funnel builder screen (visualization_edit.php).
import './owa.visualizationEdit.js';
// The header's notification bell and its account and help menus (header.php).
import './owa.header.js';
// A report's widgets and its controls (report_widgets.php, report.php), started from the data the templates hand over.
import './owa.reportWidgets.js';
// A report of rows the server computed, in the standard grid (the funnel, and a module's report built the same way).
import './owa.rowsReport.js';
// The custom reports list's New dialog (custom_reports.php).
import './owa.customReports.js';
// A success message fades on its own (msgs.php).
import './owa.transientStatus.js';
// The filter box over a Property's users (property_access.php).
import './owa.propertyAccess.js';

// window.jQuery / window.$ are published by vendor-jquery-global.js (first import);
// window.OWA (the ~166 template references) is published by owa.format.js itself.
