<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\DatabaseRequirement;

/**
 * OWA 2.0 runs on MySQL 8.0.21 or later, or MariaDB 10.11 or later, and is
 * refused elsewhere before a table is created or changed.
 */
final class DatabaseRequirementTest extends TestCase
{
    protected function tearDown(): void
    {
        DatabaseRequirement::assume(null);
        DatabaseRequirement::assumeTimeZones(null);
        \OWA\Core\Strings::useTranslations(null);
    }

    /** Missing time zone tables are a warning with its fix, not a refusal. */
    public function testMissingTimeZoneTablesAreAWarning(): void
    {
        DatabaseRequirement::assumeTimeZones(false);

        $warning = (string) DatabaseRequirement::timeZoneWarning();

        $this->assertStringStartsWith('The database server\'s time zone tables are not loaded, so hour, minute and '
          . 'date-hour report as (not set).', $warning);
        $this->assertStringContainsString('mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql', $warning);
        $this->assertStringContainsString('hosting provider', $warning);

        DatabaseRequirement::assumeTimeZones(true);

        $this->assertNull(DatabaseRequirement::timeZoneWarning());
    }

    /** The servers CI runs on have the tables loaded, and the check says so. */
    public function testThisServerConvertsNamedTimeZones(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This asks the database server.');
        }

        $this->assertTrue(\OWA\Core\CoreAPI::dbSingleton()->namedTimeZonesConvert());
        $this->assertNull(DatabaseRequirement::timeZoneWarning());
    }

    /** The installer form shows these, so each is in the locale in use. */
    public function testTheRefusalsAreTranslated(): void
    {
        \OWA\Core\Strings::useTranslations([
            'This database server is MariaDB %s. OWA needs MariaDB %s or later -- the oldest '
          . 'MariaDB release still supported -- or MySQL %s or later.' => 'MariaDB %s: zu alt (%s, %s).',
            'This database server is MySQL %s. OWA needs MySQL %s or later -- its reporting build '
          . 'uses JSON_VALUE and window functions -- or MariaDB %s or later.' => 'MySQL %s: zu alt (%s, %s).',
            'OWA could not read the database server\'s version (%s). It needs MySQL %s '
          . 'or later, or MariaDB %s or later.' => 'Version unlesbar (%s; %s, %s).',
            'nothing was returned' => 'leer',
        ]);

        $this->assertSame('MariaDB 10.6.18: zu alt (10.11, 8.0.21).', DatabaseRequirement::problemFor('10.6.18-MariaDB'));
        $this->assertSame('MySQL 5.7.44: zu alt (8.0.21, 10.11).', DatabaseRequirement::problemFor('5.7.44-log'));
        $this->assertSame('Version unlesbar (leer; 8.0.21, 10.11).', DatabaseRequirement::problemFor(''));
    }

    /** @return array VERSION() string => accepted? */
    public static function versions(): array
    {
        return [
            'MySQL 5.7'               => ['5.7.44-log', false],
            'MySQL 8.0.20'            => ['8.0.20', false],
            'MySQL 8.0.21'            => ['8.0.21', true],
            'MySQL 8.0 LTS build'     => ['8.0.39-0ubuntu0.22.04.1', true],
            'MySQL 8.4'               => ['8.4.10', true],
            'Percona 8.0'             => ['8.0.35-27', true],
            'MariaDB 10.6'            => ['10.6.18-MariaDB', false],
            'MariaDB 10.11'           => ['10.11.6-MariaDB-1:10.11.6+maria~ubu2204', true],
            'MariaDB 11.4'            => ['11.4.2-MariaDB-log', true],
            'MariaDB 5.5 (says 5.5)'  => ['5.5.68-MariaDB', false],
            'nothing'                 => ['', false],
            'not a version'           => ['unknown', false],
        ];
    }

    /** @dataProvider versions */
    public function testWhichServersAreAccepted(string $version, bool $accepted): void
    {
        $problem = DatabaseRequirement::problemFor($version);

        $this->assertSame($accepted, $problem === null, (string) $problem);

        if (!$accepted) {
            $this->assertStringContainsString('MySQL 8.0.21', $problem, 'the refusal names what would do');
            $this->assertStringContainsString('MariaDB 10.11', $problem);
        }
    }

    /** MariaDB below the floor is told MariaDB's floor, not MySQL's alone. */
    public function testARefusalSaysWhichServerItSaw(): void
    {
        $this->assertStringContainsString('is MariaDB 10.6.18',
            DatabaseRequirement::problemFor('10.6.18-MariaDB'));
        $this->assertStringContainsString('is MySQL 5.7.44',
            DatabaseRequirement::problemFor('5.7.44-log'));
    }

    /** The server the suite runs against -- MySQL or MariaDB in CI -- is accepted. */
    public function testThisServerIsAccepted(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('This asks the database server.');
        }

        $this->assertNull(DatabaseRequirement::problem());
    }

    /** An installation on an unsupported server applies no update. */
    public function testTheUpdateRefusesAnUnsupportedServer(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('The update reads the recorded schema version.');
        }

        DatabaseRequirement::assume('5.7.44-log');

        $base = \OWA\Core\CoreAPI::serviceSingleton()->getModule('base');

        $this->assertFalse($base->update(), 'nothing may be applied on MySQL 5.7');
    }

    /**
     * The installer creates no table on an unsupported server.
     *
     * Only against a throwaway database, or none: if the check ever went
     * missing, this would run a real schema install, and on a developer's
     * installation that is their data.
     */
    public function testTheInstallerRefusesAnUnsupportedServer(): void
    {
        if (owa_test_db_available()
                && !preg_match('/(isolation|e2e|scratch|selfhost)/i',
                    (string) \OWA\Core\CoreAPI::getSetting('base', 'db_name'))) {
            $this->markTestSkipped('Runs only against a throwaway database: a regression here would install into it.');
        }

        DatabaseRequirement::assume('10.6.18-MariaDB');

        $installer = \OWA\Core\CoreAPI::supportClassFactory('base', 'installManager');

        $this->assertFalse($installer->installSchema());
    }
}
