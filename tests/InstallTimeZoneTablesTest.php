<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\DatabaseRequirement;
use OWA\Module\Base\Classes\EnvironmentCheck;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The installer warns when the database server's time zone tables are not
 * loaded, and installs anyway: the environment check lists it as a passing
 * check with a warning, and the defaults step shows it by the timezone field.
 */
final class InstallTimeZoneTablesTest extends TestCase
{
    protected function tearDown(): void
    {
        DatabaseRequirement::assumeTimeZones(null);
    }

    public function testTheCheckPassesWithAWarning(): void
    {
        $missing = EnvironmentCheck::timeZoneTables(DatabaseRequirement::timeZoneWarningText());

        $this->assertTrue($missing['passed'], 'a warning does not stop the install');
        $this->assertSame('not loaded', $missing['value']);
        $this->assertStringContainsString('mysql_tzinfo_to_sql', $missing['warning']);

        $loaded = EnvironmentCheck::timeZoneTables(null);

        $this->assertTrue($loaded['passed']);
        $this->assertSame('', $loaded['warning']);
    }

    public function testTheEnvironmentScreenShowsTheWarning(): void
    {
        $failed = EnvironmentCheck::check('PHP version', '7.4', false, 'Upgrade PHP.');
        $zones  = EnvironmentCheck::timeZoneTables(DatabaseRequirement::timeZoneWarningText());

        $t = new \OWA\Core\Template('base');
        $t->set('checks', [ $failed, $zones ]);
        $t->set('errors', [ $failed ]);
        $t->set_template('install_check_env.php');

        $html = $t->fetch();

        $this->assertStringContainsString('owa_installCheck is-warn', $html);
        $this->assertStringContainsString(htmlspecialchars(DatabaseRequirement::timeZoneWarningText(), ENT_QUOTES), $html);
        $this->assertStringContainsString('One thing needs fixing', $html, 'the warning is not counted as a failure');
    }

    public function testTheDefaultsStepShowsTheWarningByTheTimezone(): void
    {
        DatabaseRequirement::assumeTimeZones(false);

        $this->assertSame(DatabaseRequirement::timeZoneWarningText(),
            \OWA\Module\Base\Controller\InstallDefaultsEntry::zoneWarning());

        $html = $this->renderDefaultsStep(\OWA\Module\Base\Controller\InstallDefaultsEntry::zoneWarning());

        $this->assertStringContainsString('data-ui="install:zone-warning">'
            . htmlspecialchars(DatabaseRequirement::timeZoneWarningText(), ENT_QUOTES) . '</span>', $html);

        DatabaseRequirement::assumeTimeZones(true);

        $this->assertSame('', \OWA\Module\Base\Controller\InstallDefaultsEntry::zoneWarning());
        $this->assertStringNotContainsString('install:zone-warning', $this->renderDefaultsStep(''));
    }

    private function renderDefaultsStep(string $warning): string
    {
        $t = new \OWA\Core\Template('base');
        $t->set('defaults', []);
        $t->set('zone_warning', $warning);
        $t->set_template('install_defaults_entry.php');

        return $t->fetch();
    }
}
