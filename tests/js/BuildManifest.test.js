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
        // base ships the tracker + reporting products; if this ever hits zero the
        // discovery glob has silently stopped finding anything.
        expect(manifests.length).toBeGreaterThan(0);
        expect(manifests.map((m) => m.module)).toContain('base');
    });

    test('every declared package is well-formed and its inputs exist', () => {
        for (const { module, manifest } of manifests) {
            const moduleDir = path.join(modulesDir, module);
            expect(Array.isArray(manifest.packages)).toBe(true);

            for (const pkg of manifest.packages) {
                expect(typeof pkg.name).toBe('string');
                expect(['js', 'css']).toContain(pkg.type);
                expect(typeof pkg.outputDir).toBe('string');

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
                    // provideJquery -> a ProvidePlugin is attached; otherwise none.
                    const hasProvide = (cfg.plugins || []).some(
                        (p) => p && p.constructor && p.constructor.name === 'ProvidePlugin'
                    );
                    expect(hasProvide).toBe(!!pkg.provideJquery);
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
});
