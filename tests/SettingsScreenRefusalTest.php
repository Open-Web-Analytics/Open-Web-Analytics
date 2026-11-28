<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\SettingsForm;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/InstallSettingRowsFixture.php';

/**
 * A refused save on a settings screen -- General, Data Retention, Updates
 * and a Property's Data Retention -- draws the same screen again with what
 * was posted and each refusal beside its field (SettingsForm::redrawRefused()).
 * A valid save still redirects with its saved message; the install rows it
 * writes are put back exactly (InstallSettingRowsFixture).
 */
final class SettingsScreenRefusalTest extends TestCase
{
    use InstallSettingRowsFixture;

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->putBackInstallRows();
        }
    }

    private function requireDb(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('the settings screens load the site list');
        }

        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $user->setRole('admin');
        $user->setAuthStatus(true);
    }

    /** A save controller run as the dispatcher runs it, with its nonce. */
    private function post(string $class, string $action, array $params): array
    {
        $c = new $class(['do' => $action, 'nonce' => \OWA\Core\CoreAPI::createNonce($action)] + $params);

        return (array) $c->doAction();
    }

    private function aPropertyId(): string
    {
        $table = \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName();
        $row   = \OWA\Core\CoreAPI::dbSingleton()->get_row("SELECT id FROM $table ORDER BY id LIMIT 1");

        if (!$row) {
            $this->markTestSkipped('needs a Property');
        }

        return (string) $row['id'];
    }

    // ------------------------------------------------------------------
    // The field, on its own
    // ------------------------------------------------------------------

    public function testAFieldShowsAPostedValueAndItsProblemBesideIt(): void
    {
        $html = SettingsForm::field('base', 'admin_notice_email', '', [
            'config'   => ['base.admin_notice_email' => 'not an address'],
            'problems' => ['base.admin_notice_email' => 'Admin Notice E-mail Address is an e-mail address, or several separated by commas.'],
        ]);

        $this->assertStringContainsString('value="not an address"', $html);
        $this->assertStringContainsString('<div class="owa-settingProblem" id="owa-setting-base-admin_notice_email-problem" role="alert" data-ui="setting-problem">'
            . 'Admin Notice E-mail Address is an e-mail address, or several separated by commas.</div>', $html);
    }

    public function testWithoutAPostTheFieldIsAsBefore(): void
    {
        $html = SettingsForm::field('base', 'admin_notice_email');

        $this->assertStringNotContainsString('owa-settingProblem', $html);
        $this->assertSame($html, SettingsForm::field('base', 'admin_notice_email', '', ['config' => [], 'problems' => []]));
    }

    public function testAPostedSecretIsNeverPrinted(): void
    {
        $html = SettingsForm::field('base', 'db_password', '', ['config' => ['base.db_password' => 'owatest-posted-secret']]);

        $this->assertStringNotContainsString('owatest-posted-secret', $html);
    }

    // ------------------------------------------------------------------
    // The screens
    // ------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function installScreens(): iterable
    {
        yield 'General' => [\OWA\Module\Base\Controller\OptionsUpdate::class, 'base.optionsUpdate', 'base.optionsGeneral',
            'tracker_scroll_thresholds', 'abc', 'Scroll Thresholds is a comma-separated list of percentages from 1 to 100.'];
        yield 'Data Retention' => [\OWA\Module\Base\Controller\OptionsRetentionUpdate::class, 'base.optionsRetentionUpdate', 'base.optionsRetention',
            'raw_retention_months', '5000', 'Keep event data (months) is a whole number from 1 to 1200.'];
        yield 'Updates' => [\OWA\Module\Base\Controller\OptionsUpdatesUpdate::class, 'base.optionsUpdatesUpdate', 'base.optionsUpdates',
            'auto_update_window', '25:00', 'Update Window is two times, HH:MM-HH:MM, such as 02:00-06:00.'];
    }

    /** @dataProvider installScreens */
    public function testARefusedValueIsShownOnItsScreenBesideItsField(string $class, string $action, string $screen,
        string $key, string $refused, string $message): void
    {
        $this->requireDb();
        $before = \OWA\Core\CoreAPI::getSetting('base', $key);

        $data = $this->post($class, $action, ['config' => ['base.' . $key => $refused]]);

        $this->assertSame($screen, $data['subview'] ?? null, 'the same screen, drawn');
        $this->assertArrayNotHasKey('status_code', $data, 'not "Options saved."');
        $this->assertSame($refused, $data['posted']['config']['base.' . $key]);
        $this->assertSame($message, $data['posted']['problems']['base.' . $key]);
        $this->assertArrayHasKey('base.' . $key, $data['validation_errors']);
        $this->assertSame($before, \OWA\Core\CoreAPI::getSetting('base', $key), 'nothing saved');

        $html = '';
        foreach (SettingsForm::pageFieldSets($screen) as $set) {
            $html .= SettingsForm::fieldSet($set, '', $data['posted']);
        }

        $this->assertStringContainsString('value="' . $refused . '"', $html, 'the submitted value is kept');
        $this->assertStringContainsString('data-ui="setting-problem">' . htmlspecialchars($message, ENT_QUOTES) . '</div>', $html);
    }

    /** @dataProvider installScreens */
    public function testAValidSaveStillRedirectsWithItsMessage(string $class, string $action, string $screen, string $key): void
    {
        $this->requireDb();
        $this->keepInstallRows([$key]);

        $data = $this->post($class, $action, ['config' => ['base.' . $key => (string) \OWA\Core\CoreAPI::getSetting('base', $key)]]);

        $this->assertSame($screen, $data['do'] ?? null);
        $this->assertSame(2500, (int) ($data['status_code'] ?? 0));
        $this->assertArrayNotHasKey('posted', $data);
    }

    public function testAPropertysRefusedRetentionIsShownBesideItsField(): void
    {
        $this->requireDb();
        $propertyId = $this->aPropertyId();

        $data = $this->post(\OWA\Module\Base\Controller\PropertyRetentionUpdate::class, 'base.propertyRetentionUpdate', [
            'propertyId' => $propertyId,
            'config'     => ['base.cube_retention_months' => '5000'],
            'override'   => ['base.cube_retention_months' => '1'],
        ]);

        $message = 'Keep reporting data (months) is a whole number from 1 to 1200.';

        $this->assertSame('base.propertyRetention', $data['subview'] ?? null);
        $this->assertSame($propertyId, (string) $data['propertyId']);
        $this->assertSame($message, $data['posted']['problems']['base.cube_retention_months']);

        $html = SettingsForm::scopedFieldSet(SettingsForm::registeredFieldSet('base.propertyRetention'), 'property', $propertyId, '', $data['posted']);

        $this->assertStringContainsString('value="5000"', $html, 'the submitted value is kept');
        $this->assertStringContainsString('data-ui="setting-problem">' . $message . '</div>', $html);
    }

    public function testAPropertysValidRetentionSaveStillRedirects(): void
    {
        $this->requireDb();
        $propertyId = $this->aPropertyId();
        $state      = SettingsForm::inheritance('base', 'cube_retention_months', 'property', $propertyId);

        $data = $this->post(\OWA\Module\Base\Controller\PropertyRetentionUpdate::class, 'base.propertyRetentionUpdate', [
            'propertyId' => $propertyId,
            'config'     => ['base.cube_retention_months' => (string) ($state['own'] ? $state['value'] : '')],
            'override'   => $state['own'] ? ['base.cube_retention_months' => '1'] : [],
        ]);

        $this->assertSame('base.propertyRetention', $data['do'] ?? null);
        $this->assertSame(2500, (int) ($data['status_code'] ?? 0));
    }

    /** A refused Organization rename draws its screen again with the posted name and the reason. */
    public function testARefusedOrganizationRenameIsShownOnItsScreen(): void
    {
        $this->requireDb();

        $data = $this->post(\OWA\Module\Base\Controller\OrganizationEdit::class, 'base.organizationEdit', ['name' => '   ']);

        $this->assertSame('base.organizationProfile', $data['subview'] ?? null, 'the same screen, drawn');
        $this->assertArrayNotHasKey('status_code', $data, 'not "Profile updated."');
        $this->assertSame('Name is required.', $data['posted']['problems']['name']);
        $this->assertContains('Name is required.', array_map(
            fn ($m) => is_array($m) ? (string) ($m['message'] ?? '') : (string) $m, array_values((array) $data['validation_errors'])));
        $this->assertSame('', trim((string) $data['organization']['name']), 'the name as posted, not the stored one');
    }
}
