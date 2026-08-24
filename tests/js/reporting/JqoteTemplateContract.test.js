/**
 * Every jqote template is fetched, and every fetch finds one.
 *
 * These templates are reached by a bare DOM-id lookup, with nothing declaring
 * the link. That makes two different failures look identical from the calling
 * code: a template nobody fetches, and a fetch whose template is not on the
 * page. Both fail quietly.
 *
 * Both had happened in js_report_templates.php. Five of its six templates were
 * dead -- kpiBox stopped reading `metricInfobox` when it moved to sprintf, and
 * the four table ones were read only by resultSetExplorer.renderResultsRows,
 * which nothing called -- and seven of the eight views including the file
 * needed none of it.
 *
 * Pinned in both directions, because one direction would have caught neither.
 *
 * Templates may live in the shared file or beside their consumer; both are
 * fetched the same way, so both are checked the same way. Beside the consumer
 * is the better of the two -- `funnel-step` and `visitors-headline-template`
 * already do it -- and is where the last shared one goes when
 * attribution-history converts.
 */
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');

/** Trees that may define or fetch a template. The vendored jQuery plugins are
 *  excluded: jqote's own source naturally contains its own syntax. */
const SOURCE_DIRS = [
    'modules/Base/src/reporting',
    'modules/Base/templates',
    'modules/Base/Controller',
    'modules/Base/View',
];
const EXCLUDE = /includes[\\/]jquery|node_modules/;

function walk(dir, out = []) {
    const abs = path.join(repoRoot, dir);
    if (!fs.existsSync(abs)) return out;
    for (const entry of fs.readdirSync(abs, { withFileTypes: true })) {
        const rel = path.join(dir, entry.name);
        if (EXCLUDE.test(rel)) continue;
        if (entry.isDirectory()) walk(rel, out);
        else if (/\.(js|php)$/.test(entry.name)) out.push(rel);
    }
    return out;
}

function sources() {
    return SOURCE_DIRS.flatMap((d) => walk(d))
        .map((rel) => [rel, fs.readFileSync(path.join(repoRoot, rel), 'utf8')]);
}

/** id -> file, for every template the pages actually ship. */
function defined() {
    const out = new Map();
    for (const [rel, code] of sources()) {
        for (const m of code.matchAll(
            /<script[^>]*type="text\/x-jqote-template"[^>]*id="([^"]+)"/g)) {
            out.set(m[1], rel);
        }
    }
    return out;
}

/** id -> file, for every id handed to jqote, directly or via renderTemplate. */
function fetched() {
    const out = new Map();
    const patterns = [
        /(?:jQuery|\$)\(\s*['"]#([\w-]+)['"]\s*\)\s*\.jqote\s*\(/g,   // direct
        /['"]renderTemplate['"]\s*,\s*['"]#([\w-]+)['"]/g,            // via the async queue
    ];
    for (const [rel, code] of sources()) {
        for (const re of patterns) {
            for (const m of code.matchAll(re)) {
                if (!out.has(m[1])) out.set(m[1], rel);
            }
        }
    }
    return out;
}

describe('jqote template contract', () => {

    test('there are templates to check', () => {
        expect(defined().size).toBeGreaterThan(0);
        expect(fetched().size).toBeGreaterThan(0);
    });

    test('every fetched template id is defined somewhere', () => {
        const have = defined();
        const missing = [...fetched().entries()]
            .filter(([id]) => !have.has(id))
            .map(([id, where]) => `#${id} fetched by ${where} but defined nowhere`);

        expect(missing).toEqual([]);
    });

    test('every defined template is fetched by something', () => {
        const want = fetched();
        const dead = [...defined().entries()]
            .filter(([id]) => !want.has(id))
            .map(([id, where]) => `#${id} defined in ${where} but fetched by nothing`);

        expect(dead).toEqual([]);
    });

    /**
     * The shared file is gone. Its last template, `attributionCell`, moved into
     * the named `attributionList` formatter when attribution-history became a
     * definition -- a formatter and the markup it renders are one thing, and a
     * template fetched by DOM id has nowhere to escape its fields.
     *
     * Every template that remains lives beside its consumer, which is what
     * makes the two checks above sufficient on their own.
     */
    test('no shared template file, and nothing includes one', () => {
        expect(fs.existsSync(path.join(repoRoot, 'modules/Base/templates/js_report_templates.php')))
            .toBe(false);

        const includers = walk('modules/Base/templates')
            .filter((rel) => /js_report_templates\.php/
                .test(fs.readFileSync(path.join(repoRoot, rel), 'utf8')));

        expect(includers).toEqual([]);
    });
});
