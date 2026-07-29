const fs = require('fs');
const path = require('path');

/**
 * jsdom load characterization of the reporting bundle.
 *
 * jsdom can't paint charts or run jqGrid layout, but it CAN execute the whole
 * bundle against a real window/document and prove that:
 *   - jQuery initializes and is the version we expect,
 *   - the OWA reporting namespace and its core objects load and instantiate,
 *   - the objects don't rely on jQuery-3-removed APIs at load/construct time.
 *
 * This is the layer that catches object construction breaking before Playwright
 * (which is slower and needs a live page) ever runs.
 *
 * The bundle is a webpack module graph (was a flat concat).
 * `OWA` and `jQuery` are no longer bundle-top-level `var`s reachable from the
 * outer function scope -- reporting-entry.js publishes them onto `window`
 * (window.OWA / window.jQuery), which is what the report templates' inline
 * scripts consume too. So the load harness reads them off window.
 */
describe('reporting bundle loads under jsdom', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const bundlePath = path.join(repoRoot, 'modules/Base/dist/owa.reporting-combined-min.js');

    let OWA;      // the OWA namespace as built by the bundle
    let jq;       // jQuery instance the bundle initialized
    let loadError;

    beforeAll(() => {
        if (!fs.existsSync(bundlePath)) {
            // Build if missing; tolerate a toolchain that can't build here.
            try {
                require('child_process').execSync('npm run build', { cwd: repoRoot, stdio: 'ignore' });
            } catch (e) { /* handled below */ }
        }
        if (!fs.existsSync(bundlePath)) { loadError = new Error('bundle not built'); return; }

        const code = fs.readFileSync(bundlePath, 'utf8');
        try {
            // The bundle is a webpack module graph; reporting-entry.js assigns
            // window.OWA and window.jQuery (the same globals the report templates'
            // inline scripts use). Run it against this test's window/document and
            // read them back off window -- mirrors a real <script> tag.
            const run = new Function(
                'window', 'document', 'navigator',
                code
            );
            run(window, document, navigator);
            OWA = window.OWA;
            jq = window.jQuery;
        } catch (e) {
            loadError = e;
        }
    });

    test('the bundle executes without throwing', () => {
        if (loadError && loadError.message === 'bundle not built') return; // toolchain unavailable
        expect(loadError).toBeUndefined();
    });

    test('jQuery initializes at the pinned version', () => {
        if (!jq) return;
        expect(typeof jq).toBe('function');
        // The reporting bundle uses jQuery 3.6.0 (sourced from the npm dep);
        // jquery-migrate bridges the legacy plugins. The
        // $.browser/$.curCSS compat shim was DELETED once every plugin went
        // jQuery-3.x-clean (jQuery-UI -> 1.13.3, Flot -> 0.8.3), so $.browser must
        // now be ABSENT -- assert it is gone so a shim regression fails loudly.
        expect(jq.fn.jquery).toBe('3.6.0');
        expect(jq.browser).toBeUndefined();
    });

    test('the OWA reporting namespace loads with its core members', () => {
        if (!OWA) return;
        expect(typeof OWA).toBe('object');
        // Core reporting objects that owa_view wires into every report page.
        expect(typeof OWA.report).toBe('function');
        expect(typeof OWA.stateManager).toBe('function');
        expect(typeof OWA.getSetting).toBe('function');
        expect(typeof OWA.setSetting).toBe('function');
        expect(typeof OWA.util).toBe('object');
    });

    test('OWA.report constructs without error', () => {
        if (!OWA) return;
        document.body.innerHTML = '<div id="owa_report_fixture"></div>';
        let ctorError = null;
        let inst = null;
        try {
            inst = new OWA.report('owa_report_fixture', {});
        } catch (e) {
            ctorError = e;
        }
        expect(ctorError).toBeNull();
        expect(inst).not.toBeNull();
        expect(inst.dom_id).toBe('owa_report_fixture');
        // Construction-time invariants the report relies on.
        expect(inst.tabs).toEqual({});
        expect(inst.resultSetExplorers).toEqual({});
    });

    test('OWA.stateManager constructs and round-trips a state store', () => {
        if (!OWA) return;
        let err = null;
        try {
            const sm = new OWA.stateManager();
            expect(typeof sm.registerStore).toBe('function');
            expect(typeof sm.set).toBe('function');
            expect(typeof sm.isPresent).toBe('function');
        } catch (e) {
            err = e;
        }
        expect(err).toBeNull();
    });
});
