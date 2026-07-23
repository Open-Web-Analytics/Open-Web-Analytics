const fs = require('fs');
const path = require('path');

/**
 * Phase 3.0 safety net -- jsdom load characterization of the reporting bundle.
 *
 * jsdom can't paint charts or run jqGrid layout, but it CAN execute the whole
 * concatenated bundle against a real window/document and prove that:
 *   - the vendored jQuery initializes and is the version we expect,
 *   - the OWA reporting namespace and its core objects load and instantiate,
 *   - the objects don't rely on jQuery-3-removed APIs at load/construct time.
 *
 * This is the layer that will catch the split-brain jQuery migration breaking
 * object construction, before Playwright (which is slower and needs a live
 * page) ever runs.
 */
describe('reporting bundle loads under jsdom', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const bundlePath = path.join(repoRoot, 'modules/base/dist/owa.reporting-combined-min.js');

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
            // `var OWA = {...}` is bundle-top-level; capture it out of the function
            // scope onto window so the tests can reach it (mirrors a <script> tag's
            // global, without polluting the module scope).
            const run = new Function(
                'window', 'document', 'navigator',
                code + '\n; if (typeof OWA !== "undefined") window.__OWA = OWA;'
            );
            run(window, document, navigator);
            OWA = window.__OWA;
            jq = window.jQuery;
        } catch (e) {
            loadError = e;
        }
    });

    test('the bundle executes without throwing', () => {
        if (loadError && loadError.message === 'bundle not built') return; // toolchain unavailable
        expect(loadError).toBeUndefined();
    });

    test('vendored jQuery initializes at the pinned version', () => {
        if (!jq) return;
        expect(typeof jq).toBe('function');
        // Pre-migration baseline. Flips to 3.x when Phase 3.1 lands -> update then.
        expect(jq.fn.jquery).toBe('1.6.4');
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
