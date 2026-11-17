const fs = require('fs');
const path = require('path');

/**
 * The tracker and its chunks -- the heatmap and the recording player among
 * them -- run on the tracked page, so they import no jQuery and no jQuery
 * plugin: either is weight on someone else's page and can clash with the
 * page's own copy. jQuery stays a dependency of the reporting bundle alone,
 * and jGrowl is not a dependency at all.
 *
 * The tracker's graph is walked from its entry, every file a build manifest
 * contributes to it and every lazy chunk's entry, through static imports and
 * import() alike.
 */

const ROOT = path.join(__dirname, '../..');
const MODULES = path.join(ROOT, 'modules');

function manifests() {
    return fs.readdirSync(MODULES).sort()
        .map((m) => ({ dir: path.join(MODULES, m), file: path.join(MODULES, m, 'build.manifest.json') }))
        .filter(({ file }) => fs.existsSync(file))
        .map(({ dir, file }) => ({ dir, manifest: JSON.parse(fs.readFileSync(file, 'utf8')) }));
}

function trackerEntries() {
    const all = manifests();
    const entries = [];

    for (const { dir, manifest } of all) {
        for (const pkg of manifest.packages || []) {
            if (pkg.name === 'owa.tracker.js') {
                entries.push(path.join(dir, pkg.entry));
            }
        }
        for (const file of (manifest.contributes || {})['owa.tracker.js'] || []) {
            entries.push(path.join(dir, file));
        }
        for (const lazy of (manifest.contributes_lazy || {})['owa.tracker.js'] || []) {
            entries.push(path.join(dir, lazy.entry));
        }
    }

    return entries;
}

const SPECIFIER = /\bimport\s*(?:[^'"()]*?\bfrom\s*)?['"]([^'"]+)['"]|\bimport\s*\(\s*(?:\/\*[\s\S]*?\*\/\s*)?['"]([^'"]+)['"]\s*\)|\brequire\s*\(\s*['"]([^'"]+)['"]\s*\)/g;

/** Every file the graph reaches, and every bare (package) import, by importing file. */
function walk(entries) {
    const seen = new Set();
    const bare = [];
    const queue = [...entries];

    while (queue.length) {
        const file = queue.shift();
        if (seen.has(file)) continue;
        seen.add(file);

        const source = fs.readFileSync(file, 'utf8').replace(/^\s*\/\/.*$/gm, '');
        for (const m of source.matchAll(SPECIFIER)) {
            const spec = m[1] || m[2] || m[3];
            if (spec.startsWith('.')) {
                queue.push(path.resolve(path.dirname(file), spec));
            } else {
                bare.push({ from: path.relative(ROOT, file), spec });
            }
        }
    }

    return { files: [...seen].map((f) => path.relative(ROOT, f)), bare };
}

describe('the tracker side imports no jQuery', () => {

    const { files, bare } = walk(trackerEntries());

    test('the walk reaches the overlays', () => {
        expect(files).toEqual(expect.arrayContaining([
            'modules/Base/src/tracker/Heatmap.js',
            'modules/Domstream/src/tracker/Player.js',
            'modules/Base/src/tracker/ElementAnchor.js',
        ]));
    });

    test('no file in the tracker graph imports jQuery or a jQuery plugin', () => {
        const offending = bare.filter(({ spec }) => /^(jquery|jgrowl)([./-]|$)/i.test(spec));

        expect(offending).toEqual([]);
    });

    test('jGrowl is no dependency of the project', () => {
        const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
        const lock = JSON.parse(fs.readFileSync(path.join(ROOT, 'package-lock.json'), 'utf8'));

        expect(Object.keys({ ...pkg.dependencies, ...pkg.devDependencies })).not.toContain('jgrowl');
        expect(Object.keys(lock.packages[''].dependencies || {})).not.toContain('jgrowl');
        expect(Object.keys(lock.packages)).not.toContain('node_modules/jgrowl');
    });

    test('the reporting bundle keeps jQuery', () => {
        const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));

        expect(Object.keys(pkg.dependencies)).toContain('jquery');
    });
});
