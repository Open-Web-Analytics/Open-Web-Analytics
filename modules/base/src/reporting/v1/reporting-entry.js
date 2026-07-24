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
 * The VENDOR plugins still reference a bare `jQuery`/`$` (they can't be edited), so
 * those free identifiers are rewritten to require('jquery') by webpack.ProvidePlugin
 * (see webpack.config.js) -- one shared jQuery instance for the whole plugin ecosystem.
 *
 * The seven OWA reporting files are now REAL ES modules (Phase 4 renovation): owa.js
 * exports the OWA namespace and the six augmenters import it and mutate the same
 * object, and every file imports jQuery explicitly (so ProvidePlugin no longer has to
 * reach them). The import graph orders owa.js before its augmenters automatically; the
 * side-effect imports below still fix the relative order of the augmenters among
 * themselves. owa.js also publishes window.OWA (see its tail) for the report templates'
 * inline <script> blocks.
 */

// jQuery + the 1.x->3.x compat bridge. jQuery itself is pulled in transitively by
// jquery-migrate's require('jquery') (and by ProvidePlugin wherever a plugin/OWA
// file references the free `jQuery`/`$`), so there is one shared instance; the
// `jQuery` identifier used for the window assignment below is provided the same way.
import 'jquery-migrate';

// jQuery plugin ecosystem (order preserved from the retired concat).
import 'jquery-ui-dist/jquery-ui.js';
import 'chosen-js/chosen.jquery.js';
import 'jquery-sparkline';
import 'jquery.flot';                       // core
import 'jquery.flot/jquery.flot.time.js';   // must precede owa.areachart (xaxis.mode:"time")
import 'jquery.flot/jquery.flot.resize.js';
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

// Expose jQuery on window for the report templates' inline <script> blocks (~32
// bare jQuery(...) calls across the .tpl/.php templates). window.OWA (the other
// ~166 template references) is published by owa.js itself.
window.jQuery = window.$ = jQuery;
