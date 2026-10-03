// @ts-check
/**
 * A Profile's tracking bundle in a real browser (PLAN 2.24): published by the
 * real CLI command, loaded by the minimal snippet, configured by its Profile.
 *
 * The page carries one script tag and, where it needs one, a page-level
 * command. Everything else -- the site id, what to track, the recorder -- comes
 * from the bundle.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { adminLogin } = require('./fixtures');

const SITE = 'e2e-tracker-harness';
const ROOT_DIR = path.resolve(__dirname, '..', '..');
const BUNDLE = path.join(ROOT_DIR, 'public', 'tracker', SITE + '.js');
const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

/** A page with the minimal snippet, after whatever page-level commands it queues. */
function pageWith(root, cmds) {
    return '<!doctype html><html><head><title>Bundle page</title>'
        + '<script>window.owa_track_automated_browsers = true;'
        + 'var owa_cmds = ' + JSON.stringify(cmds) + ';</script>'
        + `<script async src="${root}public/tracker/${SITE}.js"></script>`
        + '</head><body><button id="b">a button</button><p style="height:2000px">tall</p></body></html>';
}

/** The beacons' query parameters, in order. */
function beaconsOf(page) {
    const sent = [];
    page.on('request', (r) => {
        if (r.url().includes('log.php')) {
            sent.push(new URL(r.url()).searchParams);
        }
    });
    return sent;
}

test.describe('a Profile tracking bundle @selfhost-only', () => {

    test.skip(!SELFHOST, 'Publishes into public/tracker; runs only under the self-host e2e runner.');

    test.beforeAll(() => {
        execFileSync('php', ['cli.php', 'cmd=publish-trackers', `site=${SITE}`, 'force=1'],
            { cwd: ROOT_DIR, encoding: 'utf8' });
        expect(fs.existsSync(BUNDLE)).toBe(true);
    });

    test.afterAll(() => {
        if (fs.existsSync(BUNDLE)) {
            fs.unlinkSync(BUNDLE);
        }
    });

    test('the snippet loads the bundle, and the page view carries the page\'s own title', async ({ page }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const url = root + 'tests/e2e/bundle_page.html';
        const scripts = [];

        page.on('request', (r) => { if (r.resourceType() === 'script') scripts.push(r.url()); });
        const beacons = beaconsOf(page);

        await page.route(url, (route) => route.fulfill({
            contentType: 'text/html', body: pageWith(root, [['setPageTitle', 'From the page']]) }));

        await page.goto(url, { waitUntil: 'load' });

        await expect.poll(() => beacons.filter((q) => q.get('e_t') === 'page_view').length,
            { timeout: 15_000 }).toBe(1);

        const view = beacons.find((q) => q.get('e_t') === 'page_view');
        expect(view.get('site')).toBe(SITE);
        expect(view.get('p_t')).toBe('From the page');

        await page.click('#b');
        await expect.poll(() => beacons.some((q) => q.get('e_t') === 'click'), { timeout: 10_000 }).toBe(true);

        // One script: the recorder is inlined before the core, so nothing else is fetched.
        expect(scripts.filter((s) => s.includes('/public/'))).toEqual([`${root}public/tracker/${SITE}.js`]);
    });

    test('a page that queues its own page view gets one page view, not two', async ({ page }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const url = root + 'tests/e2e/bundle_page.html';
        const beacons = beaconsOf(page);

        await page.route(url, (route) => route.fulfill({
            contentType: 'text/html', body: pageWith(root, [['trackPageView', 'https://example.test/virtual']]) }));

        await page.goto(url, { waitUntil: 'load' });
        await page.waitForTimeout(2000);

        const views = beacons.filter((q) => q.get('e_t') === 'page_view');
        expect(views).toHaveLength(1);
        expect(views[0].get('p_l')).toBe('https://example.test/virtual');
    });

    /**
     * The Tracking Tag screen says where the bundle is and whether it is current,
     * and what publishing read back. php -S reads no .htaccess, so here it sends
     * no cache header and the screen must say so.
     */
    test('the Tracking Tag screen reports the bundle and its cache header', async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.sitesInvocation&owa_siteId=${SITE}`, { waitUntil: 'networkidle' });

        const box = page.locator('.owa-tagStatus');
        await expect(box).toContainText(`public/tracker/${SITE}.js`);
        await expect(box).toContainText('Published');
        await expect(box).toContainText('This server sends no revalidation header for the tracker');
    });

    /*
     * The copy button copies the tag exactly as the box shows it. The box
     * scrolls rather than wrapping, so what is shown is what is pasted.
     */
    test('the copy button puts the tag on the clipboard', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await adminLogin(page);
        await page.goto(`?owa_do=base.sitesInvocation&owa_siteId=${SITE}`, { waitUntil: 'networkidle' });

        const block = page.locator('.owa-codeBlock').first();
        const shown = await block.locator('code').textContent();
        expect(shown).toContain(`public/tracker/${SITE}.js`);

        await block.getByRole('button', { name: 'Copy to clipboard' }).click();
        await expect(block.locator('.owa-codeBlock__copy')).toContainText('Copied');
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(shown);

        // The block is no wider than the panel it sits in.
        const [blockWidth, panelWidth] = await page.evaluate(() => [
            document.querySelector('.owa-codeBlock').getBoundingClientRect().width,
            document.getElementById('panel').getBoundingClientRect().width,
        ]);
        expect(blockWidth).toBeLessThanOrEqual(panelWidth);
    });
});

