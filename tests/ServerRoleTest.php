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

    /** OWA_SECRET, which every role needs. */
    private const SECRET = ['OWA_SECRET' => 'owatest-private-files-0123456789abcdef'];

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
        yield 'full'          => ['full',          ['tracking', 'ui', 'rest', 'cli', 'install'], true,  true];
        yield 'collector'     => ['collector',     ['tracking', 'cli'],                          false, false];
        yield 'api'           => ['api',           ['rest', 'cli'],                              false, true];
        yield 'collector,api' => ['collector,api', ['tracking', 'rest', 'cli'],                  false, true];
        yield 'blank'         => ['',              ['tracking', 'ui', 'rest', 'cli', 'install'], true,  true];
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
        $this->assertSame(['full'], (new ServerRole('api, FULL'))->roles());
        $this->assertSame(['collector', 'api'], (new ServerRole(' Collector ,api,collector'))->roles());
        $this->assertSame('collector,api', (new ServerRole('collector,api'))->name());
    }

    public function testTheRolesAreFullCollectorAndApi(): void
    {
        $this->assertSame(['full', 'collector', 'api'], ServerRole::ALL);
        $this->assertSame(['full'], ServerRole::SCHEDULER);
        $this->assertSame(['full', 'collector', 'api'], array_keys(ServerRole::SERVES));
        $this->assertFalse(defined(ServerRole::class . '::WORKER'));
        $this->assertFalse(defined(ServerRole::class . '::CONSOLE'));
    }

    public function testUnknownNamesAreKeptForTheRefusal(): void
    {
        $role = new ServerRole('colector,api');

        $this->assertSame(['colector'], $role->unknown());
        $this->assertSame([], $role->removed());
        $this->assertSame(['api'], $role->roles());
    }

    public function testRemovedRolesAreKeptApartFromUnknownNames(): void
    {
        $role = new ServerRole('Worker,console,colector,api');

        $this->assertSame(['worker', 'console'], $role->removed());
        $this->assertSame(['colector'], $role->unknown());
        $this->assertSame(['api'], $role->roles());
        $this->assertSame(['full'], (new ServerRole('worker'))->roles(), 'nothing left: full, and boot refuses it');
    }

    public function testOnlyACollectorQueuesWhateverTheSetting(): void
    {
        $this->assertTrue((new ServerRole('collector'))->queuesTrackingEvents());
        $this->assertFalse((new ServerRole('full'))->queuesTrackingEvents());
        $this->assertTrue((new ServerRole('collector,api'))->hasDatabase(), 'combined with a database role');
    }

    public function testFullRunsEveryCommandAndOtherRolesOnlyTheirs(): void
    {
        $default = \OWA\Core\Controller\Cli::serverRoles();

        $this->assertSame(['full'], $default);
        $this->assertTrue((new ServerRole('full'))->mayRun(['api']));
        $this->assertFalse((new ServerRole('api'))->mayRun($default));
        $this->assertFalse((new ServerRole('collector'))->mayRun($default));
        $this->assertTrue((new ServerRole('collector,api'))->mayRun(['api']));
        $this->assertSame(['full'], \OWA\Module\Base\Controller\CoreInstallCli::serverRoles(), 'a release installs from a full server only');
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function testARoleWithADatabaseNeedsItsConstants(): void
    {
        foreach (['full', 'api'] as $value) {
            $this->assertSame([], (new ServerRole($value))->configProblems(self::DATABASE, []), $value);
        }

        $defined = self::DATABASE;
        unset($defined['OWA_DB_HOST'], $defined['OWA_AUTH_KEY']);

        $this->assertSame(['Role api needs OWA_DB_HOST, OWA_AUTH_KEY in owa-config.php.'],
            (new ServerRole('api'))->configProblems($defined, []));
    }

    /** OWA_PUBLIC_URL, not empty, on every role with a database; a collector builds no links. */
    public function testARoleWithADatabaseNeedsThePublicUrl(): void
    {
        foreach ([[], ['OWA_PUBLIC_URL' => '']] as $variant) {

            $defined = array_merge(self::DATABASE, $variant);

            if (!$variant) {
                unset($defined['OWA_PUBLIC_URL']);
            }

            foreach (['full', 'api'] as $value) {
                $this->assertSame(["Role $value needs OWA_PUBLIC_URL in owa-config.php."],
                    (new ServerRole($value))->configProblems($defined, []), $value);
            }
        }

        $this->assertSame([], (new ServerRole('collector'))->configProblems(
            ['OWA_PUBLIC_URL' => ''] + self::SECRET, ['sqs' => 'OWA_SQS_QUEUE_URL']));
    }

    public function testACollectorNeedsAQueueBackendAndNoDatabase(): void
    {
        $collector = new ServerRole('collector');

        $this->assertSame([], $collector->configProblems(self::SECRET, ['sqs' => 'OWA_SQS_QUEUE_URL']));
        $this->assertSame(['Role collector needs a queue backend to send beacons to: set OWA_SQS_QUEUE_URL.'],
            $collector->configProblems(self::SECRET, [], ['OWA_SQS_QUEUE_URL']));
    }

    private const MISSING_SECRET = 'OWA_SECRET is not defined in owa-config.php. Add define(\'OWA_SECRET\', \'RANDOM\'); with a random string of at least 32 characters in place of RANDOM. This prints one: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"';

    /** A role without a database has no schema version to read: it needs OWA_SECRET always, for every request. */
    public function testACollectorNeedsOwaSecret(): void
    {
        $this->assertSame([self::MISSING_SECRET],
            (new ServerRole('collector'))->configProblems([], ['sqs' => 'OWA_SQS_QUEUE_URL']));

        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');
        $queue = ['OWA_SQS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-test'];

        foreach ([RequestType::TRACKING, RequestType::CLI] as $type) {
            $problem = ServerRole::bootProblem($type, $queue, true, false);

            $this->assertSame(503, $problem['status'] ?? null, $type);
            $this->assertSame(self::MISSING_SECRET, $problem['message'], $type);
            $this->assertNull(ServerRole::bootProblem($type, $queue + self::SECRET, true, false), $type);
        }

        $this->assertNull(ServerRole::settingsProblem(106, $queue), 'boot has asked already');
    }

    /**
     * A role with a database: only once Base's schema is at Update106, which
     * writes OWA_SECRET. Before it, the pending update stops every request
     * but the update commands.
     */
    public function testARoleWithADatabaseNeedsOwaSecretOnlyFromUpdate106(): void
    {
        $this->assertSame(106, ServerRole::SECRET_SCHEMA_VERSION);

        $defined = self::DATABASE + ['OWA_SQS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-test'];

        foreach (['full', 'api', 'collector,api'] as $value) {
            \OWA\Core\CoreAPI::setSetting('base', 'server_role', $value);

            $this->assertSame([], (new ServerRole($value))->configProblems($defined, ['sqs' => 'OWA_SQS_QUEUE_URL']), $value . ': not at boot');
            $this->assertNull(ServerRole::bootProblem(RequestType::CLI, $defined, true, false), $value);

            $this->assertNull(ServerRole::settingsProblem(105, $defined), $value . ': before Update106');
            $this->assertNull(ServerRole::settingsProblem(null, $defined), $value . ': no schema recorded');
            $this->assertSame(['status' => 503, 'message' => self::MISSING_SECRET],
                ServerRole::settingsProblem(106, $defined), $value);
            $this->assertSame(['status' => 503, 'message' => self::MISSING_SECRET],
                ServerRole::settingsProblem('107', $defined), $value);
            $this->assertNull(ServerRole::settingsProblem(106, $defined + self::SECRET), $value);
        }
    }

    /** The placeholder, or a value too short, is refused as the other secrets are, on every role. */
    public function testAWeakOwaSecretIsRefusedOnEveryRole(): void
    {
        $queue = ['OWA_SQS_QUEUE_URL' => 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-test'];

        foreach (['yoursecretgoeshere', 'short', ''] as $weak) {
            foreach (['full' => [RequestType::UI, self::DATABASE], 'collector' => [RequestType::TRACKING, $queue]] as $role => [$type, $defined]) {
                \OWA\Core\CoreAPI::setSetting('base', 'server_role', $role);
                $defined['OWA_SECRET'] = $weak;

                $problem = ServerRole::bootProblem($type, $defined, true, false);

                $this->assertSame(503, $problem['status'] ?? null, "$role / $weak");
                $this->assertSame('OWA_SECRET in owa-config.php must be a random string of at least 32 characters, not empty and not the example value. This prints one: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"',
                    $problem['message'], "$role / $weak");
                $this->assertNull(ServerRole::bootProblem(RequestType::CLI, $defined, true, false), 'the command line warns');
                $this->assertStringStartsWith('Warning: OWA_SECRET in owa-config.php', (string) ServerRole::secretsWarning('schedule-run', $defined));
                $this->assertSame([], ServerRole::weakSecrets($defined), 'not one reset-secrets writes');
            }
        }
    }

    public function testTwoQueueBackendsAreRefusedOnEveryRole(): void
    {
        $two = ['sqs' => 'OWA_SQS_QUEUE_URL', 'zzq' => 'OWA_ZZQ_URL'];

        foreach (['full', 'collector', 'api'] as $value) {
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
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'api');

        // Installing stated, not read: OWA_INSTALLING is process-global, and
        // the web installer's controllers define it for the rest of a suite run.
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
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'full');

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

    /** The boot refuses a missing OWA_SECRET once the stored settings, and with them the schema version, are read. */
    public function testBootAsksAboutOwaSecretOnceTheStoredSettingsAreRead(): void
    {
        $caller = (string) file_get_contents(__DIR__ . '/../Core/Caller.php');

        $load  = strpos($caller, "\$this->c->load( \$this->c->get( 'base', 'configuration_id' ) );");
        $asked = strpos($caller, "ServerRole::settingsProblem( \$this->c->get( 'base', 'schema_version' ) )");

        $this->assertNotFalse($load);
        $this->assertNotFalse($asked);
        $this->assertGreaterThan($load, $asked);
        $this->assertLessThan(strpos($caller, '$this->overloadConfig( $config );'), $asked);
    }

    public function testEachExampleSecretIsWeak(): void
    {
        $dist = (string) file_get_contents(__DIR__ . '/../owa-config-dist.php');

        foreach (\OWA\Core\Requirements::SECRET_CONSTANTS as $constant) {
            $this->assertSame(1, preg_match("/define\\('$constant', '([^']*)'\\)/", $dist, $m), $constant);
            $this->assertSame([$constant], ServerRole::weakSecrets([$constant => $m[1]]), $constant);
        }

        $this->assertSame(1, preg_match("/define\\('OWA_SECRET', '([^']*)'\\)/", $dist, $m));
        $this->assertTrue(ServerRole::isWeakSecret($m[1]), 'OWA_SECRET\'s');

        $this->assertSame([], ServerRole::weakSecrets(self::DATABASE));
        $this->assertNull(ServerRole::bootProblem(RequestType::UI, self::DATABASE, true, false));
        $this->assertNull(ServerRole::bootProblem(RequestType::UI, self::DATABASE + self::SECRET, true, false));
    }

    public function testBootRefusesAnUnknownRoleForEveryRequest(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'nonsense');

        foreach (RequestType::ALL as $type) {
            $this->assertSame(503, ServerRole::bootProblem($type, self::DATABASE, true, false)['status'], $type);
        }

        $this->assertSame('OWA_SERVER_ROLE names nonsense, which is not a role. The roles are full, collector, api.',
            ServerRole::bootProblem(RequestType::UI, self::DATABASE, true, false, [])['message']);
    }

    /** worker and console are refused with their own message, naming the roles there are. */
    public function testBootRefusesARemovedRoleForEveryRequest(): void
    {
        foreach (['worker', 'console', 'console,api', 'worker,nonsense'] as $value) {

            \OWA\Core\CoreAPI::setSetting('base', 'server_role', $value);

            foreach (RequestType::ALL as $type) {
                $problem = ServerRole::bootProblem($type, self::DATABASE, true, false, []);

                $this->assertSame(503, $problem['status'], "$value / $type");
                $this->assertStringContainsString('which OWA no longer has. The roles are full, collector, api;',
                    $problem['message'], "$value / $type");
            }
        }

        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'worker,console');

        $this->assertSame('OWA_SERVER_ROLE names worker, console, which OWA no longer has. The roles are full, collector, api; '
            . 'the scheduler and the admin and report screens run on the full server.',
            ServerRole::bootProblem(RequestType::CLI, self::DATABASE, true, false, [])['message']);
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

    /** OWA_NS is not read: the prefix is CoreAPI::NS. */
    public function testTheNsConstantGetsAnUpgradeNotice(): void
    {
        $notices = ServerRole::upgradeNotices(['OWA_NS' => 'zz_']);

        $this->assertSame(['OWA_NS'], array_keys($notices));
        $this->assertStringContainsString('No longer read', $notices['OWA_NS']);
        $this->assertFalse(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', 'ns'));
    }

    /** OWA_LOG_VISITOR_PII is not read: the identity fields are stored as sent. */
    public function testTheVisitorPiiConstantGetsAnUpgradeNotice(): void
    {
        $notices = ServerRole::upgradeNotices(['OWA_LOG_VISITOR_PII' => false]);

        $this->assertSame(['OWA_LOG_VISITOR_PII'], array_keys($notices));
        $this->assertStringContainsString('No longer read', $notices['OWA_LOG_VISITOR_PII']);
        $this->assertFalse(\OWA\Core\CoreAPI::configSingleton()->isRegistered('base', 'log_visitor_pii'));
    }

    /** The boot itself, with OWA_USE_STATIC_CONFIG_ONLY defined, is StaticConfigBootTest's. */
    public function testStoredSettingsAreReadOnEveryRoleWithADatabase(): void
    {
        foreach (['full' => true, 'api' => true, 'collector' => false, 'collector,api' => true, '' => true] as $role => $reads) {
            \OWA\Core\CoreAPI::setSetting('base', 'server_role', $role);
            $this->assertSame($reads, ServerRole::readsStoredSettings(), $role);
        }
    }

    public function testTheStaticSwitchNoticeSaysItAppliesOnlyToCollectors(): void
    {
        $notice = ServerRole::upgradeNotices(['OWA_USE_STATIC_CONFIG_ONLY' => true])['OWA_USE_STATIC_CONFIG_ONLY'];

        $this->assertStringContainsString('applies only to collectors', $notice);
        $this->assertStringContainsString('on a role with a database it is ignored', $notice);
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
        yield 'drain-tracker-ingest' => ['drain-tracker-ingest', ['full', 'collector']];
        yield 'change-password'      => ['change-password',      ['full']];
        yield 'app-password-create'  => ['app-password-create',  ['full']];
        yield 'add-site'             => ['add-site',             ['full']];
        yield 'activate'             => ['activate',             ['full']];
        yield 'schedule-status'      => ['schedule-status',      ['full']];
        yield 'schedule-run'         => ['schedule-run',         ['full']];
        yield 'core-update-schema'   => ['core-update-schema',   ['full']];
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

        $this->assertSame('cmd=schedule-run does not run on this server: its role is collector, and the command runs on full.',
            (new ServerRole('collector'))->commandProblem('schedule-run', $action));
        $this->assertNull((new ServerRole('full'))->commandProblem('schedule-run', $action));
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
