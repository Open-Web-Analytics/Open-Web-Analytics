/**
 * @jest-environment node
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '../..');
const { RULES, writePublicHtaccess, PublicHtaccessPlugin } = require(path.join(ROOT, 'webpack.public-htaccess.js'));

/**
 * The build writes public/.htaccess (#1401).
 *
 * The root .htaccess grants public/ back only inside an <If> expression. A
 * server that reads Require but not <If> -- shared hosting running LiteSpeed --
 * denied every stylesheet and script. public/.htaccess grants the tree with a
 * plain Require. public/ is build output, so the build writes it.
 */
describe('public/.htaccess', () => {

    test('grants the tree with a plain Require and no expression', () => {
        const rules = RULES.replace(/^\s*#.*$/gm, '');

        expect(rules).toMatch(/<IfModule mod_authz_core\.c>\s*Require all granted\s*<\/IfModule>/);
        expect(rules).not.toMatch(/<If\b/);
    });

    test('is written under the root, and written again only when it differs', () => {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'owa-public-'));

        try {
            const file = writePublicHtaccess(root);

            expect(file).toBe(path.join(root, 'public', '.htaccess'));
            expect(fs.readFileSync(file, 'utf8')).toBe(RULES);

            fs.writeFileSync(file, 'Require all denied\n');
            writePublicHtaccess(root);
            expect(fs.readFileSync(file, 'utf8')).toBe(RULES);
        } finally {
            fs.rmSync(root, { recursive: true, force: true });
        }
    });

    test('every build config writes it, so a build of one package does too', () => {
        const configs = require(path.join(ROOT, 'webpack.config.js'));

        expect(configs.length).toBeGreaterThan(0);

        for (const config of configs) {
            const plugin = (config.plugins || []).find((p) => p instanceof PublicHtaccessPlugin);

            expect(plugin).toBeDefined();
            expect(plugin.root).toBe(ROOT);
        }
    });

    test('the release tarball keeps public/ and leaves the build helper out', () => {
        const workflow = fs.readFileSync(path.join(ROOT, '.github/workflows/main.yml'), 'utf8');

        expect(workflow).not.toMatch(/--exclude='\.\/public/);
        expect(workflow).toMatch(/--exclude='\.\/webpack\.public-htaccess\.js'/);
        expect(workflow).toMatch(/npm run build/);
    });
});
