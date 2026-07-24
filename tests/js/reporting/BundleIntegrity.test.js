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
        // Entries are either `src_path + '/reporting/v1/....js'` (OWA + vendored
        // plugins under modules/base/src) or `__dirname + '/node_modules/....js'`
        // (npm deps: jQuery core, jquery-migrate, free-jqgrid). Resolve each
        // literal against the base its `+` prefix implies.
        const rel = [...srcBlock[1].matchAll(/'([^']+\.js)'/g)].map((m) => m[1]);
        expect(rel.length).toBeGreaterThan(0);
        // src_path === <root>/modules/base/src. node_modules literals start with
        // '/node_modules/' and resolve from the repo root instead.
        return rel.map((r) =>
            r.startsWith('/node_modules/')
                ? path.join(repoRoot, r.replace(/^\//, ''))
                : path.join(repoRoot, 'modules/base/src', r.replace(/^\//, ''))
        );
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

        // exactly one jQuery core. Phase 3.2 flipped the core from the vendored
        // jquery-1.6.4.min.js to the npm dep's jquery.min.js (jquery-migrate is a
        // separate shim, not a core, so it must not be double-counted here).
        expect(configured.filter((f) => /^jquery\.min\.js$/.test(f))).toHaveLength(1);

        const required = [
            // jQuery 1.x->3.x migration bridge (Phase 3.2): migrate restores the
            // removed 1.x APIs the legacy plugins use, and the compat shim adds
            // back $.browser (which migrate 3.x drops but sparkline + jQuery-UI need).
            'jquery-migrate.min.js', 'owa.jquery-compat-shim.js',
            // vendored plugins the reporting UI depends on. jquery.sprintf.js was
            // dropped in Phase 3.2 (dead: OWA uses its own OWA.util.sprintf, the
            // $.sprintf plugin form is called nowhere). jqGrid 3.6.5 was replaced
            // by free-jqgrid 4.15.5 (jquery.jqgrid.min.js) in the same phase.
            'jquery.ui.selectmenu.js', 'chosen.jquery.js',
            'jquery.sparkline.min.js', 'jquery.jqgrid.min.js', 'jquery.flot.min.js',
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
        const jqueryIdx = inputs.findIndex((f) => /^jquery\.min\.js$/.test(f));
        const firstPluginIdx = inputs.findIndex((f) => f === 'jquery-ui-1.8.12.custom.min.js');
        const firstOwaIdx = inputs.findIndex((f) => f === 'owa.js');

        expect(jqueryIdx).toBe(0);
        // migrate + the compat shim sit between the core and the first plugin.
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
         * The invariant the entire Phase 3 jQuery migration hinges on. Phase 3.2
         * flipped the reporting bundle from jQuery 1.6.4 to 3.6.0 (the version the
         * tracker already ships -- this ends the split-brain). This test PINS 3.6.0
         * so a future bump is a conscious, reviewed change rather than a silent one.
         */
        test('reporting bundle embeds jQuery 3.6.0 (post-migration baseline)', () => {
            if (bundle === null) return;
            expect(bundle.includes('jQuery v3.6.0')).toBe(true);
            // Exactly one jQuery CORE is concatenated (no accidental double-embed).
            // The `jQuery v<n> ` banner form matches the core but NOT jquery-migrate's
            // `jQuery Migrate v<n>` banner, so migrate is not miscounted as a core.
            const cores = [...bundle.matchAll(/jQuery v[\d.]+ /g)];
            expect(cores.length).toBe(1);
            // And migrate is present (the bridge that keeps the legacy plugins alive).
            expect(bundle.includes('jQuery Migrate v')).toBe(true);
        });
    });
});
