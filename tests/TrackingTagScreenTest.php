<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The Tracking Tag screen (base.sitesInvocation): status, the tag in a code
 * block with a copy button, then the settings built into what the tag loads.
 */
final class TrackingTagScreenTest extends TestCase
{
    const TAG = '<script async src="https://example.test/owa/public/tracker/alice-site.js"></script>';

    private function render(array $vars = []): string
    {
        $t = new \OWA\Core\Template('base');

        foreach (array_merge([
            'site_id'       => 'alice-site',
            'last_event'    => 0,
            'bundle_url'    => 'https://example.test/owa/public/tracker/alice-site.js',
            'bundle_status' => ['state' => 'published', 'published_at' => 1790000000],
            'bundle_cache'  => ['ok' => true, 'cache_control' => 'no-cache'],
            'bundle_tag'    => self::TAG,
            'tracking_code' => '<script>var owaClassic = 1;</script>',
            'tracked_events' => ['Page views', 'Clicks'],
        ], $vars) as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template('sites_invocation.php'));

        return (string) $t->fetch();
    }

    /** Shown escaped, once, in a pre that scrolls instead of wrapping, with a copy button. */
    public function testACodeBlockShowsTheCodeExactlyAndOffersCopy(): void
    {
        $html = (new \OWA\Core\Template('base'))->codeBlock("\n<a href=\"x\">&amp;</a>\n", 'HTML');

        $this->assertStringContainsString(
            '<pre class="owa-codeBlock__code"><code>&lt;a href=&quot;x&quot;&gt;&amp;amp;&lt;/a&gt;</code></pre>', $html,
            'escaped once, the surrounding newlines trimmed so the box has no blank first line');
        $this->assertMatchesRegularExpression('/<button type="button" class="owa-codeBlock__copy" data-owa-copy aria-label="Copy to clipboard">/', $html,
            'a button, not a submit: the blocks sit inside the settings form\'s page');
        $this->assertStringContainsString('<span class="owa-codeBlock__language">HTML</span>', $html);
    }

    /** Where things stand, the settings that decide what the tracker records, then the tag that loads it. */
    public function testTheScreenIsStatusThenTheTagThenTheSettings(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the settings form reads stored values');
        }

        $html = $this->render();

        preg_match_all('#<h2>(.*?)</h2>#', $html, $m);
        $this->assertSame(['Status', 'Tracker Settings', 'Add the tag to your pages'], $m[1]);

        $this->assertStringContainsString(htmlspecialchars(self::TAG, ENT_QUOTES), $html, 'the tag, in a code block');
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('PHP SDK', $html, 'the PHP section is gone');
        $this->assertStringContainsString('They are built into the tracker the tag', $html);
        $this->assertMatchesRegularExpression('#<details class="owa-tagScreen__classic">\s*<summary>Classic tag format</summary>#', $html,
            'the classic tag, collapsed under one heading');
        $this->assertStringContainsString(htmlspecialchars('<script>var owaClassic = 1;</script>', ENT_QUOTES), $html);
        $this->assertStringContainsString('value="Save and republish"', $html);
        $this->assertStringContainsString('name="config[base.tracker_clicks]"', $html, 'the settings form is rendered');

        // The example is a command the tracker cannot do for itself.
        $this->assertStringContainsString('setContentGroup', $html);
        $this->assertStringNotContainsString('setPageTitle', $html, 'the tracker reads the title itself');
    }

    /** Every group is closed when the page loads, headed by its name and what it is for. */
    public function testTheSettingsAreGroupsClosedOnLoad(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the settings form reads stored values');
        }

        $html = $this->render();

        preg_match_all('#<details class="owa-settingsGroup" id="([^"]+)"( open)?>#', $html, $m);
        $this->assertContains('base.trackingClicks', $m[1]);
        $this->assertGreaterThanOrEqual(7, count($m[1]), "Base's seven, and any a module adds");
        $this->assertSame([''], array_values(array_unique($m[2])), 'none open');

        $this->assertMatchesRegularExpression(
            '#<summary><span class="owa-settingsGroup__title">Clicks and downloads</span>'
          . '<span class="owa-settingsGroup__description">Clicks on links and other elements, and which links count as downloads\.</span></summary>#',
            $html);
        $this->assertStringNotContainsString('<legend>', $html, 'the group heading is the summary, not a legend as well');
    }

    /** The pills are what the saved settings track, and say so when that is nothing. */
    public function testThePillsAreWhatIsTracked(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the settings form reads stored values');
        }

        $html = $this->render(['tracked_events' => ['Page views', 'Page interaction recording']]);
        preg_match_all('#<li class="owa-pill">([^<]*)</li>#', $html, $m);
        $this->assertSame(['Page views', 'Page interaction recording'], $m[1]);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--ok">\s*<dt>Tracking</dt>\s*<dd>\s*<ul class="owa-pills">#', $html,
            'the last row of the status box');
        $this->assertLessThan(strpos($html, '</dl>'), strpos($html, '<dt>Tracking</dt>'));
        $this->assertGreaterThan(strpos($html, '<dt>Tracker</dt>'), strpos($html, '<dt>Tracking</dt>'));

        $none = $this->render(['tracked_events' => []]);
        $this->assertStringNotContainsString('owa-pill"', $none);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--pending">\s*<dt>Tracking</dt>\s*<dd>\s*Nothing: every event is switched off#', $none);
    }

    /** Each status row says its state, and the colour bar agrees with it. */
    public function testTheStatusRowsSayWhereThingsStand(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the settings form reads stored values');
        }

        $none = $this->render();
        $this->assertStringContainsString('<code>alice-site</code>', $none);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--pending">\s*<dt>Events</dt>\s*<dd>None received yet\.#', $none);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--ok">\s*<dt>Tracker</dt>\s*<dd>Published #', $none);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--ok">\s*<dt>Updates</dt>#', $none);

        $live = $this->render([
            'last_event'    => time() - 60,
            'bundle_status' => ['state' => 'queued'],
            'bundle_cache'  => ['ok' => false, 'cache_control' => 'max-age=86400'],
        ]);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--ok">\s*<dt>Events</dt>\s*<dd>Last received #', $live);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--pending">\s*<dt>Tracker</dt>\s*<dd>Waiting to publish\.#', $live);
        $this->assertMatchesRegularExpression('#owa-tagStatus__row--problem">\s*<dt>Updates</dt>\s*<dd>This server sends no revalidation header#', $live);
        $this->assertStringContainsString('<code>Cache-Control: max-age=86400</code>', $live);

        $unchecked = $this->render(['bundle_cache' => []]);
        $this->assertStringNotContainsString('<dt>Updates</dt>', $unchecked, 'nothing to say before the header is checked');
    }

    /** Redisplaying a refused post, the group is open: a refused value is not hidden from whoever must fix it. */
    public function testARefusedPostOpensItsGroup(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the settings form reads stored values');
        }

        $set = \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet('base.trackingVisit');
        $closed = \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet($set, 'profile', 'zz-tag-screen', '', null, true);
        $open   = \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet($set, 'profile', 'zz-tag-screen', '',
            ['config' => ['base.tracker_session_cookie_days' => '500'], 'override' => ['base.tracker_session_cookie_days' => '1']], true);

        $this->assertStringContainsString('<details class="owa-settingsGroup" id="base.trackingVisit">', $closed);
        $this->assertStringContainsString('<details class="owa-settingsGroup" id="base.trackingVisit" open>', $open);

        $plain = \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet($set, 'profile', 'zz-tag-screen', '');
        $this->assertStringContainsString('<legend>Visits and cookies</legend>', $plain, 'other screens keep the plain fieldset');
        $this->assertStringNotContainsString('<details', $plain);
    }
}
