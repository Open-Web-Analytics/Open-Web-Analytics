/*
 * OWA reporting jQuery migration shim (Phase 3.2).
 *
 * The reporting bundle was flipped from jQuery 1.6.4 to jQuery 3.x. jquery-migrate
 * (loaded just before this file) restores most of the 1.x -> 3.x removals that the
 * old vendored plugins rely on (andSelf, $.attrFn, deprecated event shorthands),
 * but it intentionally does NOT restore `jQuery.browser` -- that API was removed
 * back in jQuery 1.9 and dropped from the 3.x line of migrate.
 *
 * It bridges two removed-API families that the still-vendored 1.6/1.8-era libs
 * rely on and that migrate 3.x does NOT restore:
 *
 *   1. `$.browser` -- removed in jQuery 1.9.
 *        - jquery-ui-1.8.12.custom reads `$.browser.msie` / `.version` at RUNTIME.
 *        (jquery.sparkline 1.2.1 used to read `$.browser.msie` at LOAD time too,
 *        but it was upgraded to jquery-sparkline 2.4.0, which no longer does.)
 *   2. `$.curCSS(elem, name)` -- a getter alias for `$.css` removed in jQuery 1.8.
 *        - jquery-ui-1.8.12.custom calls it ~19 times at RUNTIME (positioning,
 *          resizable, etc.); without it the UI widgets throw
 *          "curCSS is not a function" and dependent renders (e.g. the jqGrid rows
 *          built inside a UI-managed container) never appear.
 *
 * This is a TEMPORARY migration bridge: delete it once sparkline is replaced with
 * a maintained build and jQuery-UI 1.8.12 is upgraded/dropped (both tracked in the
 * Phase 3.2 plan). No OWA code calls either API -- they exist solely to keep the
 * legacy vendored plugins alive during the migration.
 */
(function ($) {
    if (!$) {
        return;
    }
    // Modern browsers are never IE; the only property the vendored libs branch on
    // is `.msie` (and, in jQuery-UI, `.version`). Report a non-IE, modern UA.
    if (!$.browser) {
        $.browser = {
            msie: false,
            mozilla: false,
            webkit: false,
            opera: false,
            safari: false,
            version: '0'
        };
    }
    // $.curCSS(elem, name, force) was historically the computed-style getter that
    // $.css delegates to; jQuery-UI 1.8.12 still calls the old public alias.
    if (!$.curCSS) {
        $.curCSS = function (elem, name) {
            return $.css(elem, name);
        };
    }
}(window.jQuery));
