const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Two questions, answered in two places.
 *
 *   Is reporting ready?  -- a report whose Property has no cube shows why,
 *                           in place of its widgets, and asks for no data.
 *   Is the tag set up?   -- the Tracking Tag screen says when the Profile's
 *                           last event arrived, or that none has.
 *
 * A Profile added through the admin mints a Property of its own, which has no
 * cube until a scheduled build finds data for it -- so creating one is how this
 * spec gets a Property that is not ready. It uses FIXTURE.newSite*, which the
 * seeder's teardown mops up if a run aborts, and deletes the Profile itself.
 *
 * The notice's wording depends on the installation's scheduler (running here,
 * never run on CI's scratch install), so either headline is accepted.
 */

async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

/**
 * Every widget data request the page makes. Widgets fetch through the API
 * front controller -- api/index.php?...&do=reports&module=base -- so that is
 * what is matched; a pattern that never matches would make "no data was
 * requested" pass on any page, which is why the ready report below is the
 * control that proves this sees them.
 */
function watchReportData(page) {
    const seen = [];

    page.on('request', (request) => {
        if (/[?&]do=reports(&|$)/.test(request.url())) {
            seen.push(request.url());
        }
    });

    return seen;
}

async function addProfile(page) {
    await gotoAction(page, 'base.sitesProfile');
    await page.fill('input[name="domain"]', FIXTURE.newSiteDomain);
    await page.fill('input[name="name"]', FIXTURE.newSiteName);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[name="submit_btn"][value="Save Profile"]').click(),
    ]);

    await page.goto('?', { waitUntil: 'networkidle' });

    const propertyRow = page.locator('.owa_siteControlProperties li.owa_siteControlItem',
        { hasText: FIXTURE.newSiteName });
    const index = await propertyRow.getAttribute('data-property-index');
    const href = await page.locator(
        `.owa_siteControlProfiles ul[data-property-index="${index}"] li.owa_siteControlItem a[href*="base.sitesProfile"]`)
        .first().getAttribute('href');
    const params = new URL(href, page.url()).searchParams;

    return params.get('owa_siteId') || params.get('siteId');
}

async function deleteProfile(page, siteId) {
    await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${siteId}&owa_edit=1`);
    await page.locator('input[value="Delete Profile"]').click();
    await expect(page.locator('#owa_confirmDialog')).toBeVisible();
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('.owa_confirmProceed').click(),
    ]);
}

test.describe('reporting readiness', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('a report on a Property with no cube says why and asks for no data', async ({ page }) => {
        const siteId = await addProfile(page);
        expect(siteId, 'the new Profile\'s id').toBeTruthy();

        try {
            const dataRequests = watchReportData(page);

            await gotoAction(page, 'base.report', `&owa_reportId=dashboard&owa_siteId=${siteId}`);

            const notice = page.locator('.owa_reportNotReady');
            await expect(notice).toBeVisible();
            await expect(page.locator('.owa_reportNotReadyHeadline'))
                .toHaveText(/^(No reporting data yet\.|Reports need the job scheduler\.)$/);

            expect(dataRequests, 'no widget asked for data').toEqual([]);

            // The chrome stays, so another Profile is one click away.
            await expect(page.locator('.owa_siteControlProperties')).toHaveCount(1);

            // And the tag question is answered where the tag is.
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                notice.getByRole('link', { name: 'Tracking Tag' }).click(),
            ]);

            await expect(page.locator('.panel_headline')).toHaveText('Tracking Tag');
            await expect(page.getByText('None received yet.')).toBeVisible();
        } finally {
            await deleteProfile(page, siteId);
        }
    });

    test('a ready report does ask for data, so the check above can fail', async ({ page }) => {
        const dataRequests = watchReportData(page);

        await gotoAction(page, 'base.report', `&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`);

        await expect(page.locator('.owa_reportNotReady')).toHaveCount(0);
        expect(dataRequests.length, 'the seeded Profile\'s dashboard fetches its widgets').toBeGreaterThan(0);
    });

    test('the Tracking Tag screen says when the last event arrived', async ({ page }) => {
        // The seeder writes raw rows for the fixture Profile.
        await gotoAction(page, 'base.sitesInvocation', `&owa_siteId=${FIXTURE.siteId}`);

        await expect(page.locator('.owa-tagStatus')).toContainText('Last received');
    });
});
