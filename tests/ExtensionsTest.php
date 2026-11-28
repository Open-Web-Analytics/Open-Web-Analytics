<?php

use OWA\Core\CoreAPI;
use OWA\Core\Env;
use OWA\Core\Extensions;
use OWA\Module\Base\Classes\Service;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Third-party modules in extensions/<Dir>/ (Core\Extensions): found after
 * OWA's own, loaded and registered as any module, and refused when they
 * declare a name OWA's modules register. The fixtures are written under
 * Env::extensionsDir() for the class and removed after it, with
 * extensions/ itself when the class made it.
 */
final class ExtensionsTest extends TestCase
{
    private static bool $made = false;

    /** @var list<string> the fixture directories written */
    private static array $dirs = [];

    public static function setUpBeforeClass(): void
    {
        self::$made = ! is_dir(Env::extensionsDir());

        // Loads, registers: a command, a job, an event type, a REST route, an implementation and an entity of its own.
        self::extension('OwatestAcme', 'owatest_acme', <<<'PHP'
            const ENTITIES = [ 'thing' ];

            public function init() {
                $this->registerCliCommand( 'owatest-acme-hello', 'owatest_acme.hello' );
                $this->registerJob( 'owatest-acme-job', 'owatest-acme-hello', '@daily', [], 'A test job.' );
                $this->addTrackingEventProcessor( 'owatest_acme_event', 'owatest_acme.process' );
                $this->registerRestApiRoute( 'v1', 'things', 'GET', 'OWA\\Extension\\OwatestAcme\\Controller\\Things' );
                $this->registerImplementation( 'owatest_implementations', 'acme', 'OWA\\Extension\\OwatestAcme\\Classes\\Thing' );
            }
PHP, ['Entity/Thing.php' => <<<'PHP'
<?php
namespace OWA\Extension\OwatestAcme\Entity;

class Thing extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'owatest_acme_thing' );
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );
    }
}
PHP]);

        // OWA's name, in a directory of its own; OWA's directory.
        self::extension('OwatestNameClash', 'base', <<<'PHP'
            public function init() {
                $this->registerRestApiRoute( 'v1', 'sites', 'GET', 'OWA\\Extension\\OwatestNameClash\\Controller\\Sites' );
            }
PHP);
        self::extension('Base', 'owatest_dir_clash', '');

        // One of each kind of name Base registers.
        self::extension('OwatestTableClash', 'owatest_table_clash', "const ENTITIES = [ 'site' ];", ['Entity/Site.php' => <<<'PHP'
<?php
namespace OWA\Extension\OwatestTableClash\Entity;

class Site extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'site' );
    }
}
PHP]);
        self::extension('OwatestCommandClash', 'owatest_command_clash',
            "public function init() { \$this->registerCliCommand( 'core-update-status', 'owatest_command_clash.status' ); }");
        self::extension('OwatestJobClash', 'owatest_job_clash',
            "public function init() { \$this->registerJob( 'rotate-partitions', 'owatest-nothing', '@daily', [] ); }");
        self::extension('OwatestEventClash', 'owatest_event_clash',
            "public function init() { \$this->addTrackingEventProcessor( 'page_view', 'owatest_event_clash.process' ); }");
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$dirs as $dir) {
            exec('rm -rf ' . escapeshellarg(Env::extensionsDir() . $dir));
        }

        if (self::$made) {
            @rmdir(Env::extensionsDir());
        }
    }

    protected function setUp(): void
    {
        Extensions::forget();
    }

    protected function tearDown(): void
    {
        Extensions::forget();
    }

    public function testExtensionsAreFoundAfterOwasModules(): void
    {
        $present = CoreAPI::getPresentModules();

        $this->assertSame('Base', $present[0]);
        $this->assertContains('OwatestAcme', $present);
        $this->assertGreaterThan(array_search('Sqs', $present, true), array_search('OwatestAcme', $present, true), 'after every one of OWA\'s');
        $this->assertSame(1, count(array_keys($present, 'Base', true)), 'OWA\'s Base, not the extension of that name');

        $this->assertSame(Env::root() . '/extensions/OwatestAcme/', Env::moduleDir('owatest_acme'));
        $this->assertSame('OWA\\Extension\\OwatestAcme', Env::moduleNamespace('owatest_acme'));
        $this->assertSame(Env::root() . '/modules/Base/', Env::moduleDir('base'));
        $this->assertSame('OWA\\Module\\Base', Env::moduleNamespace('Base'));
        $this->assertContains('OWA\\Extension\\OwatestAcme\\Module', Env::moduleClasses());
        $this->assertContains('OwatestAcme', \OWA\Core\CliWithoutBoot::moduleDirs());
    }

    /** Built and added as any module: its registrations reach the service, its entity is its own. */
    public function testAnExtensionLoadsAndRegisters(): void
    {
        $m = CoreAPI::moduleClassFactory('owatest_acme');

        $this->assertInstanceOf('OWA\\Extension\\OwatestAcme\\Module', $m);
        $this->assertSame(Env::root() . '/extensions/OwatestAcme/', $m->path);
        $this->assertSame('owa_owatest_acme_thing', CoreAPI::entityFactory('owatest_acme.thing')->getTableName());
        $this->assertNull(CoreAPI::lookupRestRoute('GET', 'owatest_acme', 'v1', 'things'), 'nothing reaches the service before the module is added');

        $s = $this->service();
        $this->assertTrue($s->loadModule($m));

        $this->assertSame([], Extensions::refused());
        $this->assertArrayHasKey('owatest-acme-hello', $m->cli_commands);
        $this->assertArrayHasKey('owatest-acme-job', $m->scheduled_jobs);
        $this->assertArrayHasKey('tracking.owatest_acme_event', $m->event_processors);
        $this->assertSame('OWA\\Extension\\OwatestAcme\\Controller\\Things', $s->getRestApiRoute('owatest_acme', 'v1', 'things', 'GET')['class_name']);
        $this->assertSame('OWA\\Extension\\OwatestAcme\\Classes\\Thing', $s->getMapValue('owatest_implementations', 'acme')[0]);
    }

    /** Activation installs it: its table, its is_active. With a database only. */
    public function testAnExtensionActivates(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database');
        }

        try {
            $this->assertTrue((bool) CoreAPI::installModule('owatest_acme', $problem), (string) $problem);
            $this->assertTrue((bool) CoreAPI::getSetting('owatest_acme', 'is_active'));
            $this->assertTrue((bool) CoreAPI::dbSingleton()->tableExists('owa_owatest_acme_thing'));
        } finally {
            $db = CoreAPI::dbSingleton();
            $db->query('DROP TABLE IF EXISTS owa_owatest_acme_thing');
            $c = CoreAPI::configSingleton();
            foreach (array_keys(\OWA\Core\Module::mechanicalSettings()) as $key) {
                $c->removeSetting('owatest_acme', $key);
            }
            $db->query(sprintf('DELETE FROM %s WHERE module = ?', CoreAPI::entityFactory('base.setting')->getTableName()), ['owatest_acme']);
        }
    }

    /** OWA's NAME, or OWA's directory: refused when the modules are found, so neither is ever built in OWA's place. */
    public function testAnExtensionWithOwasNameIsRefused(): void
    {
        $present = CoreAPI::getPresentModules();

        $this->assertNotContains('OwatestNameClash', $present);
        $this->assertSame('extensions/OwatestNameClash is not loaded: its name base is registered already, by the module base. Rename it in the extension.',
            Extensions::refused()['OwatestNameClash']);
        $this->assertSame('extensions/Base is not loaded: OWA has a module of that name, modules/Base. Rename the extension.', Extensions::refused()['Base']);
        $this->assertInstanceOf(\OWA\Module\Base\Module::class, CoreAPI::moduleClassFactory('base'));
    }

    /** A REST route is /<module>/<version>/<route>: only OWA's NAME could take one of OWA's, and that extension's routes never reach the service. */
    public function testOwasRestRoutesAreNeverDisplaced(): void
    {
        $owas = CoreAPI::lookupRestRoute('GET', 'base', 'v1', 'sites');
        $m    = new \OWA\Extension\OwatestNameClash\Module();
        $s    = $this->service();

        $this->assertFalse($s->loadModule($m));
        $this->assertStringContainsString('its name base is registered already', Extensions::refused()['OwatestNameClash']);
        $this->assertSame($owas, CoreAPI::lookupRestRoute('GET', 'base', 'v1', 'sites'));
        $this->assertSame('OWA\\Module\\Base\\Controller\\SitesRest', CoreAPI::lookupRestRoute('GET', 'base', 'v1', 'sites')['class_name']);
        $this->assertSame([], (array) ($s->getAllRestApiRoutes()['base'] ?? []), 'nothing of it registered');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function clashes(): array
    {
        return [
            'entity table'  => ['owatest_table_clash', 'its table owa_site is registered already, by the module base'],
            'CLI command'   => ['owatest_command_clash', 'its CLI command core-update-status is registered already, by the module base'],
            'scheduled job' => ['owatest_job_clash', 'its scheduled job rotate-partitions is registered already, by the module base'],
            'event type'    => ['owatest_event_clash', 'its event type tracking.page_view is registered already, by the module base'],
        ];
    }

    /** Refused at load: not added, nothing registered, a notice and a System Health finding naming it and the name. */
    #[\PHPUnit\Framework\Attributes\DataProvider('clashes')]
    public function testAClashingExtensionIsRefusedAtLoad(string $name, string $said): void
    {
        $s   = $this->service();
        $m   = CoreAPI::moduleClassFactory($name);
        $dir = \OWA\Core\Lib::moduleDirName($name);

        $this->assertFalse($s->loadModule($m));
        $this->assertArrayNotHasKey($name, $s->modules);
        $this->assertStringContainsString("extensions/$dir is not loaded: $said.", Extensions::refused()[$dir]);

        $finding = \OWA\Module\Base\Classes\SystemHealth::refusedExtensions();
        $this->assertSame('red', $finding['level']);
        $this->assertStringContainsString("extensions/$dir is not loaded", $finding['detail']);

        // Base's own stays as it was.
        $this->assertSame($s->modules['base'], $s->getModule('base'));
    }

    /** Refused at activation too: install() creates nothing and says why. */
    public function testAClashingExtensionIsNotActivated(): void
    {
        $m = CoreAPI::moduleClassFactory('owatest_command_clash');

        $this->assertFalse($m->install());
        $this->assertStringContainsString('its CLI command core-update-status is registered already', $m->install_problem);
        $this->assertArrayHasKey('OwatestCommandClash', Extensions::refused());
    }

    public function testSystemHealthSaysNothingWithoutARefusal(): void
    {
        $this->assertNull(\OWA\Module\Base\Classes\SystemHealth::refusedExtensions([]));
    }

    // ---------------------------------------------------------------------

    /** A fresh service with Base loaded first, as boot loads it. */
    private function service(): Service
    {
        $s = new Service();
        $s->addModule(CoreAPI::serviceSingleton()->getModule('base'));

        return $s;
    }

    /** An extension's Module.php, with $body in its class, and $files beside it. */
    private static function extension(string $dir, string $name, string $body, array $files = []): void
    {
        $root = Env::extensionsDir() . $dir;
        @mkdir($root, 0755, true);
        self::$dirs[] = $dir;

        file_put_contents("$root/Module.php", <<<PHP
<?php
namespace OWA\\Extension\\$dir;

class Module extends \\OWA\\Core\\Module {

    const NAME = '$name';

    public function __construct() {

        \$this->display_name = '$dir';
        \$this->required_schema_version = 1;

        foreach ( \\OWA\\Core\\Module::mechanicalSettings() as \$key => \$args ) {

            \$this->registerSettingsField( \$key, \$args );
        }

        parent::__construct();
    }

$body
}

PHP);

        foreach ($files as $rel => $source) {
            @mkdir(dirname("$root/$rel"), 0755, true);
            file_put_contents("$root/$rel", $source);
        }
    }
}
