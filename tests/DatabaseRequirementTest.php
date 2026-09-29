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
