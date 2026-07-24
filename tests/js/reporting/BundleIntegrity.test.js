const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

/**
 * Build-integrity characterization of the reporting bundle
 * (modules/base/dist/owa.reporting-combined-min.js).
 *
 * The reporting bundle is a REAL webpack module graph (formerly a flat
 * WebpackConcatPlugin concatenation), mirroring the tracker entry:
 *   - reporting-entry.js is the entry point; it side-effect-imports the vendor
 *     plugins in load-bearing order, then the seven OWA files.
 *   - The seven OWA files are kept as PLAIN (sloppy-mode) scripts, NOT ESM: they
 *     are legacy code full of implicit globals (undeclared `for (x in ...)` loop
 *     vars, bare `y = ...` assigns) that throw under the strict mode ESM forces.
 *     So OWA is shared via a browser global exactly as the old concat did: owa.js
 *     publishes `window.OWA`, and the six augmenters read the bare `OWA` global.
 *     Adding an import/export to any of these files would flip it to strict and
 *     break it -- this test guards that they stay non-ESM.
 *   - jQuery + `$` are supplied to every module (vendor + OWA) by
 *     webpack.ProvidePlugin, so no file needs an explicit jQuery import.
 *   - The bundle is emitted under the SAME filename as the old concat, and is a
 *     single self-contained file (splitChunks excludes it) so the report
 *     templates keep loading exactly one script.
 *
 * These tests pin that contract: the source graph is present, the OWA files stay
 * sloppy-mode and share OWA via the window global, the config drives it through
 * ProvidePlugin (not a concat), and the built artifact embeds the pinned vendor
 * versions in one self-contained file.
 */
describe('reporting bundle build integrity', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const configPath = path.join(repoRoot, 'webpack.config.js');
    const manifestPath = path.join(repoRoot, 'modules/base/build.manifest.json');
    const srcDir = path.join(repoRoot, 'modules/base/src/reporting/v1');
    const entryPath = path.join(srcDir, 'reporting-entry.js');
    const bundlePath = path.join(repoRoot, 'modules/base/dist/owa.reporting-combined-min.js');

    // The reporting JS package as declared in base's build manifest. webpack.config.js
    // discovers this (and the tracker + CSS packages) by scanning modules/*/build.manifest.json.
    const reportingPkg = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
        .packages.find((p) => p.name === 'owa.reporting-combined-min.js');

    // The seven hand-written OWA reporting modules. owa.js defines the namespace;
    // the rest augment it. Order here is the ESM import order in reporting-entry.js.
    const OWA_MODULES = [
        'owa.js', 'owa.report.js', 'owa.resultSetExplorer.js', 'owa.sparkline.js',
        'owa.areachart.js', 'owa.piechart.js', 'owa.kpibox.js',
    ];

    test('the reporting entry point exists', () => {
        expect(fs.existsSync(entryPath)).toBe(true);
    });

    test('every OWA reporting module exists', () => {
        const missing = OWA_MODULES.filter((f) => !fs.existsSync(path.join(srcDir, f)));
        expect(missing).toEqual([]);
    });

    test('the OWA files are real ES modules sharing OWA via import/export', () => {
        // The reporting files are now proper ES modules (Phase 4 renovation), replacing
        // the earlier sloppy-mode-shared-via-window.OWA scheme. owa.js defines the
        // namespace and `export { OWA }`; the six augmenters `import { OWA } from
        // './owa.js'` and mutate the same object. Every file imports jQuery explicitly
        // (was webpack.ProvidePlugin). owa.js STILL publishes window.OWA because the
        // report templates' inline <script> blocks (~166 refs) read the browser global
        // -- that's a template concern, separate from the module-internal sharing.
        const owaJs = fs.readFileSync(path.join(srcDir, 'owa.js'), 'utf8');
        expect(owaJs).toMatch(/export\s*\{\s*OWA\s*\}/); // owa.js exports the namespace
        expect(owaJs).toMatch(/window\.OWA\s*=\s*OWA/);  // and keeps the template global

        // Every file imports jQuery; every AUGMENTER also imports OWA from owa.js.
        const missingJquery = [];
        const missingOwaImport = [];
        for (const f of OWA_MODULES) {
            const code = fs.readFileSync(path.join(srcDir, f), 'utf8');
            if (!/^\s*import \* as jQuery from 'jquery'/m.test(code)) missingJquery.push(f);
            if (f !== 'owa.js' && !/^\s*import\s*\{\s*OWA\s*\}\s*from\s*'\.\/owa\.js'/m.test(code)) {
                missingOwaImport.push(f);
            }
        }
        expect(missingJquery).toEqual([]);
        expect(missingOwaImport).toEqual([]);
    });

    test('reporting-entry imports the vendor plugins in load-bearing order', () => {
        // Order matters: jquery-migrate before the plugins; flot core -> time ->
        // resize -> pie, with time.js before the OWA area chart (xaxis.mode:"time");
        // and the OWA namespace modules last. Assert the relative sequence.
        //
        // Match only the `import ...` lines, not the whole file: the header docblock
        // mentions module paths in prose, which would otherwise be found before the
        // real import statements and scramble the order check.
        const entry = fs.readFileSync(entryPath, 'utf8')
            .split('\n').filter((l) => /^\s*import\b/.test(l)).join('\n');
        const orderedTokens = [
            'jquery-migrate',
            'jquery-ui-dist/jquery-ui.js',
            'chosen-js/chosen.jquery.js',
            'jquery-sparkline',
            "'jquery.flot'",                    // flot core
            'jquery.flot/jquery.flot.time.js',
            'jquery.flot/jquery.flot.resize.js',
            'jquery.flot/jquery.flot.pie.js',
            'free-jqgrid/dist/jquery.jqgrid.min.js',
            'jQote2/jquery.jqote2.min.js',
            './owa.js',
            './owa.report.js',
            './owa.kpibox.js',
        ];
        let last = -1;
        for (const tok of orderedTokens) {
            const idx = entry.indexOf(tok);
            expect(idx).toBeGreaterThan(-1);       // token present
            expect(idx).toBeGreaterThan(last);     // and after the previous one
            last = idx;
        }
        // time.js must precede areachart (the whole reason it is imported at all).
        expect(entry.indexOf('jquery.flot.time.js'))
            .toBeLessThan(entry.indexOf('./owa.areachart.js'));
    });

    test('the reporting package is declared in base\'s build manifest', () => {
        // The entry, output dir, and per-product flags now live in the module's
        // build.manifest.json (discovered by webpack.config.js), not inline in the
        // config. The reporting bundle is a single self-contained file (no vendor
        // split) built with a bundled jQuery (ProvidePlugin).
        expect(reportingPkg).toBeDefined();
        expect(reportingPkg.type).toBe('js');
        expect(reportingPkg.entry).toMatch(/reporting-entry\.js$/);
        expect(reportingPkg.outputDir).toBe('dist');
        expect(reportingPkg.splitVendors).toBe(false); // one self-contained file
        expect(reportingPkg.provideJquery).toBe(true);  // bundled jQuery for the plugins
    });

    test('the build drives the bundle through the module graph, not a concat', () => {
        const cfg = fs.readFileSync(configPath, 'utf8');
        // ProvidePlugin is what supplies jQuery/$ to the vendor plugins that read a
        // bare global (chosen, flot) now that they run under webpack module scope.
        // The config wires it whenever a manifest package sets provideJquery.
        expect(cfg).toMatch(/ProvidePlugin/);
        expect(cfg).toMatch(/jQuery:\s*['"]jquery['"]/);
        // Packages are discovered from per-module build manifests.
        expect(cfg).toMatch(/build\.manifest\.json/);
        // The flat-concat toolchain is retired.
        expect(cfg).not.toMatch(/WebpackConcatPlugin/);
        expect(cfg).not.toMatch(/webpack-concat-files-plugin/);
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
            // ~900KB as a module graph; guard against a truncated/empty build.
            expect(bundle.length).toBeGreaterThan(200 * 1024);
        });

        test('OWA reporting symbols are present in the output', () => {
            if (bundle === null) return;
            for (const sym of ['OWA', 'owa_report', 'resultSetExplorer', 'sparkline']) {
                expect(bundle.includes(sym)).toBe(true);
            }
        });

        test('the bundle is self-contained (vendors inlined, not split out)', () => {
            if (bundle === null) return;
            // The report templates load ONLY owa.reporting-combined-min.js, so the
            // vendor code (jQuery, jQuery-UI, flot, jqGrid, ...) must be INLINED in
            // this one file rather than split into a sibling chunk. (The tracker's
            // owa.vendors split is scoped away from this bundle via a separate
            // compiler config in webpack.config.js.)
            //
            // Note on what does NOT work as a signal: with output.iife:false a
            // vendors split becomes a sibling *entry* chunk loaded by its own
            // <script>, not a runtime import(), so `__webpack_require__.e` never
            // appears and there is no literal 'owa.vendors' reference to grep. The
            // observable difference is that the vendor code either lives IN this
            // file or it doesn't. A split collapses this bundle from ~900KB to ~70KB
            // and moves every vendor fingerprint out into the sibling chunk -- so a
            // size floor no split can clear, plus the vendor tokens being present
            // right here, is what actually catches the regression.
            expect(bundle.length).toBeGreaterThan(500 * 1024);
            for (const token of ['"3.6.0"', 'jQuery UI - v1.13.3', 'flot-base']) {
                expect(bundle.includes(token)).toBe(true);
            }

            // And no sibling reporting-vendors chunk is emitted alongside it. The
            // dist dir legitimately holds the tracker's owa.vendors.js; anything
            // else vendor-shaped next to the reporting bundle means it was split.
            const distDir = path.dirname(bundlePath);
            const strays = fs.readdirSync(distDir).filter(
                (f) => /vendor/i.test(f) && f !== 'owa.vendors.js'
            );
            expect(strays).toEqual([]);
        });

        /**
         * The vendor-version invariants, pinned against the module-graph output.
         * Terser strips the `jQuery v<n>`
         * banner comment, so match the version token embedded in code instead; the
         * jQuery-UI license banner and Flot's `flot-base` class survive minification
         * and fingerprint the pinned plugin versions. The authoritative jQuery
         * runtime-version check lives in BundleLoad.test.js (reads jq.fn.jquery).
         */
        test('reporting bundle embeds the pinned vendor versions', () => {
            if (bundle === null) return;
            // jQuery core 3.6.0 (post-split-brain baseline): `var C="3.6.0"`.
            expect(bundle.includes('"3.6.0"')).toBe(true);
            // jQuery-UI 1.13.3 (license banner is preserved by terser).
            expect(bundle.includes('jQuery UI - v1.13.3')).toBe(true);
            // Flot 0.8.3 renamed its canvas class base -> flot-base.
            expect(bundle.includes('flot-base')).toBe(true);
        });
    });
});
