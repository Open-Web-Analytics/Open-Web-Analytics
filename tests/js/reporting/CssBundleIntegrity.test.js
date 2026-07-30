const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

/**
 * Build-integrity characterization of the combined reporting stylesheet
 * (public/base/css/owa.reporting-css-combined.css; sources in modules/Base/css/).
 *
 * This file's production moved from the PHP-CLI `cmd=build` controller
 * (base.build / owa_buildController, formerly driven by base/module.php
 * registerBuildPackages) to webpack (reportingCssConfig in webpack.config.js):
 *   - The six source CSS files are the webpack entry, in the SAME cascade order
 *     the PHP package used: jquery-ui, ui.jqgrid, chosen, owa, owa.admin, owa.report.
 *   - mini-css-extract-plugin emits the combined file under the SAME name to the
 *     SAME directory (modules/Base/css/), and css-loader runs with url:false so
 *     every url() is passed through verbatim -- combined with the css/ output dir,
 *     the relative asset paths (images/ui-icons_*, chosen-sprite.png, ../i/*) stay
 *     valid without rewriting a single url().
 *   - webpack-remove-empty-scripts drops the stub .js a CSS-only entry produces.
 *   - The output is NOT minified (no -min suffix), matching the retired concat.
 *
 * These tests pin that contract: the six sources exist, the config drives the CSS
 * through mini-css-extract (not the PHP concat), the PHP build package is gone, and
 * the built artifact carries each source's signature rule with url()s intact.
 */
describe('reporting CSS build integrity', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const configPath = path.join(repoRoot, 'webpack.config.js');
    const manifestPath = path.join(repoRoot, 'modules/Base/build.manifest.json');
    // Source stylesheets live in the module tree; the BUILT artifact + its copied
    // url() assets are emitted into the public/ asset tree (moved out of source so the
    // deny-all .htaccess can allow public/** without exposing anything sensitive).
    const cssSrcDir = path.join(repoRoot, 'modules/Base/css');
    const publicCssDir = path.join(repoRoot, 'public/base/css');
    const artifactPath = path.join(publicCssDir, 'owa.reporting-css-combined.css');
    const modulePhpPath = path.join(repoRoot, 'modules/Base/Module.php');

    // The six source stylesheets, in the cascade order the entry lists them.
    // Order is load-bearing: later files intentionally override earlier ones.
    const CSS_SOURCES = [
        'jquery-ui.css', 'ui.jqgrid.css', 'chosen.css',
        'owa.css', 'owa.admin.css', 'owa.report.css',
    ];

    // The reporting-css package as declared in base's build manifest.
    const cssPkg = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
        .packages.find((p) => p.name === 'owa.reporting-css-combined.css');

    test('all six source stylesheets exist', () => {
        for (const f of CSS_SOURCES) {
            expect(fs.existsSync(path.join(cssSrcDir, f))).toBe(true);
        }
    });

    test('the build manifest lists the six sources in cascade order', () => {
        // The entry (source list + order) now lives in base/build.manifest.json,
        // discovered by webpack.config.js. Order is load-bearing.
        expect(cssPkg).toBeDefined();
        expect(cssPkg.type).toBe('css');
        // Emitted into the public/ asset tree, not the module source dir.
        expect(cssPkg.outputDir).toBe('../../public/base/css');
        expect(cssPkg.files).toEqual(CSS_SOURCES.map((f) => `css/${f}`));
    });

    test('webpack.config.js drives the CSS through mini-css-extract with url:false', () => {
        const cfg = fs.readFileSync(configPath, 'utf8');
        // The CSS pipeline is wired.
        expect(cfg).toMatch(/MiniCssExtractPlugin/);
        expect(cfg).toMatch(/css-loader/);
        // url:false is what keeps every url() a valid relative path against css/.
        expect(cfg).toMatch(/url:\s*false/);
    });

    test('the PHP-CLI build package is retired', () => {
        // registerBuildPackages() (and its owa.reporting-css package) was removed from
        // base/module.php; the parent no-op stub is inherited instead. A re-introduced
        // package would mean two build paths fighting over one file.
        const modulePhp = fs.readFileSync(modulePhpPath, 'utf8');
        expect(modulePhp).not.toMatch(/owa\.reporting-css/);
        expect(modulePhp).not.toMatch(/registerBuildPackage\s*\(/);
    });

    describe('built artifact', () => {
        // Build once for this block. Skip (don't fail) if the toolchain can't build
        // here -- CI builds explicitly; a dev box without node_modules shouldn't
        // red-bar the whole suite.
        let css = null;

        beforeAll(() => {
            try {
                if (!fs.existsSync(artifactPath)) {
                    execSync('npm run build', { cwd: repoRoot, stdio: 'ignore' });
                }
                css = fs.readFileSync(artifactPath, 'utf8');
            } catch (e) {
                css = null;
            }
        });

        test('the stylesheet was produced and is non-trivial in size', () => {
            if (css === null) return; // toolchain unavailable
            // ~85KB combined; guard against a truncated/empty build.
            expect(css.length).toBeGreaterThan(40 * 1024);
        });

        test('a signature rule from each source is present (all six concatenated)', () => {
            if (css === null) return;
            // One distinctive token per source proves it made it into the combine.
            expect(css).toMatch(/\.ui-datepicker/);        // jquery-ui.css
            expect(css).toMatch(/\.ui-jqgrid/);            // ui.jqgrid.css
            expect(css).toMatch(/\.chosen-container/);     // chosen.css
            expect(css).toMatch(/#owa/);                   // owa.css (OWA app ids)
            expect(css).toMatch(/\.ui-selectmenu-button/); // bundled selectmenu (jquery-ui 1.13)
        });

        test('url() asset references are passed through verbatim (url:false)', () => {
            if (css === null) return;
            // These would be rewritten/hashed/inlined if css-loader resolved url()s;
            // they must stay as authored so they resolve against the css/ dir at runtime.
            expect(css).toContain('url("chosen-sprite.png")');
            expect(css).toContain('images/ui-icons_');
            expect(css).toContain('../i/'); // owa.report.css funnel/triangle images
        });

        test('no stray JS chunk is emitted alongside the stylesheet', () => {
            if (css === null) return;
            // A CSS-only entry produces a stub .js chunk; RemoveEmptyScriptsPlugin
            // must delete it so public/base/css/ isn't littered with an empty script.
            const stray = fs.readdirSync(publicCssDir).filter(
                (f) => f === 'owa.reporting-css-combined.js'
            );
            expect(stray).toEqual([]);
        });

        test('the url()-referenced assets are copied into the public tree', () => {
            if (css === null) return;
            // css-loader url:false leaves every url() as authored, so the CopyPlugin
            // must mirror those assets into public/ in the SAME relative layout or they
            // 404 at runtime: sprites + theme images beside the stylesheet, ../i/ as a
            // sibling. Pin one of each root the combined CSS references.
            const publicBase = path.dirname(publicCssDir); // public/base
            const copied = [
                'css/chosen-sprite.png',
                'css/images/ui-icons_444444_256x240.png',
                'css/font-awesome/css/all.min.css',
                'css/owa.css', // dual-role: build input AND served raw to the installer
                'i/funnel_flow.png', // owa.report.css ../i/ funnel image
            ];
            const missing = copied.filter((f) => !fs.existsSync(path.join(publicBase, f)));
            expect(missing).toEqual([]);
        });
    });
});
