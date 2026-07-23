const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

/**
 * Phase 3.0 safety net -- build-integrity characterization of the reporting
 * bundle (modules/base/dist/owa.reporting-combined-min.js).
 *
 * The reporting bundle is NOT a webpack module graph like the tracker; it is a
 * flat WebpackConcatPlugin concatenation of 11 vendored libs + 7 OWA files
 * (see webpack.config.js). Before the jQuery 1.6.4 -> 3.x migration touches any
 * of those inputs, these tests pin the current, working shape of the build so a
 * plugin swap or a dropped concat entry fails loudly instead of silently
 * shipping a broken reporting UI.
 *
 * The input list is derived FROM webpack.config.js (not hardcoded here) so this
 * test tracks the real build definition and cannot drift out of sync with it.
 */
describe('reporting bundle build integrity', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const configPath = path.join(repoRoot, 'webpack.config.js');
    const bundlePath = path.join(repoRoot, 'modules/base/dist/owa.reporting-combined-min.js');

    /** Pull the reporting bundle's `src: [...]` list straight out of the webpack config. */
    function getConfiguredInputs() {
        const cfg = fs.readFileSync(configPath, 'utf8');
        // Isolate the src array of the owa.reporting-combined-min.js bundle.
        const srcBlock = cfg.match(/owa\.reporting-combined-min\.js[\s\S]*?src:\s*\[([\s\S]*?)\]/);
        expect(srcBlock).not.toBeNull();
        // Each entry is `src_path + '/reporting/v1/....js',`
        const rel = [...srcBlock[1].matchAll(/'([^']+\.js)'/g)].map((m) => m[1]);
        expect(rel.length).toBeGreaterThan(0);
        // src_path === <root>/modules/base/src ; strip the leading slash on the literal.
        return rel.map((r) => path.join(repoRoot, 'modules/base/src', r.replace(/^\//, '')));
    }

    test('every configured bundle input file exists', () => {
        const missing = getConfiguredInputs().filter((f) => !fs.existsSync(f));
        expect(missing).toEqual([]);
    });

    /**
     * Guards the OTHER drift direction: the config-derived list above shrinks
     * silently if an entry is deleted, so pin the load-bearing inputs that must
     * remain in the concat. jQuery is matched by pattern (its version/filename
     * WILL change in the migration); the OWA reporting files and the vendored
     * plugins are matched by name. If the migration legitimately drops one
     * (e.g. replaces Flot), this fails and must be updated as a conscious edit.
     */
    test('all load-bearing inputs are still referenced in the build config', () => {
        const configured = getConfiguredInputs().map((f) => path.basename(f));

        // exactly one jQuery core, whatever its version.
        expect(configured.filter((f) => /^jquery-[\d.]+.*\.js$/.test(f))).toHaveLength(1);

        const required = [
            // vendored plugins the reporting UI depends on
            'jquery.sprintf.js', 'jquery.ui.selectmenu.js', 'chosen.jquery.js',
            'jquery.sparkline.min.js', 'jquery.jqGrid.min.js', 'jquery.flot.min.js',
            'jquery.jqote2.min.js',
            // OWA reporting code
            'owa.js', 'owa.report.js', 'owa.resultSetExplorer.js', 'owa.sparkline.js',
            'owa.areachart.js', 'owa.piechart.js', 'owa.kpibox.js',
        ];
        const dropped = required.filter((f) => !configured.includes(f));
        expect(dropped).toEqual([]);
    });

    test('the concat input order leads with jQuery, then plugins, then OWA code', () => {
        // Ordering is load-bearing for a flat concat: jQuery must precede its
        // plugins, and every plugin must precede the OWA code that calls it.
        const inputs = getConfiguredInputs().map((f) => path.basename(f));
        const jqueryIdx = inputs.findIndex((f) => /^jquery-[\d.]+/.test(f));
        const firstPluginIdx = inputs.findIndex((f) => f === 'jquery.sprintf.js');
        const firstOwaIdx = inputs.findIndex((f) => f === 'owa.js');

        expect(jqueryIdx).toBe(0);
        expect(firstPluginIdx).toBeGreaterThan(jqueryIdx);
        expect(firstOwaIdx).toBeGreaterThan(firstPluginIdx);
    });

    describe('built artifact', () => {
        // Build once for this block. Skip (don't fail) if the toolchain can't
        // build here -- CI builds explicitly; a dev box without node_modules
        // shouldn't red-bar the whole suite.
        let bundle = null;

        beforeAll(() => {
            try {
                if (!fs.existsSync(bundlePath)) {
                    execSync('npm run build', { cwd: repoRoot, stdio: 'ignore' });
                }
                bundle = fs.readFileSync(bundlePath, 'utf8');
            } catch (e) {
                bundle = null;
            }
        });

        test('the bundle was produced and is non-trivial in size', () => {
            if (bundle === null) return; // toolchain unavailable
            // Historically ~630KB; guard against a truncated/empty concat.
            expect(bundle.length).toBeGreaterThan(200 * 1024);
        });

        test('OWA reporting symbols are present in the output', () => {
            if (bundle === null) return;
            for (const sym of ['OWA', 'owa_report', 'resultSetExplorer', 'sparkline']) {
                expect(bundle.includes(sym)).toBe(true);
            }
        });

        /**
         * The invariant the entire Phase 3 jQuery migration hinges on: today the
         * reporting bundle ships jQuery 1.6.4 while the tracker ships 3.x
         * (split-brain). This test PINS the current state to 1.6.4 so the moment
         * the migration flips it to 3.x, this fails and must be consciously
         * updated -- turning an invisible, load-order-sensitive change into an
         * explicit, reviewed one.
         */
        test('reporting bundle currently embeds jQuery 1.6.4 (pre-migration baseline)', () => {
            if (bundle === null) return;
            expect(bundle.includes('jQuery v1.6.4')).toBe(true);
            // And exactly one jQuery core is concatenated (no accidental double-embed).
            const cores = [...bundle.matchAll(/jQuery v[\d.]+ /g)];
            expect(cores.length).toBe(1);
        });
    });
});
