<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\InstallDatabase;

/**
 * The installer's "Let OWA create a new database" option: the name is the
 * Organization's (owa_org_<id>), it is never one that already exists, and
 * the Organization install creates takes the id from it.
 */
final class InstallDatabaseTest extends TestCase
{
    public function testTheNameCarriesTheOrganizationId(): void
    {
        $id = InstallDatabase::mintOrganizationId();

        $this->assertMatchesRegularExpression('/^[1-9]\d{0,18}$/', $id);
        $this->assertSame('owa_org_' . $id, InstallDatabase::nameFor($id));
        $this->assertSame($id, InstallDatabase::organizationIdFrom(InstallDatabase::nameFor($id)));
        $this->assertNotSame($id, InstallDatabase::mintOrganizationId(), 'random, not derived');
    }

    public function testAnyOtherNameCarriesNone(): void
    {
        foreach (['owa', 'paa_wordpress', 'owa_org_', 'owa_org_0', 'owa_org_12x', 'xowa_org_12', 'owa_org_12345678901234567890'] as $name) {
            $this->assertNull(InstallDatabase::organizationIdFrom($name), $name);
        }
    }

    /** The first Organization of a database OWA named takes its id; any other install keeps the id it always derived. */
    public function testTheOrganizationTakesItsIdFromTheDatabaseName(): void
    {
        $this->assertSame('4242424242', \OWA\Module\Base\Classes\SiteManager::newOrganizationId('owa_org_4242424242'));
        $this->assertSame(
            \OWA\Core\CoreAPI::entityFactory('base.organization')->generateId('organization:default'),
            \OWA\Module\Base\Classes\SiteManager::newOrganizationId('test_openwebanalytics'));
    }

    /** Created, then refused as existing, then taken back: a real round trip on the server. */
    public function testCreateRefusesAnExistingDatabaseAndDropTakesItBack(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('creates a database');
        }

        $name = InstallDatabase::nameFor(InstallDatabase::mintOrganizationId());
        $db   = \OWA\Core\CoreAPI::dbSingleton();

        try {
            $this->assertSame(['ok' => true, 'error' => null], InstallDatabase::create($name));
            $this->assertTrue($db->databaseExists($name));

            // The tables' character set, which an undeclared table inherits.
            $row = $db->get_row(sprintf(
                "SELECT DEFAULT_CHARACTER_SET_NAME AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '%s'", $name));
            $this->assertMatchesRegularExpression('/^utf8(mb3)?$/', (string) ($row['c'] ?? ''));

            $again = InstallDatabase::create($name);
            $this->assertFalse($again['ok'], 'never into a database that exists');
            $this->assertStringContainsString('already exists', $again['error']);
        } finally {
            InstallDatabase::dropCreated($name);
        }

        $this->assertFalse($db->databaseExists($name));
    }

    public function testANameOwaDidNotGiveIsNeitherCreatedNorDropped(): void
    {
        $this->assertFalse(InstallDatabase::create('paa_wordpress')['ok']);
        $this->assertFalse(InstallDatabase::dropCreated('paa_wordpress'), 'never drops a database it did not name');
    }

    public function testTheServerIsNamedFromItsVersion(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('asks the server');
        }

        $this->assertMatchesRegularExpression('/^(MySQL|MariaDB) \d+\.\d+\.\d+$/', InstallDatabase::serverDescription());
    }

    /** The form: existing is the default, and the name a created database would get is shown, not typed. */
    public function testTheFormOffersBothAndShowsTheName(): void
    {
        $t = new \OWA\Core\Template('base');
        $t->set('config', ['db_mode' => 'existing', 'db_create_name' => 'owa_org_123456789', 'db_name' => '']);
        $t->set('public_url', 'https://example.test/');
        $this->assertTrue($t->set_template('install_config_entry.php'));
        $html = (string) $t->fetch();

        $this->assertMatchesRegularExpression('/value="existing" checked/', $html);
        $this->assertStringContainsString('Let OWA create a new database for this install (CREATE permissions required)', $html);
        $this->assertStringContainsString('<code>owa_org_123456789</code>', $html);
        $this->assertMatchesRegularExpression('/type="hidden" name="[^"]*db_create_name" value="owa_org_123456789"/', $html);
        $this->assertDoesNotMatchRegularExpression('/type="text"[^>]*db_create_name/', $html, 'the name cannot be typed');
        $this->assertStringContainsString('MySQL / MariaDB', $html);
    }

    /** The defaults step names the server and database it connected to, and says nothing when it has neither. */
    public function testTheDefaultsStepNamesTheConnection(): void
    {
        $render = function (string $server, string $name): string {
            $t = new \OWA\Core\Template('base');
            $t->set('defaults', []);
            $t->set('db_server', $server);
            $t->set('db_name', $name);
            $this->assertTrue($t->set_template('install_defaults_entry.php'));
            return (string) $t->fetch();
        };

        $this->assertStringContainsString(
            'Connected to MariaDB 10.11.6, database <code>owa_org_123456789</code>.',
            $render('MariaDB 10.11.6', 'owa_org_123456789'));
        $this->assertStringNotContainsString('Connected to', $render('', ''));
    }
}
