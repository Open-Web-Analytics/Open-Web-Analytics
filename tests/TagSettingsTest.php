<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\SettingsForm;

/**
 * The tracking tag's settings (PLAN 2.24.4): declared once, shown at each level
 * that may hold them, saved through the Override switch.
 */
final class TagSettingsTest extends TestCase
{
    private function config()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    private function tagSet(): array
    {
        $set = SettingsForm::registeredFieldSet('base.trackingTag');
        $this->assertNotSame(array(), $set, 'base.trackingTag is registered');

        return $set;
    }

    private function aSiteId(): string
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs an Observation Profile');
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);

        $sites = (array) \OWA\Core\CoreAPI::getSitesList();
        if (!$sites) {
            $this->markTestSkipped('needs an Observation Profile');
        }

        $first = reset($sites);

        return (string) (is_array($first) ? $first['site_id'] : $first);
    }

    public function testEveryTagSettingIsDeclaredForTheInstallAndTheProperty(): void
    {
        $set = $this->tagSet();

        $this->assertSame('tracking_tag', $set['group']);

        foreach ($set['settings'] as $key) {
            $args = $this->config()->registeredField('base', $key);

            $this->assertNotNull($args, "$key is declared");
            $this->assertTrue(\OWA\Module\Base\Classes\Settings::isStorable($args), "$key is storable");
            $this->assertContains('install', $args['scopes'], $key);
            $this->assertContains('property', $args['scopes'], $key);
            $this->assertNotEmpty($args['label'] ?? '', "$key has a label");
        }
    }

    /** The visitor cookie is one per page, so its settings stop at the Property. */
    public function testThePropertyOnlySettingsAreNotOnTheProfileScreen(): void
    {
        $html = SettingsForm::scopedFieldSet($this->tagSet(), 'profile', 'zz-tag-profile', 'owa_');

        $this->assertStringContainsString('config[base.tracker_clicks]', $html);
        $this->assertStringContainsString('config[base.tracker_session_cookie_days]', $html);
        $this->assertStringNotContainsString('config[base.tracker_visitor_cookie_days]', $html);
        $this->assertStringNotContainsString('config[base.tracker_cookie_domain]', $html);

        $property = SettingsForm::scopedFieldSet($this->tagSet(), 'property', 'zz-tag-property', 'owa_');

        $this->assertStringContainsString('config[base.tracker_visitor_cookie_days]', $property);
        $this->assertStringContainsString('config[base.tracker_cookie_domain]', $property);
    }

    public function testTheInstallScreenHasTheTagFieldset(): void
    {
        $this->assertContains('Tracking Tag',
            array_column(SettingsForm::pageFieldSets('base.optionsGeneral'), 'legend'));
    }

    /** @dataProvider refused */
    public function testAValueOutsideItsFormIsRefused(string $key, $value, string $problem): void
    {
        $this->assertSame($problem, $this->config()->valueProblem('base', $key, $value));
    }

    public static function refused(): array
    {
        return array(
            'a threshold of 0'       => array('tracker_scroll_thresholds', '0, 50', 'Scroll Thresholds is a comma-separated list of percentages from 1 to 100.'),
            'a threshold over 100'   => array('tracker_scroll_thresholds', '50, 101', 'Scroll Thresholds is a comma-separated list of percentages from 1 to 100.'),
            'an extension with a dot'=> array('tracker_download_extensions', 'pdf, .zip', 'Download File Extensions is a comma-separated list of file extensions, without the dot.'),
            'a domain with a space'  => array('tracker_cookie_domain', 'bad domain', 'Tracker Cookie Domain is a domain name, such as example.com.'),
            'a lifetime over 400'    => array('tracker_session_cookie_days', '500', 'Session Cookie Lifetime (days) is a whole number from 0 to 400.'),
        );
    }

    public function testZeroDaysAndAnEmptyDomainAreAccepted(): void
    {
        $this->assertNull($this->config()->valueProblem('base', 'tracker_visitor_cookie_days', '0'));
        $this->assertNull($this->config()->valueProblem('base', 'tracker_cookie_domain', ''));
        $this->assertNull($this->config()->valueProblem('base', 'tracker_cookie_domain', '.example.com'));
    }

    /** The Tracking Tag screen saves through the switch, at Profile scope. */
    public function testTheProfileSaveStoresAnOverride(): void
    {
        $site_id = $this->aSiteId();

        \OWA\Core\CoreAPI::clearScopedSetting('profile', $site_id, 'base', 'tracker_clicks');

        try {
            $c = new \OWA\Module\Base\Controller\SitesEditTagSettings(array(
                'siteId'   => $site_id,
                'config'   => array('base.tracker_clicks' => '0'),
                'override' => array('base.tracker_clicks' => '1'),
            ));
            $c->action();

            $this->assertFalse(\OWA\Core\CoreAPI::getScopedSettingRow('profile', $site_id, 'base', 'tracker_clicks'));

            // Switched off: back to inheriting.
            (new \OWA\Module\Base\Controller\SitesEditTagSettings(array('siteId' => $site_id)))->action();

            $this->assertNull(\OWA\Core\CoreAPI::getScopedSettingRow('profile', $site_id, 'base', 'tracker_clicks'));

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting('profile', $site_id, 'base', 'tracker_clicks');
        }
    }

    public function testTheProfileSaveReportsARefusedValue(): void
    {
        $site_id = $this->aSiteId();

        $c = new \OWA\Module\Base\Controller\SitesEditTagSettings(array(
            'siteId'   => $site_id,
            'config'   => array('base.tracker_session_cookie_days' => '500'),
            'override' => array('base.tracker_session_cookie_days' => '1'),
        ));

        $v = (new ReflectionProperty(\OWA\Core\Controller::class, 'v'))->getValue($c);
        $v->doValidations();

        $this->assertContains('Session Cookie Lifetime (days) is a whole number from 0 to 400.',
            array_values((array) $c->getValidationErrorMsgs()));
    }

    /** The Tracking Tag screen renders the group, not a list it keeps itself. */
    public function testTheTrackingTagScreenRendersTheGroup(): void
    {
        $code = php_strip_whitespace(OWA_DIR . 'modules/Base/templates/sites_invocation.php');

        $this->assertStringContainsString("SettingsForm::groupFieldSets( 'tracking_tag' )", $code);
        $this->assertStringContainsString('SettingsForm::scopedFieldSet', $code);
        $this->assertStringContainsString("createNonceFormField( 'base.sitesEditTagSettings' )", $code);
    }
}
