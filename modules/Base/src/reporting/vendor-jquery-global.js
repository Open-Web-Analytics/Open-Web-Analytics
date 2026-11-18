/**
 * Publishes jQuery on the global object for the legacy vendor plugins.
 *
 * The reporting bundle's vendored jQuery plugins are UMD/IIFE files that cannot be
 * edited, and several read a FREE `jQuery`/`$` at module-eval time rather than
 * require()-ing it:
 *   - chosen-js:      `$ = jQuery` (chosen.jquery.js ~610)
 *   - jquery.flot (core/time): `(function($){...})(jQuery)`
 *
 * This module imports jQuery and assigns it to window BEFORE the plugins run. It MUST be the FIRST import in reporting-entry.js -- ES modules are
 * evaluated in source order, so this runs to completion (setting window.jQuery /
 * window.$) before any vendor module's body executes, and their free `jQuery`
 * references resolve to the global at eval time.
 *
 * It also serves the report templates' inline <script> blocks (~32 bare jQuery(...)
 * calls + ~166 OWA refs), which read window.jQuery / window.$ at runtime -- so this
 * one publish covers both the eval-time vendor need and the runtime template need.
 */
import * as jQuery from 'jquery';

window.jQuery = window.$ = jQuery;

export default jQuery;
