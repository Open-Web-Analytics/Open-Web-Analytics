/**
 * The reporting UI has no client-side template engine.
 *
 * jQote2 was the last hand-copied library under src/reporting/v1/includes. Its
 * one caller, resultSetExplorer.renderTemplate, was reached only through a
 * view variable no controller set, and every template it used to render is
 * markup or a named formatter now. Nothing may call a jqote function, define a
 * jqote template, or import the vendored copy.
 */
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../../..');

const SOURCE_DIRS = [
    'modules/Base/src/reporting',
    'modules/Base/templates',
    'modules/Base/Controller',
    'modules/Base/View',
];

function walk(dir, out = []) {
    const abs = path.join(repoRoot, dir);
    if (!fs.existsSync(abs)) return out;
    for (const entry of fs.readdirSync(abs, { withFileTypes: true })) {
        const rel = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(rel, out);
        else if (/\.(js|php)$/.test(entry.name)) out.push(rel);
    }
    return out;
}

describe('jQote2 is gone', () => {

    test('the vendored copy is not in the tree', () => {
        expect(fs.existsSync(path.join(repoRoot, 'modules/Base/src/reporting/v1/includes')))
            .toBe(false);
    });

    test('no reporting source names jqote or renderTemplate', () => {
        const hits = SOURCE_DIRS.flatMap((d) => walk(d))
            .filter((rel) => /jqote|renderTemplate/i
                .test(fs.readFileSync(path.join(repoRoot, rel), 'utf8')));

        expect(hits).toEqual([]);
    });

    test('resultSetExplorer has no template view', () => {
        const code = fs.readFileSync(
            path.join(repoRoot, 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js'), 'utf8');

        expect(code).not.toMatch(/\btemplate\s*:\s*\{/);
        expect(code).not.toMatch(/\btemplate\s*:\s*'renderTemplate'/);
    });

    test('no shared template file, and nothing includes one', () => {
        expect(fs.existsSync(path.join(repoRoot, 'modules/Base/templates/js_report_templates.php')))
            .toBe(false);

        const includers = walk('modules/Base/templates')
            .filter((rel) => /js_report_templates\.php/
                .test(fs.readFileSync(path.join(repoRoot, rel), 'utf8')));

        expect(includers).toEqual([]);
    });
});
