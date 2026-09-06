const fs = require('fs');
const path = require('path');

/**
 * Per-module asset build registration.
 *
 * The webpack build no longer hardcodes its products. Each module declares what it
 * builds in a build.manifest.json in its own directory, and webpack.config.js
 * DISCOVERS them by scanning modules/*.build.manifest.json -- the direct analogue of
 * how root composer.json merges modules/*.composer.json via
 * wikimedia/composer-merge-plugin. A module self-registers by dropping that one file
 * in its own dir; there is no central list to edit.
 *
 * These tests pin the discovery contract: webpack.config.js exports a config per
 * declared package, and each package resolves to the right webpack shape for its
 * type. Today only `base` ships build inputs.
 */
describe('per-module build manifest discovery', () => {

    const repoRoot = path.resolve(__dirname, '../..');
    const modulesDir = path.join(repoRoot, 'modules');
    const MANIFEST = 'build.manifest.json';

    // Every manifest in the tree, keyed by module name.
    const manifests = fs.readdirSync(modulesDir)
        .filter((m) => fs.existsSync(path.join(modulesDir, m, MANIFEST)))
        .map((m) => ({
            module: m,
            manifest: JSON.parse(
                fs.readFileSync(path.join(modulesDir, m, MANIFEST), 'utf8')
            ),
        }));

    test('at least one module declares a build manifest', () => {
        // Base ships the tracker + reporting products; if this ever hits zero the
        // discovery glob has silently stopped finding anything. On-disk module dirs
        // are PascalCase (PSR-4), so the base module's dir is 'Base'.
        expect(manifests.length).toBeGreaterThan(0);
        expect(manifests.map((m) => m.module)).toContain('Base');
    });

    test('every declared package is well-formed and its inputs exist', () => {
        for (const { module, manifest } of manifests) {
            const moduleDir = path.join(modulesDir, module);
            expect(Array.isArray(manifest.packages)).toBe(true);

            for (const pkg of manifest.packages) {
                expect(typeof pkg.name).toBe('string');
                expect(['js', 'css']).toContain(pkg.type);
                expect(typeof pkg.outputDir).toBe('string');

                // An OPTIONAL per-package licence, emitted verbatim beside the
                // bundle. If declared it must resolve, or the build silently ships
                // a package whose notice is missing.
                if (pkg.licence !== undefined) {
                    expect(typeof pkg.licence).toBe('string');
                    expect(fs.existsSync(path.join(moduleDir, pkg.licence))).toBe(true);
                }

                if (pkg.type === 'js') {
                    // A JS package points at one entry file that must exist.
                    expect(typeof pkg.entry).toBe('string');
                    expect(fs.existsSync(path.join(moduleDir, pkg.entry))).toBe(true);
                } else {
                    // A CSS package lists >=1 source file, all of which must exist.
                    expect(Array.isArray(pkg.files)).toBe(true);
                    expect(pkg.files.length).toBeGreaterThan(0);
                    for (const f of pkg.files) {
                        expect(fs.existsSync(path.join(moduleDir, f))).toBe(true);
                    }
                }
            }
        }
    });

    test('webpack.config.js exports one config per declared package', () => {
        // Load the real config (it runs the discovery over the tree) and assert it
        // produced exactly the packages the manifests declare, named module:package.
        const configs = require(path.join(repoRoot, 'webpack.config.js'));
        expect(Array.isArray(configs)).toBe(true);

        const expectedNames = manifests.flatMap(({ module, manifest }) =>
            manifest.packages.map((p) => `${module}:${p.name}`)
        ).sort();
        const actualNames = configs.map((c) => c.name).sort();
        expect(actualNames).toEqual(expectedNames);
    });

    test('js/css packages resolve to the right webpack shape', () => {
        const configs = require(path.join(repoRoot, 'webpack.config.js'));
        const byName = Object.fromEntries(configs.map((c) => [c.name, c]));

        for (const { module, manifest } of manifests) {
            for (const pkg of manifest.packages) {
                const cfg = byName[`${module}:${pkg.name}`];
                expect(cfg).toBeDefined();
                // The package name IS the emitted filename (keeps PHP paths stable).
                expect(Object.keys(cfg.entry)).toEqual([pkg.name]);

                if (pkg.type === 'js') {
                    // No ProvidePlugin anywhere anymore (Phase 4): the reporting entry
                    // publishes jQuery on window itself via its first import, so neither
                    // JS product injects jQuery at the config level.
                    const hasProvide = (cfg.plugins || []).some(
                        (p) => p && p.constructor && p.constructor.name === 'ProvidePlugin'
                    );
                    expect(hasProvide).toBe(false);
                    // splitVendors:false -> no chunk splitting.
                    if (!pkg.splitVendors) {
                        expect(cfg.optimization.splitChunks).toBe(false);
                    } else {
                        expect(cfg.optimization.splitChunks.cacheGroups.vendor.name)
                            .toBe(pkg.splitVendors);
                    }
                } else {
                    // CSS: mini-css-extract emits the combined stylesheet, and
                    // webpack-remove-empty-scripts (whose plugin class is exported as
                    // `WebpackPlugin`) drops the stub .js chunk a CSS-only entry makes.
                    const pluginNames = (cfg.plugins || []).map(
                        (p) => p && p.constructor && p.constructor.name
                    );
                    expect(pluginNames).toContain('MiniCssExtractPlugin');
                    expect(pluginNames).toContain('WebpackPlugin');
                }
            }
        }
    });

    /**
     * Per-package licence emission.
     *
     * OWA is GPL-2.0, but the TRACKER alone was relicensed BSD-3 in 2020 (#670) so
     * site owners can embed it on non-GPL pages. The notice therefore has to travel
     * with the tracker and must NOT be attached to anything else -- shipping a BSD
     * notice beside the GPL reporting bundle would misstate its licence.
     *
     * It rides as a sibling file rather than a banner inside the bundle: the tracker
     * loads on every tracked page view, and BSD-3 clause 2 lets a binary-form
     * redistribution carry the notice in accompanying materials. It also cannot live
     * only with the source, because the release tarball excludes modules/Base/src and
     * ships public/.
     */
    describe('per-package licence', () => {

        const configs = require(path.join(repoRoot, 'webpack.config.js'));
        const byName = Object.fromEntries(configs.map((c) => [c.name, c]));

        const copyPluginsFor = (name) =>
            (byName[name].plugins || []).filter(
                (p) => p && p.constructor && p.constructor.name === 'CopyPlugin'
            );

        test('the tracker declares a licence and every other JS package does not', () => {
            // Pinning both halves: that the tracker keeps its notice, and that the
            // mechanism has not been widened onto GPL output.
            const base = manifests.find((m) => m.module === 'Base').manifest;
            const js = base.packages.filter((p) => p.type === 'js');
            const withLicence = js.filter((p) => p.licence !== undefined).map((p) => p.name);

            expect(withLicence).toEqual(['owa.tracker.js']);
            expect(js.length).toBeGreaterThan(1);
        });

        test('a declared licence is emitted beside the bundle as LICENSE.txt', () => {
            const copies = copyPluginsFor('Base:owa.tracker.js');
            expect(copies).toHaveLength(1);

            const patterns = copies[0].patterns || copies[0].options.patterns;
            expect(patterns).toHaveLength(1);
            expect(patterns[0].to).toBe('LICENSE.txt');
            expect(patterns[0].from.endsWith('src/tracker/LICENSE.txt')).toBe(true);
            expect(fs.existsSync(patterns[0].from)).toBe(true);
        });

        test('the licence text is the BSD-3 notice, not the repo GPL', () => {
            // A swap to the GPL text here would be a silent relicence of the tracker.
            const base = manifests.find((m) => m.module === 'Base').manifest;
            const pkg = base.packages.find((p) => p.name === 'owa.tracker.js');
            const text = fs.readFileSync(
                path.join(modulesDir, 'Base', pkg.licence), 'utf8'
            );
            expect(text).toMatch(/Redistribution and use in source and binary forms/);
            expect(text).not.toMatch(/GNU GENERAL PUBLIC LICENSE/i);
        });

        test('a package with no licence gets no CopyPlugin', () => {
            // CSS packages legitimately use CopyPlugin for their url() deps, so this
            // only pins the JS side, where CopyPlugin exists solely for the licence.
            expect(copyPluginsFor('Base:owa.reporting-combined-min.js')).toHaveLength(0);
        });
    });
});
