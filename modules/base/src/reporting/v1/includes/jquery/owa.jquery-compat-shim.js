/*
 * OWA reporting jQuery migration shim (Phase 3.2).
 *
 * The reporting bundle was flipped from jQuery 1.6.4 to jQuery 3.x. jquery-migrate
 * (loaded just before this file) restores most of the 1.x -> 3.x removals that the
 * old vendored plugins rely on (andSelf, $.attrFn, deprecated event shorthands),
 * but it intentionally does NOT restore `jQuery.browser` -- that API was removed
 * back in jQuery 1.9 and dropped from the 3.x line of migrate.
 *
 * It bridges the ONE removed API a still-vendored plugin reads and that migrate
 * 3.x does NOT restore:
 *
 *   `$.browser` -- removed in jQuery 1.9.
 *        - Flot 0.7's pie plugin reads `$.browser.msie` at RUNTIME (an IE-only
 *          arc-angle nudge in the draw path). Flot 0.7 is still vendored.
 *        (Historically jquery-ui-1.8.12.custom read `$.browser.msie`/`.version`
 *        and sparkline 1.2.1 read `$.browser.msie` at load, but both were
 *        upgraded to jQuery-3.x-clean builds -- jquery-ui-dist 1.13.3 and
 *        jquery-sparkline 2.4.0 -- so Flot is the last consumer.)
 *
 * `$.curCSS` was also shimmed for jQuery-UI 1.8.12, which called it ~19 times at
 * runtime; the 1.13.3 upgrade dropped that consumer, so the curCSS shim is gone.
 *
 * This is a TEMPORARY migration bridge: delete it once Flot 0.7 is replaced with a
 * maintained build (tracked in the Phase 3.2/3.3 plan). No OWA code calls
 * `$.browser` -- it exists solely to keep the vendored Flot pie plugin alive.
 */
(function ($) {
    if (!$) {
        return;
    }
    // Modern browsers are never IE; the only property Flot's pie plugin branches
    // on is `.msie`. Report a non-IE, modern UA.
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
}(window.jQuery));
