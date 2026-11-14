<?php

use OWA\Core\ModuleActivation;
use OWA\Core\RequestType;
use OWA\Core\ServerRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA_SERVER_ROLE (Core\ServerRole): what each role serves, what it needs in
 * owa-config.php, and which CLI commands run on it. The entry points
 * themselves are ServerRoleBootTest's.
 */
final class ServerRoleTest extends TestCase
{
    private $savedRole;

    /** Every constant a role with a database needs, defined. */
    private const DATABASE = array(
        'OWA_DB_NAME' => 'owa', 'OWA_DB_HOST' => 'db', 'OWA_DB_USER' => 'owa', 'OWA_DB_PASSWORD' => '',
        'OWA_NONCE_KEY' => 'k', 'OWA_NONCE_SALT' => 's', 'OWA_AUTH_KEY' => 'k', 'OWA_AUTH_SALT' => 's',
        'OWA_PUBLIC_URL' => 'https://owa.example.test/',
    );

    protected function setUp(): void
    {
        $this->savedRole = \OWA\Core\CoreAPI::getSetting('base', 'server_role');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', $this->savedRole);
    }

    /** @return iterable<string, array{string, string[], bool, bool}> */
    public static function matrix(): iterable
    {
        yield 'full'        => array('full',        array('tracking', 'ui', 'rest', 'cli', 'install'), true,  true);
        yield 'collector'   => array('collector',   array('tracking', 'cli'),                          false, false);
        yield 'worker'      => array('worker',      array('cli'),                                      true,  true);
        yield 'console'     => array('console',     array('ui', 'cli'),                                false, true);
        yield 'api'         => array('api',         array('rest', 'cli'),                              false, true);
        yield 'console,api' => array('console,api', array('ui', 'rest', 'cli'),                        false, true);
        yield 'blank'       => array('',            array('tracking', 'ui', 'rest', 'cli', 'install'), true,  true);
    }

    /** @dataProvider matrix */
    public function testEachRoleServesItsRequestTypes(string $value, array $types, bool $scheduler, bool $database): void
    {
        $role = new ServerRole($value);

        $this->assertSame($types, $role->requestTypes());
        $this->assertSame($scheduler, $role->runsScheduler());
        $this->assertSame($database, $role->hasDatabase());

        foreach (RequestType::ALL as $type) {
            $this->assertSame(in_array($type, $types, true), $role->serves($type), "$value / $type");
        }
    }

    public function testFullIsEveryRoleAndAListIsNormalised(): void
    {
        $this->assertSame(array('full'), (new ServerRole('console, FULL'))->roles());
        $this->assertSame(array('console', 'api'), (new ServerRole(' Console ,api,console'))->roles());
        $this->assertSame('console,api', (new ServerRole('console,api'))->name());
    }

    public function testUnknownNamesAreKeptForTheRefusal(): void
    {
        $role = new ServerRole('colector,console');

        $this->assertSame(array('colector'), $role->unknown());
        $this->assertSame(array('console'), $role->roles());
    }

    public function testOnlyACollectorQueuesWhateverTheSetting(): void
    {
        $this->assertTrue((new ServerRole('collector'))->queuesTrackingEvents());
        $this->assertFalse((new ServerRole('full'))->queuesTrackingEvents());
        $this->assertTrue((new ServerRole('collector,worker'))->hasDatabase(), 'combined with a database role');
    }

    public function testFullRunsEveryCommandAndOtherRolesOnlyTheirs(): void
    {
        $default = \OWA\Core\Controller\Cli::serverRoles();

        $this->assertSame(array('full', 'worker'), $default);
        $this->assertTrue((new ServerRole('full'))->mayRun(array('console')));
        $this->assertTrue((new ServerRole('worker'))->mayRun($default));
        $this->assertFalse((new ServerRole('console'))->mayRun($default));
        $this->assertFalse((new ServerRole('collector'))->mayRun($default));
        $this->assertTrue((new ServerRole('console,api'))->mayRun(array('api')));
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function testARoleWithADatabaseNeedsItsConstants(): void
    {
        foreach (array('full', 'worker', 'console', 'api') as $value) {
            $this->assertSame(array(), (new ServerRole($value))->configProblems(self::DATABASE, array()), $value);
        }

        $defined = self::DATABASE;
        unset($defined['OWA_DB_HOST'], $defined['OWA_AUTH_KEY']);

        $this->assertSame(array('Role worker needs OWA_DB_HOST, OWA_AUTH_KEY in owa-config.php.'),
            (new ServerRole('worker'))->configProblems($defined, array()));
    }

    /** OWA_PUBLIC_URL, not empty, on every role with a database; a collector builds no links. */
    public function testARoleWithADatabaseNeedsThePublicUrl(): void
    {
        foreach (array(array(), array('OWA_PUBLIC_URL' => '')) as $variant) {

            $defined = array_merge(self::DATABASE, $variant);

            if (!$variant) {
                unset($defined['OWA_PUBLIC_URL']);
            }

            foreach (array('full', 'worker', 'console', 'api') as $value) {
                $this->assertSame(array("Role $value needs OWA_PUBLIC_URL in owa-config.php."),
                    (new ServerRole($value))->configProblems($defined, array()), $value);
            }
        }

        $this->assertSame(array(), (new ServerRole('collector'))->configProblems(
            array('OWA_PUBLIC_URL' => ''), array('sqs' => 'OWA_SQS_QUEUE_URL')));
    }

    public function testACollectorNeedsAQueueBackendAndNoDatabase(): void
    {
        $collector = new ServerRole('collector');

        $this->assertSame(array(), $collector->configProblems(array(), array('sqs' => 'OWA_SQS_QUEUE_URL')));
        $this->assertSame(array('Role collector needs a queue backend to send beacons to: set OWA_SQS_QUEUE_URL.'),
            $collector->configProblems(array(), array(), array('OWA_SQS_QUEUE_URL')));
    }

    public function testTwoQueueBackendsAreRefusedOnEveryRole(): void
    {
        $two = array('sqs' => 'OWA_SQS_QUEUE_URL', 'zzq' => 'OWA_ZZQ_URL');

        foreach (array('full', 'collector', 'worker') as $value) {
            $this->assertContains('Two queue backends are configured (OWA_SQS_QUEUE_URL, OWA_ZZQ_URL): set one.',
                (new ServerRole($value))->configProblems(self::DATABASE, $two), $value);
        }
    }

    public function testBootRefusesARequestTheRoleDoesNotServeWith404(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');

        $problem = ServerRole::bootProblem('ui', array(), true, false);

        $this->assertSame(404, $problem['status']);
        $this->assertSame('This server\'s role is collector, which serves tracking, cli, not ui requests.', $problem['message']);
    }

    public function testBootChecksTheConfigurationOnlyOnceThereIsOne(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'worker');

        // Installing stated, not read: OWA_INSTALLING is process-global, and
        // InstallCli defines it for the rest of a suite run.
        $this->assertNull(ServerRole::bootProblem('cli', array(), false, false), 'before install: nothing to check');
        $this->assertNull(ServerRole::bootProblem('cli', array(), true, true), 'the installer writes the configuration');
        $this->assertSame(503, ServerRole::bootProblem('cli', array(), true, false)['status']);
        $this->assertNull(ServerRole::bootProblem('cli', self::DATABASE, true, false));
    }

    public function testBootRefusesAnUnknownRoleForEveryRequest(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'nonsense');

        foreach (RequestType::ALL as $type) {
            $this->assertSame(503, ServerRole::bootProblem($type, self::DATABASE, true, false)['status'], $type);
        }
    }

    public function testRetiredConstantsGetAnUpgradeNotice(): void
    {
        $notices = ServerRole::upgradeNotices(array('OWA_QUEUE_EVENTS' => true, 'OWA_SCHEDULER_ENABLED' => false,
            'OWA_USE_STATIC_CONFIG_ONLY' => true, 'OWA_DB_HOST' => 'db'));

        $this->assertSame(array('OWA_QUEUE_EVENTS', 'OWA_USE_STATIC_CONFIG_ONLY', 'OWA_SCHEDULER_ENABLED'), array_keys($notices));
        $this->assertStringContainsString('OWA_QUEUE_TRACKING_EVENTS', $notices['OWA_QUEUE_EVENTS']);
        $this->assertStringContainsString('OWA_SERVER_ROLE', $notices['OWA_USE_STATIC_CONFIG_ONLY']);
    }

    public function testTheOneXStaticSwitchOnlyStopsTheStoredSettingsRead(): void
    {
        $saved = \OWA\Core\CoreAPI::getSetting('base', 'skip_stored_settings');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'full');
            \OWA\Core\CoreAPI::setSetting('base', 'skip_stored_settings', true);

            $this->assertFalse(ServerRole::readsStoredSettings());
            $this->assertTrue(ServerRole::current()->hasDatabase(), 'still a full server with its database');

            \OWA\Core\CoreAPI::setSetting('base', 'skip_stored_settings', false);
            $this->assertTrue(ServerRole::readsStoredSettings());

            \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');
            $this->assertFalse(ServerRole::readsStoredSettings());
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'skip_stored_settings', $saved);
        }
    }

    // ---------------------------------------------------------------------
    // CLI commands
    // ---------------------------------------------------------------------

    /** Every command's roles are roles, and full is among them. */
    public function testEveryCommandNamesRealRolesIncludingFull(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->loadCliCommands();

        foreach ((array) $s->getMap('cli_commands') as $command => $action) {

            $roles = ServerRole::commandRoles($action);

            $this->assertIsArray($roles, $command);
            $this->assertSame(array(), array_diff($roles, ServerRole::ALL), $command);
            $this->assertContains('full', $roles, $command);
        }
    }

    /** @return iterable<string, array{string, string[]}> */
    public static function marks(): iterable
    {
        yield 'server-check'         => array('server-check',         ServerRole::ALL);
        yield 'instance-info'        => array('instance-info',        ServerRole::ALL);
        yield 'drain-tracker-ingest' => array('drain-tracker-ingest', array('full', 'worker', 'collector'));
        yield 'change-password'      => array('change-password',      array('full', 'worker', 'console'));
        yield 'app-password-create'  => array('app-password-create',  array('full', 'worker', 'console'));
        yield 'add-site'             => array('add-site',             array('full', 'worker', 'console'));
        yield 'schedule-status'      => array('schedule-status',      array('full', 'worker', 'console'));
        yield 'schedule-run'         => array('schedule-run',         array('full', 'worker'));
        yield 'update'               => array('update',               array('full', 'worker'));
    }

    /** @dataProvider marks */
    public function testCommandMarks(string $command, array $roles): void
    {
        $action = \OWA\Core\CoreAPI::getCliCommandClass($command);

        $this->assertSame($roles, ServerRole::commandRoles($action));
    }

    public function testACommandNotMarkedForTheRoleIsNamedInTheRefusal(): void
    {
        $action = \OWA\Core\CoreAPI::getCliCommandClass('schedule-run');

        $this->assertSame('cmd=schedule-run does not run on this server: its role is collector, and the command runs on full, worker.',
            (new ServerRole('collector'))->commandProblem('schedule-run', $action));
        $this->assertNull((new ServerRole('worker'))->commandProblem('schedule-run', $action));
    }

    // ---------------------------------------------------------------------
    // Modules switched on by their own constant
    // ---------------------------------------------------------------------

    public function testTheSqsModuleIsDeclaredToBeActivatedByItsQueueUrl(): void
    {
        $this->assertSame(array('activated_by' => array('OWA_SQS_QUEUE_URL'), 'intake_queue_type' => 'sqs'),
            ModuleActivation::declarations()['sqs'] ?? null);
        $this->assertSame(array('OWA_SQS_QUEUE_URL'), ModuleActivation::queueBackendConstants());
    }

    public function testAModuleIsActivatedByASetConstantOnly(): void
    {
        $decl = array(
            'sqs'   => array('activated_by' => array('OWA_SQS_QUEUE_URL'), 'intake_queue_type' => 'sqs'),
            'cache' => array('activated_by' => array('OWA_ZZ_CACHE_SERVERS', 'OWA_ZZ_CACHE_HOST'), 'intake_queue_type' => ''),
        );

        $this->assertSame(array(), ModuleActivation::activeByConstant(array(), $decl));
        $this->assertSame(array(), ModuleActivation::activeByConstant(array('OWA_SQS_QUEUE_URL' => ''), $decl), 'empty is not set');
        $this->assertSame(array('cache' => 'OWA_ZZ_CACHE_HOST'),
            ModuleActivation::activeByConstant(array('OWA_ZZ_CACHE_HOST' => 'h'), $decl), 'any of its constants');
        $this->assertSame(array('sqs' => 'OWA_SQS_QUEUE_URL'),
            ModuleActivation::queueBackends(array('OWA_SQS_QUEUE_URL' => 'https://q', 'OWA_ZZ_CACHE_HOST' => 'h'), $decl),
            'only a module with an intake queue type is a backend');
    }

    public function testTwoQueueModulesConfiguredAreTwoBackends(): void
    {
        $decl = array(
            'sqs' => array('activated_by' => array('OWA_SQS_QUEUE_URL'), 'intake_queue_type' => 'sqs'),
            'zzq' => array('activated_by' => array('OWA_ZZQ_URL'), 'intake_queue_type' => 'zzq'),
        );

        $backends = ModuleActivation::queueBackends(array('OWA_SQS_QUEUE_URL' => 'a', 'OWA_ZZQ_URL' => 'b'), $decl);

        $this->assertSame(array('sqs' => 'OWA_SQS_QUEUE_URL', 'zzq' => 'OWA_ZZQ_URL'), $backends);
        $this->assertNotSame(array(), (new ServerRole('full'))->configProblems(self::DATABASE, $backends));
    }

    public function testTheModulesScreenCannotTurnOffAModuleItsConstantActivates(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();
        $c->noteConfigConstant('hello', 'is_active', 'OWA_ZZ_HELLO');

        try {
            $this->assertSame('OWA_ZZ_HELLO', \OWA\Core\Module::activatedByConstant('hello'));
            $this->assertFalse(\OWA\Core\CoreAPI::moduleClassFactory('hello')->deactivate());
        } finally {
            $c->forgetConfigConstant('hello', 'is_active');
        }

        $this->assertSame('', \OWA\Core\Module::activatedByConstant('hello'));
    }

    // ---------------------------------------------------------------------
    // Region and database type
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{string, ?string}> */
    public static function queueUrls(): iterable
    {
        yield 'standard' => array('https://sqs.us-east-1.amazonaws.com/123456789012/q', 'us-east-1');
        yield 'China'    => array('https://sqs.cn-north-1.amazonaws.com.cn/123456789012/q', 'cn-north-1');
        yield 'legacy'   => array('https://us-west-2.queue.amazonaws.com/123456789012/q', 'us-west-2');
        yield 'FIPS'     => array('https://sqs-fips.us-gov-west-1.amazonaws.com/123456789012/q', 'us-gov-west-1');
        yield 'VPC'      => array('https://vpce-0ab12.sqs.eu-west-2.vpce.amazonaws.com/123456789012/q', 'eu-west-2');
        yield 'not AWS'  => array('https://sqs.us-east-1.example.com/123456789012/q', null);
        yield 'empty'    => array('', null);
    }

    /** @dataProvider queueUrls */
    public function testTheRegionIsReadFromTheQueueUrlsHost(string $url, ?string $region): void
    {
        $this->assertSame($region, \OWA\Module\Sqs\Classes\Sqs::regionFromUrl($url));
    }

    public function testTheDatabaseTypeDefaultsToMysql(): void
    {
        $settings = require dirname(__DIR__) . '/modules/Base/settings.php';

        $this->assertSame('mysql', $settings['settings']['db_type']['default']);
    }
}
