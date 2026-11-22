<?php

use OWA\Core\ModuleActivation;
use OWA\Core\RequestType;
use OWA\Core\ServerRole;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/fixtures/TestFixtureModule.php';

/**
 * OWA_SERVER_ROLE (Core\ServerRole): what each role serves, what it needs in
 * owa-config.php, and which CLI commands run on it. The entry points
 * themselves are ServerRoleBootTest's.
 */
final class ServerRoleTest extends TestCase
{
    private $savedRole;

    /** Every constant a role with a database needs, defined. */
    private const DATABASE = [
        'OWA_DB_NAME' => 'owa', 'OWA_DB_HOST' => 'db', 'OWA_DB_USER' => 'owa', 'OWA_DB_PASSWORD' => '',
        'OWA_NONCE_KEY'  => 'owatest-nonce-key-0123456789abcdef',
        'OWA_NONCE_SALT' => 'owatest-nonce-salt-0123456789abcdef',
        'OWA_AUTH_KEY'   => 'owatest-auth-key-0123456789abcdef',
        'OWA_AUTH_SALT'  => 'owatest-auth-salt-0123456789abcdef',
        'OWA_PUBLIC_URL' => 'https://owa.example.test/',
    ];

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
        yield 'full'        => ['full',        ['tracking', 'ui', 'rest', 'cli', 'install'], true,  true];
        yield 'collector'   => ['collector',   ['tracking', 'cli'],                          false, false];
        yield 'worker'      => ['worker',      ['cli'],                                      true,  true];
        yield 'console'     => ['console',     ['ui', 'cli'],                                false, true];
        yield 'api'         => ['api',         ['rest', 'cli'],                              false, true];
        yield 'console,api' => ['console,api', ['ui', 'rest', 'cli'],                        false, true];
        yield 'blank'       => ['',            ['tracking', 'ui', 'rest', 'cli', 'install'], true,  true];
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
        $this->assertSame(['full'], (new ServerRole('console, FULL'))->roles());
        $this->assertSame(['console', 'api'], (new ServerRole(' Console ,api,console'))->roles());
        $this->assertSame('console,api', (new ServerRole('console,api'))->name());
    }

    public function testUnknownNamesAreKeptForTheRefusal(): void
    {
        $role = new ServerRole('colector,console');

        $this->assertSame(['colector'], $role->unknown());
        $this->assertSame(['console'], $role->roles());
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

        $this->assertSame(['full', 'worker'], $default);
        $this->assertTrue((new ServerRole('full'))->mayRun(['console']));
        $this->assertTrue((new ServerRole('worker'))->mayRun($default));
        $this->assertFalse((new ServerRole('console'))->mayRun($default));
        $this->assertFalse((new ServerRole('collector'))->mayRun($default));
        $this->assertTrue((new ServerRole('console,api'))->mayRun(['api']));
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function testARoleWithADatabaseNeedsItsConstants(): void
    {
        foreach (['full', 'worker', 'console', 'api'] as $value) {
            $this->assertSame([], (new ServerRole($value))->configProblems(self::DATABASE, []), $value);
        }

        $defined = self::DATABASE;
        unset($defined['OWA_DB_HOST'], $defined['OWA_AUTH_KEY']);

        $this->assertSame(['Role worker needs OWA_DB_HOST, OWA_AUTH_KEY in owa-config.php.'],
            (new ServerRole('worker'))->configProblems($defined, []));
    }

    /** OWA_PUBLIC_URL, not empty, on every role with a database; a collector builds no links. */
    public function testARoleWithADatabaseNeedsThePublicUrl(): void
    {
        foreach ([[], ['OWA_PUBLIC_URL' => '']] as $variant) {

            $defined = array_merge(self::DATABASE, $variant);

            if (!$variant) {
                unset($defined['OWA_PUBLIC_URL']);
            }

            foreach (['full', 'worker', 'console', 'api'] as $value) {
                $this->assertSame(["Role $value needs OWA_PUBLIC_URL in owa-config.php."],
                    (new ServerRole($value))->configProblems($defined, []), $value);
            }
        }

        $this->assertSame([], (new ServerRole('collector'))->configProblems(
            ['OWA_PUBLIC_URL' => ''], ['sqs' => 'OWA_SQS_QUEUE_URL']));
    }

    public function testACollectorNeedsAQueueBackendAndNoDatabase(): void
    {
        $collector = new ServerRole('collector');

        $this->assertSame([], $collector->configProblems([], ['sqs' => 'OWA_SQS_QUEUE_URL']));
        $this->assertSame(['Role collector needs a queue backend to send beacons to: set OWA_SQS_QUEUE_URL.'],
            $collector->configProblems([], [], ['OWA_SQS_QUEUE_URL']));
    }

    public function testTwoQueueBackendsAreRefusedOnEveryRole(): void
    {
        $two = ['sqs' => 'OWA_SQS_QUEUE_URL', 'zzq' => 'OWA_ZZQ_URL'];

        foreach (['full', 'collector', 'worker'] as $value) {
            $this->assertContains('Two queue backends are configured (OWA_SQS_QUEUE_URL, OWA_ZZQ_URL): set one.',
                (new ServerRole($value))->configProblems(self::DATABASE, $two), $value);
        }
    }

    public function testBootRefusesARequestTheRoleDoesNotServeWith404(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');

        $problem = ServerRole::bootProblem('ui', [], true, false);

        $this->assertSame(404, $problem['status']);
        $this->assertSame('This server\'s role is collector, which serves tracking, cli, not ui requests.', $problem['message']);
    }

    public function testBootChecksTheConfigurationOnlyOnceThereIsOne(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'worker');

        // Installing stated, not read: OWA_INSTALLING is process-global, and
        // InstallCli defines it for the rest of a suite run.
        $this->assertNull(ServerRole::bootProblem('cli', [], false, false), 'before install: nothing to check');
        $this->assertNull(ServerRole::bootProblem('cli', [], true, true), 'the installer writes the configuration');
        $this->assertSame(503, ServerRole::bootProblem('cli', [], true, false)['status']);
        $this->assertNull(ServerRole::bootProblem('cli', self::DATABASE, true, false));
    }

    /** @return iterable<string, array{mixed}> */
    public static function weakSecrets(): iterable
    {
        yield 'empty'             => [''];
        yield 'short'             => ['0123456789abcdef0123456789abcde'];
        yield 'the example value' => ['yourauthkeygoeshere'];
        yield 'not a string'      => [['an array']];
    }

    /**
     * A secret that is empty, short or the example value refuses every
     * request but the command line, naming the constant.
     *
     * @dataProvider weakSecrets
     */
    public function testBootRefusesAWeakSecret($value): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'full');

        $defined = ['OWA_AUTH_KEY' => $value] + self::DATABASE;

        $this->assertSame(['OWA_AUTH_KEY'], ServerRole::weakSecrets($defined));

        foreach ([RequestType::UI, RequestType::REST, RequestType::TRACKING] as $type) {
            $problem = ServerRole::bootProblem($type, $defined, true, false);

            $this->assertSame(503, $problem['status'] ?? null, $type);
            $this->assertStringStartsWith('OWA_AUTH_KEY in owa-config.php must be a random string of at least 32 characters',
                $problem['message'], $type);
        }

        $this->assertNull(ServerRole::bootProblem(RequestType::CLI, $defined, true, false), 'cmd=reset-secrets can run');
    }

    /** Every command but reset-secrets warns of a weak secret, so a scheduler's log says it. */
    public function testACommandWarnsOfAWeakSecret(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'worker');

        $weak = ['OWA_NONCE_SALT' => 'yournoncesaltgoeshere'] + self::DATABASE;

        $warning = ServerRole::secretsWarning('schedule-run', $weak);

        $this->assertStringStartsWith('Warning: OWA_NONCE_SALT in owa-config.php must be a random string', (string) $warning);
        $this->assertNull(ServerRole::secretsWarning('reset-secrets', $weak), 'the command that fixes it');
        $this->assertNull(ServerRole::secretsWarning('schedule-run', self::DATABASE));

        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');
        $this->assertNull(ServerRole::secretsWarning('schedule-run', $weak), 'a collector signs nothing');
    }

    public function testCliWritesTheWarningBeforeTheCommand(): void
    {
        $cli = (string) file_get_contents(__DIR__ . '/../cli.php');

        $warn = strpos($cli, 'ServerRole::secretsWarning($params[\'cmd\'])');
        $this->assertNotFalse($warn);
        $this->assertLessThan(strpos($cli, '$owa->handleRequest($params)'), $warn);

        // The quiet command is the one that writes new secrets.
        \OWA\Core\CoreAPI::serviceSingleton()->loadCliCommands();
        $this->assertSame('base.resetSecretsCli', \OWA\Core\CoreAPI::getCliCommandClass(ServerRole::RESET_SECRETS_COMMAND));
    }

    public function testEachExampleSecretIsWeak(): void
    {
        $dist = (string) file_get_contents(__DIR__ . '/../owa-config-dist.php');

        foreach (\OWA\Core\Requirements::SECRET_CONSTANTS as $constant) {
            $this->assertSame(1, preg_match("/define\\('$constant', '([^']*)'\\)/", $dist, $m), $constant);
            $this->assertSame([$constant], ServerRole::weakSecrets([$constant => $m[1]]), $constant);
        }

        $this->assertSame([], ServerRole::weakSecrets(self::DATABASE));
        $this->assertNull(ServerRole::bootProblem(RequestType::UI, self::DATABASE, true, false));
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
        $notices = ServerRole::upgradeNotices(['OWA_QUEUE_EVENTS' => true, 'OWA_SCHEDULER_ENABLED' => false,
            'OWA_USE_STATIC_CONFIG_ONLY' => true, 'OWA_DB_HOST' => 'db']);

        $this->assertSame(['OWA_QUEUE_EVENTS', 'OWA_USE_STATIC_CONFIG_ONLY', 'OWA_SCHEDULER_ENABLED'], array_keys($notices));
        $this->assertStringContainsString('OWA_QUEUE_TRACKING_EVENTS', $notices['OWA_QUEUE_EVENTS']);
        $this->assertStringContainsString('OWA_SERVER_ROLE', $notices['OWA_USE_STATIC_CONFIG_ONLY']);
    }

    /** 1.x's debug constants are not read; OWA_DEBUG replaces both. */
    public function testTheOneXDebugConstantsGetAnUpgradeNotice(): void
    {
        $notices = ServerRole::upgradeNotices(['OWA_ERROR_HANDLER' => 'development', 'OWA_LOG_PHP_ERRORS' => true]);

        $this->assertSame(['OWA_ERROR_HANDLER', 'OWA_LOG_PHP_ERRORS'], array_keys($notices));
        $this->assertStringContainsString('OWA_DEBUG', $notices['OWA_ERROR_HANDLER']);
        $this->assertStringContainsString('OWA_DEBUG', $notices['OWA_LOG_PHP_ERRORS']);
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
            $this->assertSame([], array_diff($roles, ServerRole::ALL), $command);
            $this->assertContains('full', $roles, $command);
        }
    }

    /** @return iterable<string, array{string, string[]}> */
    public static function marks(): iterable
    {
        yield 'server-check'         => ['server-check',         ServerRole::ALL];
        yield 'instance-info'        => ['instance-info',        ServerRole::ALL];
        yield 'drain-tracker-ingest' => ['drain-tracker-ingest', ['full', 'worker', 'collector']];
        yield 'change-password'      => ['change-password',      ['full', 'worker', 'console']];
        yield 'app-password-create'  => ['app-password-create',  ['full', 'worker', 'console']];
        yield 'add-site'             => ['add-site',             ['full', 'worker', 'console']];
        yield 'schedule-status'      => ['schedule-status',      ['full', 'worker', 'console']];
        yield 'schedule-run'         => ['schedule-run',         ['full', 'worker']];
        yield 'core-update-schema'   => ['core-update-schema',   ['full', 'worker']];
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
        $this->assertSame(['activated_by' => ['OWA_SQS_QUEUE_URL'], 'intake_queue_type' => 'sqs'],
            ModuleActivation::declarations()['sqs'] ?? null);
        $this->assertSame(['OWA_SQS_QUEUE_URL'], ModuleActivation::queueBackendConstants());
    }

    public function testAModuleIsActivatedByASetConstantOnly(): void
    {
        $decl = [
            'sqs'   => ['activated_by' => ['OWA_SQS_QUEUE_URL'], 'intake_queue_type' => 'sqs'],
            'cache' => ['activated_by' => ['OWA_ZZ_CACHE_SERVERS', 'OWA_ZZ_CACHE_HOST'], 'intake_queue_type' => ''],
        ];

        $this->assertSame([], ModuleActivation::activeByConstant([], $decl));
        $this->assertSame([], ModuleActivation::activeByConstant(['OWA_SQS_QUEUE_URL' => ''], $decl), 'empty is not set');
        $this->assertSame(['cache' => 'OWA_ZZ_CACHE_HOST'],
            ModuleActivation::activeByConstant(['OWA_ZZ_CACHE_HOST' => 'h'], $decl), 'any of its constants');
        $this->assertSame(['sqs' => 'OWA_SQS_QUEUE_URL'],
            ModuleActivation::queueBackends(['OWA_SQS_QUEUE_URL' => 'https://q', 'OWA_ZZ_CACHE_HOST' => 'h'], $decl),
            'only a module with an intake queue type is a backend');
    }

    public function testTwoQueueModulesConfiguredAreTwoBackends(): void
    {
        $decl = [
            'sqs' => ['activated_by' => ['OWA_SQS_QUEUE_URL'], 'intake_queue_type' => 'sqs'],
            'zzq' => ['activated_by' => ['OWA_ZZQ_URL'], 'intake_queue_type' => 'zzq'],
        ];

        $backends = ModuleActivation::queueBackends(['OWA_SQS_QUEUE_URL' => 'a', 'OWA_ZZQ_URL' => 'b'], $decl);

        $this->assertSame(['sqs' => 'OWA_SQS_QUEUE_URL', 'zzq' => 'OWA_ZZQ_URL'], $backends);
        $this->assertNotSame([], (new ServerRole('full'))->configProblems(self::DATABASE, $backends));
    }

    public function testTheModulesScreenCannotTurnOffAModuleItsConstantActivates(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();
        $c->noteConfigConstant('test_fixture', 'is_active', 'OWA_ZZ_FIXTURE');

        try {
            $this->assertSame('OWA_ZZ_FIXTURE', \OWA\Core\Module::activatedByConstant('test_fixture'));
            $this->assertFalse(\OWA\Core\CoreAPI::moduleClassFactory('test_fixture')->deactivate());
        } finally {
            $c->forgetConfigConstant('test_fixture', 'is_active');
        }

        $this->assertSame('', \OWA\Core\Module::activatedByConstant('test_fixture'));
    }

    // ---------------------------------------------------------------------
    // Region and database type
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{string, ?string}> */
    public static function queueUrls(): iterable
    {
        yield 'standard' => ['https://sqs.us-east-1.amazonaws.com/123456789012/q', 'us-east-1'];
        yield 'China'    => ['https://sqs.cn-north-1.amazonaws.com.cn/123456789012/q', 'cn-north-1'];
        yield 'legacy'   => ['https://us-west-2.queue.amazonaws.com/123456789012/q', 'us-west-2'];
        yield 'FIPS'     => ['https://sqs-fips.us-gov-west-1.amazonaws.com/123456789012/q', 'us-gov-west-1'];
        yield 'VPC'      => ['https://vpce-0ab12.sqs.eu-west-2.vpce.amazonaws.com/123456789012/q', 'eu-west-2'];
        yield 'not AWS'  => ['https://sqs.us-east-1.example.com/123456789012/q', null];
        yield 'empty'    => ['', null];
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
