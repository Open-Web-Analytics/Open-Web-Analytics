const fs = require('fs');
const path = require('path');
const vm = require('vm');

/**
 * The built bundles must not declare anything in the page's global scope.
 *
 * The tracker ships as a classic <script> into pages OWA does not control. In
 * production mode webpack concatenates the entry's whole module graph into a
 * single scope (scope hoisting), so if `output.iife` is off, that scope IS the
 * host page's global scope: every top-level declaration in every concatenated
 * source file becomes a global binding.
 *
 * That is not a theoretical concern. The tracker's event class, then still named
 * `Event` (it is OwaEvent now, in tracker/OwaEvent.js), used to land as a global
 * lexical binding, which shadows the DOM's window.Event for the whole page (lexical
 * declarations are resolved before the global object), and broke every library doing
 * `new Event(...)` -- Bootstrap dropdowns, modals and tabs among them, with
 * "Failed to execute 'dispatchEvent' on 'EventTarget'".
 *
 * Two guards, because they fail on different mistakes:
 *   1. the config guard catches `output.iife: false` coming back;
 *   2. the artefact guard catches any other way a global escapes the bundle.
 */
describe('built bundles keep their declarations out of the global scope', () => {

    const repoRoot = path.resolve(__dirname, '../..');
    const distDir = path.join(repoRoot, 'public/base/dist');
    const bundles = ['owa.tracker.js', 'owa.reporting-combined-min.js'];

    // Identifiers that must never resolve after a bundle has been evaluated: the
    // tracker's own class and module names, plus the DOM globals most likely to be
    // shadowed by a same-named class.
    const mustNotResolve = [
        'Event',
        'OwaEvent',
        'OWATracker',
        'CommandQueue',
        'StateManager',
        'Heatmap',
        'Player',
        'Uri',
        'Util',
        'owa',
        'CustomEvent',
        'Request',
        'Response',
        'Node',
        'Element',
    ];

    test('no JS build product disables the IIFE wrapper', () => {
        const configs = require(path.join(repoRoot, 'webpack.config.js'));
        const jsConfigs = configs.filter((c) => c.output && c.output.filename);

        expect(jsConfigs.length).toBeGreaterThan(0);
        for (const cfg of jsConfigs) {
            // Undefined is fine: webpack defaults to true for target:web.
            expect(cfg.output.iife).not.toBe(false);
        }
    });

    test.each(bundles)('%s declares no globals', (name) => {
        const file = path.join(distDir, name);
        if (!fs.existsSync(file)) {
            throw new Error(
                `${file} is missing. The dist tree is gitignored, so run \`npm run build\` before \`npm test\`.`
            );
        }

        const code = fs.readFileSync(file, 'utf8');

        // A bare realm: no window, no document, no DOM constructors. Evaluating the
        // bundle here is expected to throw once it reaches browser APIs, which is
        // fine -- global declaration instantiation happens before the first
        // statement runs, so anything the bundle declares is already visible.
        const context = vm.createContext({});
        try {
            vm.runInContext(code, context, { filename: name });
        } catch (err) {
            // Expected: the bundle needs a browser.
        }

        // `var` leaks become properties of the realm's global object.
        expect(Object.keys(context)).toEqual([]);

        // `let`/`const`/`class` leaks do NOT, so probe them by name from a second
        // script in the same realm, which shares the global lexical environment.
        //
        // A leaked binding shows up one of two ways. If the bundle ran far enough to
        // evaluate the declaration, `typeof` reports its type. If it threw earlier,
        // the binding exists but is uninitialized and `typeof` itself throws from
        // the temporal dead zone -- which still proves the declaration is global.
        for (const identifier of mustNotResolve) {
            const resolved = vm.runInContext(
                `(() => { try { return typeof ${identifier}; } catch (e) { return 'declared (uninitialized)'; } })()`,
                context
            );
            expect({ identifier, resolved }).toEqual({ identifier, resolved: 'undefined' });
        }
    });
});
