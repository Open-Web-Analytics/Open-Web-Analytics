<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * On a completed install, a module with tables but no recorded schema version
 * is in an unknown state (Core\Module::hasUnknownSchemaState()): install() and update() refuse and
 * apply nothing, getSchemaVersion() reads 0, and cmd=core-install --force
 * moves its tables aside (Core\Module::moveTablesAside()).
 *
 * Needs no database: the module's tables are what the test says
 * (existingTables()), and the database the move-aside asks is a recording
 * one.
 */
final class UnknownSchemaStateTest extends TestCase
{
    /** @var object|null the database singleton the test replaced */
    private $savedDb = null;

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) {
            (new ReflectionProperty(\OWA\Core\CoreAPI::class, 'db'))->setValue(null, $this->savedDb);
            $this->savedDb = null;
        }
    }

    /** A module named test_unknown on a completed install ($complete), whose tables are $tables and which has one update class. */
    private function module(array $tables, bool $complete = true): \OWA\Core\Module
    {
        $module = new class($tables) extends \OWA\Core\Module {
            const NAME = 'test_unknown';

            /** @var list<string> */
            public $tables;

            /** @var bool what installComplete() answers */
            public $complete = true;

            protected function installComplete()
            {
                return $this->complete;
            }

            /** @var int how many times update() went on to collect updates */
            public $collected = 0;

            public function __construct(array $tables)
            {
                $this->tables                  = $tables;
                $this->required_schema_version = 3;
                parent::__construct();
            }

            public function existingTables()
            {
                return $this->tables;
            }

            public static function updateClasses($name)
            {
                return [2 => '\\OWA\\Module\\TestUnknown\\Update\\Update002'];
            }
        };

        $module->complete = $complete;

        return $module;
    }

    /** A recording database: tableExists() answers from $present, renameTable() is recorded. */
    private function database(array $present): object
    {
        $db = new class($present) {
            /** @var list<string> */
            public $present;

            /** @var list<string> */
            public $renamed = [];

            public function __construct(array $present)
            {
                $this->present = $present;
            }

            public function tableExists($table)
            {
                return in_array($table, $this->present, true);
            }

            public function renameTable($from, $to)
            {
                $this->renamed[] = $from . ' -> ' . $to;

                return true;
            }
        };

        $property      = new ReflectionProperty(\OWA\Core\CoreAPI::class, 'db');
        $this->savedDb = $property->getValue();
        $property->setValue(null, $db);

        return $db;
    }

    private const MESSAGE = 'Module test_unknown has tables but no recorded schema version, so the state of its tables is unknown. '
        . 'Run php cli.php cmd=core-install --force to move them aside and install the module fresh.';

    /** Detection: no recorded version and a table there. With no table, the state is known: nothing is installed. */
    public function testTablesWithNoRecordedVersionAreAnUnknownState(): void
    {
        $this->assertEmpty(\OWA\Core\CoreAPI::getSetting('test_unknown', 'schema_version'), 'precondition: no version recorded');

        $unknown = $this->module(['owa_test_unknown_thing']);
        $this->assertTrue($unknown->hasUnknownSchemaState());
        $this->assertSame(self::MESSAGE, $unknown->unknownSchemaStateProblem());
        $this->assertSame(0, $unknown->getSchemaVersion(), 'not read as 1, which would replay every update');
        $this->assertFalse($unknown->isSchemaCurrent());

        $none = $this->module([]);
        $this->assertFalse($none->hasUnknownSchemaState());
        $this->assertSame('', $none->unknownSchemaStateProblem());
        $this->assertSame(1, $none->getSchemaVersion());
    }

    /**
     * Before the install is complete, tables with no recorded version are from
     * an earlier attempt of this install: not unknown, read as 1, and nothing
     * refuses them.
     */
    public function testBeforeTheInstallIsCompleteTheStateIsKnown(): void
    {
        $module = $this->module(['owa_test_unknown_thing'], false);

        $this->assertFalse($module->hasUnknownSchemaState());
        $this->assertSame('', $module->unknownSchemaStateProblem());
        $this->assertSame(1, $module->getSchemaVersion());
    }

    /**
     * A fresh install that stopped part way, some tables made and no version
     * or install_complete recorded: run again, install() creates the missing
     * tables and records the required version. The wizard's re-submit and
     * cmd=core-install with the setup arguments both come through here
     * (testBothInstallPathsComeThroughInstall()).
     */
    public function testAPartialFreshInstallCompletesOnReRun(): void
    {
        require_once __DIR__ . '/fixtures/TestFixtureModule.php';

        $module = new class extends \OWA\Module\TestFixture\Module {
            /** @var list<string> */
            public $made = [];

            protected function installComplete()
            {
                return false;
            }

            public function existingTables()
            {
                return ['owa_test_fixture_a'];
            }

            public function createMissingTables()
            {
                $this->made[] = 'owa_test_fixture_b';

                return ['created' => ['owa_test_fixture_b'], 'existing' => ['owa_test_fixture_a'], 'failed' => []];
            }
        };

        try {
            $this->assertEmpty(\OWA\Core\CoreAPI::getSetting('test_fixture', 'schema_version'), 'precondition: no version recorded');
            $this->assertFalse($module->hasUnknownSchemaState());

            $this->assertTrue($module->install());
            $this->assertSame('', $module->install_problem);
            $this->assertSame(['owa_test_fixture_b'], $module->made, 'the missing table is created');
            $this->assertSame(1, (int) \OWA\Core\CoreAPI::getSetting('test_fixture', 'schema_version'), 'and the required version recorded');
        } finally {
            \OWA\Module\TestFixture\Module::forget();
        }
    }

    /** The wizard and cmd=core-install set up the schema through InstallManager::installSchema(), which is Base's install(). */
    public function testBothInstallPathsComeThroughInstall(): void
    {
        $root = dirname(__DIR__) . '/';

        $this->assertStringContainsString('$status = $this->installSchema();', (string) file_get_contents($root . 'modules/Base/Controller/InstallBase.php'));
        $this->assertStringContainsString('return $this->im->installSchema();', (string) file_get_contents($root . 'Core/Controller/Install.php'));
        $this->assertStringContainsString('$im->installSchema() !== true', (string) file_get_contents($root . 'modules/Base/Controller/CoreInstallCli.php'));
        $this->assertStringContainsString('$base->install();', (string) file_get_contents($root . 'modules/Base/Classes/InstallManager.php'));
    }

    /** InstallManager::unknownStateModules(), which cmd=core-install asks, names a module only on a completed install. */
    public function testOnlyACompletedInstallHasModulesInTheUnknownState(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $saved   = $service->modules;

        try {
            $service->modules = ['test_unknown' => $this->module(['owa_test_unknown_thing'], false)];
            $this->assertSame([], (new \OWA\Module\Base\Classes\InstallManager())->unknownStateModules());

            $service->modules = ['test_unknown' => $this->module(['owa_test_unknown_thing'])];
            $this->assertSame(['test_unknown'], (new \OWA\Module\Base\Classes\InstallManager())->unknownStateModules());
        } finally {
            $service->modules = $saved;
        }
    }

    /** update() refuses before it collects an update: none runs. */
    public function testNoUpdateRunsInTheUnknownState(): void
    {
        $module = $this->module(['owa_test_unknown_thing']);

        $this->assertFalse($module->update());
        $this->assertSame(self::MESSAGE, $module->update_problem);
        $this->assertSame([], (fn () => $this->updates)->call($module), 'no update was collected, so none was applied');
    }

    /** install(), which activation calls, refuses before creating anything or recording a version. */
    public function testInstallRefusesTheUnknownState(): void
    {
        $module = $this->module(['owa_test_unknown_thing']);

        $this->assertFalse($module->install());
        $this->assertSame(self::MESSAGE, $module->install_problem);
        $this->assertEmpty(\OWA\Core\CoreAPI::getSetting('test_unknown', 'schema_version'), 'no version recorded');
    }

    /** Each table is renamed <table>_before_<stamp>, and only after every new name was checked. */
    public function testTablesAreMovedAside(): void
    {
        $db     = $this->database([]);
        $module = $this->module(['owa_test_unknown_a', 'owa_test_unknown_b']);

        $this->assertSame([
            'moved'   => ['owa_test_unknown_a' => 'owa_test_unknown_a_before_20261128120000', 'owa_test_unknown_b' => 'owa_test_unknown_b_before_20261128120000'],
            'problem' => '',
        ], $module->moveTablesAside('20261128120000'));
        $this->assertSame(['owa_test_unknown_a -> owa_test_unknown_a_before_20261128120000', 'owa_test_unknown_b -> owa_test_unknown_b_before_20261128120000'], $db->renamed);
    }

    /** A new name a table already has is refused, and nothing is renamed, the tables before it included. */
    public function testANameThatWouldCollideIsRefusedAndNothingIsRenamed(): void
    {
        $db     = $this->database(['owa_test_unknown_b_before_20261128120000']);
        $module = $this->module(['owa_test_unknown_a', 'owa_test_unknown_b']);

        $this->assertSame([
            'moved'   => [],
            'problem' => 'owa_test_unknown_b cannot be moved aside: owa_test_unknown_b_before_20261128120000 exists. Nothing was renamed.',
        ], $module->moveTablesAside('20261128120000'));
        $this->assertSame([], $db->renamed);
    }

    /** A new name over 64 characters is refused, and nothing is renamed. */
    public function testANameOverTheLimitIsRefused(): void
    {
        $db     = $this->database([]);
        $long   = 'owa_' . str_repeat('x', 40);
        $module = $this->module([$long]);

        $this->assertStringContainsString('is longer than 64 characters. Nothing was renamed.', $module->moveTablesAside('20261128120000')['problem']);
        $this->assertSame([], $db->renamed);
    }

    /** Every shipped module's tables can be moved aside: the longest name with _before_<14 digits> is within the limit. */
    public function testEveryShippedTableFitsTheLimitMovedAside(): void
    {
        $longest = '';

        foreach (\OWA\Module\Base\Classes\TableInventory::owned() as $table => $group) {
            $name = \OWA\Core\Entity::TABLE_PREFIX . $table;

            if (strlen($name) > strlen($longest)) {
                $longest = $name;
            }
        }

        $this->assertNotSame('', $longest);
        $this->assertLessThanOrEqual(\OWA\Core\Module::TABLE_NAME_LIMIT,
            strlen(\OWA\Core\Module::movedAsideName($longest, '20261128120000')), $longest);
    }

    /** core-update-schema asks every active module, and refuses for one in the unknown state before any update. */
    public function testCoreUpdateSchemaRefusesBeforeAnyUpdate(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $saved   = $service->modules;
        $module  = $this->module(['owa_test_unknown_thing']);

        try {
            $service->modules = ['test_unknown' => $module];

            $this->assertSame(['test_unknown' => self::MESSAGE], \OWA\Module\Base\Controller\CoreUpdateSchemaCli::unknownStateProblems());
            $this->assertSame([], \OWA\Module\Base\Controller\CoreUpdateSchemaCli::unknownStateProblems('base'), 'one module asked by name');
        } finally {
            $service->modules = $saved;
        }
    }

    /** The updates screen and the CLI apply nothing while one is unknown: both ask unknownStateProblems() before their first update. */
    public function testEveryUpdatePathAsksFirst(): void
    {
        $root = dirname(__DIR__) . '/modules/Base/Controller/';

        foreach (['UpdatesApply.php', 'CoreUpdateSchemaCli.php'] as $file) {
            $source = (string) file_get_contents($root . $file);
            $asked  = strpos($source, 'unknownStateProblems(');
            $first  = strpos($source, '->update()');

            $this->assertNotFalse($asked, $file);
            $this->assertLessThan($first, $asked, "$file asks before its first update()");
        }
    }
}
