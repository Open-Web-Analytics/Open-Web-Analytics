<?php

require_once(__DIR__ . '/CliControllerTestCase.php');
require_once(__DIR__ . '/fixtures/TestFixtureModule.php');

/**
 * Contract + behavior tests for the documented OWA CLI commands
 * (registered in modules/base/module.php:537-552; wiki: "Command Line
 * Interface (CLI)"). Each command is invoked as
 *   php cli.php cmd=<name> arg=val ...
 * and dispatched to a controller. These tests resolve the command name
 * through the same service registry cli.php uses, then drive the controller's
 * doAction() lifecycle directly.
 *
 * Two commands are handled in separate phases and intentionally NOT exercised
 * here:
 *   - install  : re-runs schema install + admin creation; belongs to a
 *                dedicated install-flow test phase.
 *   - update   : applies/rolls back DB schema packages globally; to be
 *                designed separately. (Its read-only 'listpending' arg is
 *                safe and IS covered below.)
 *
 * Everything else is either safe to fully round-trip (add-site, change-password,
 * the module activate/deactivate/install commands against the
 * schema-less test fixture module, and the crawl/queue maintenance commands
 * which no-op on an empty working set) or is asserted at the contract level
 * (command is registered + capability gate + argument validation).
 */
final class CliCommandsTest extends CliControllerTestCase
{
    /** The module the lifecycle commands are driven against. */
    private const MODULE = \OWA\Module\TestFixture\Module::NAME;

    // =================================================================
    // Registry: every documented command resolves to a controller class.
    // This is the wiki-to-code completeness check -- if a command is
    // dropped from registerCliCommands(), its row here reddens.
    // =================================================================

    /**
     * @dataProvider commandRegistryProvider
     */
    public function testCommandIsRegistered(string $cmd, string $expectedClass): void
    {
        $this->assertSame($expectedClass, $this->commandClass($cmd),
            "CLI command '{$cmd}' should resolve to {$expectedClass} through the service registry.");
    }

    /**
     * 1.x's names for the renamed commands are free, so cli.php can say what
     * they are now; each new name is one a module registers, active here or
     * not (geoip-update-db is MaxmindGeoip's).
     */
    public function testTheRenamedCommandsPointAtRegisteredOnes(): void
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->loadCliCommands();

        $sources = '';

        foreach (array_merge(glob(\OWA\Core\Env::root() . '/modules/*/config/cli_commands.php'), glob(\OWA\Core\Env::root() . '/modules/*/Module.php')) as $file) {
            $sources .= file_get_contents($file);
        }

        foreach (\OWA\Core\Controller\Cli::RENAMED as $old => $new) {
            $this->assertEmpty($s->getCliCommandClass($old), "$old is still a command");
            $this->assertMatchesRegularExpression('/[\'"]' . preg_quote($new, '/') . '[\'"]\s*(=>|,)/', $sources, "no module registers $new");
            $this->assertDoesNotMatchRegularExpression('/[\'"]' . preg_quote($old, '/') . '[\'"]\s*(=>|,)/', $sources, "a module still registers $old");
        }
    }

    /**
     * cmd name => module.class string it must map to (module.php:537-552).
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function commandRegistryProvider(): array
    {
        return [
            'core-update-schema'         => ['core-update-schema',         'base.coreUpdateSchemaCli'],
            'drain-tracker-ingest'       => ['drain-tracker-ingest',       'base.drainTrackerIngestCli'],
            'tracker-ingest-replay'      => ['tracker-ingest-replay',      'base.trackerIngestReplayCli'],
            'tracker-ingest-provision'   => ['tracker-ingest-provision',   'base.trackerIngestProvisionCli'],
            'core-install'               => ['core-install',               'base.coreInstallCli'],
            'activate'                   => ['activate',                   'base.moduleActivateCli'],
            'deactivate'                 => ['deactivate',                 'base.moduleDeactivateCli'],
            'add-site'                   => ['add-site',                   'base.sitesAddCli'],
            'prune-event-queue-archives' => ['prune-event-queue-archives', 'base.pruneEventQueueArchivesCli'],
            'change-password'            => ['change-password',            'base.changeUserPasswordCli'],
            'reset-secrets'              => ['reset-secrets',              'base.resetSecretsCli'],
            'v1-drop'                    => ['v1-drop',                    'base.v1DropCli'],
        ];
    }

    // =================================================================
    // add-site  (owa_sitesAddCliController, cap: edit_sites)
    // =================================================================

    public function testAddSiteCreatesSite(): void
    {
        $domain = 'owatest-cli-add-' . $this->tok . '.example.com';

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\SitesAddCli::class,
            'sitesAddCli.php',
            ['domain' => 'https://' . $domain, 'name' => 'CLI add ' . $this->tok]
        );

        $this->assertNull($result['view'], 'add-site prints through notice(), not a view.');
        $this->assertNotEmpty($result['data']['site']['site_id'] ?? null, 'add-site should report the new Profile.');

        // Verify the row landed, stored without the scheme it was typed with,
        // then schedule it for cleanup.
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($domain, 'domain');
        $this->assertNotEmpty($site->get('id'),
            'add-site should have persisted a site row keyed by domain.');
        $this->trackForCleanup('base.site', $site->get('id'), 'id');

        /*
         * AND THE PROPERTY IT MINTED.
         *
         * Adding a site creates the Property above it -- a site is an
         * Observation Profile now, and a Profile has to hang off something.
         * This cleanup predates the hierarchy and only ever removed the
         * Profile, so every run of this test left one parentless Property
         * behind. The test install had accumulated 2,295 of them against 165
         * sites, and each one is a row in the fan-out's Properties column:
         * a picker nobody could use, from a defect in a test rather than in
         * the product.
         */
        $propertyId = $site->get('property_id');

        $this->assertNotEmpty($propertyId,
            'add-site should have given the new Profile a Property to hang off.');

        $this->trackForCleanup('base.property', $propertyId, 'id');
    }

    public function testAddSiteRequiresDomain(): void
    {
        $result = $this->runCommand(
            \OWA\Module\Base\Controller\SitesAddCli::class,
            'sitesAddCli.php',
            ['name' => 'no domain ' . $this->tok]
        );

        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_USAGE, \OWA\Core\Controller\Cli::$exit_code,
            'add-site without a domain should fail validation.');
        $this->assertArrayHasKey('validation_errors', $result['data']);
    }

    public function testAddSiteRejectsAStreamTypeItDoesNotKnow(): void
    {
        $domain = 'owatest-cli-streamtype-' . $this->tok . '.example.com';

        $this->runCommand(
            \OWA\Module\Base\Controller\SitesAddCli::class,
            'sitesAddCli.php',
            ['domain' => 'https://' . $domain, 'name' => 'CLI stream type ' . $this->tok, 'streamType' => 'App']
        );

        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_USAGE, \OWA\Core\Controller\Cli::$exit_code,
            'add-site takes streamType=web or streamType=app.');

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load($domain, 'domain');
        $this->assertEmpty($site->get('id'), 'nothing was created');
    }

    public function testAddSiteRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\SitesAddCli::class,
            'sitesAddCli.php',
            ['domain' => 'https://owatest-cli-denied-' . $this->tok . '.example.com']
        );

        $this->assertNotCapable($result, 'add-site making a Property requires create_properties.');

        // And nothing was written: a domain is stored without its scheme.
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load('owatest-cli-denied-' . $this->tok . '.example.com', 'domain');
        $this->assertEmpty($site->get('id'),
            'A denied add-site must not create a site row.');
    }

    // =================================================================
    // change-password  (owa_changeUserPasswordCliController, cap: edit_settings)
    // =================================================================

    public function testChangePasswordUpdatesTheStoredHash(): void
    {
        $user = $this->makeUser('viewer', 'pw', 'oldpass' . $this->tok);

        $before = \OWA\Core\CoreAPI::entityFactory('base.user');
        $before->load($user['user_id'], 'user_id');
        $oldHash = $before->get('password');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ChangeUserPasswordCli::class,
            'changeUserPasswordCli.php',
            ['user' => $user['user_id'], 'password' => 'newpass' . $this->tok]
        );

        $this->assertNull($result['view'],
            'A successful change-password runs the action with no error view.');

        $after = \OWA\Core\CoreAPI::entityFactory('base.user');
        $after->load($user['user_id'], 'user_id');
        $this->assertNotSame($oldHash, $after->get('password'),
            'change-password should have rotated the stored password hash.');
        $this->assertNotEmpty($after->get('password'));
    }

    public function testChangePasswordRequiresUserAndPassword(): void
    {
        // Omit the user; validation must fail and route to the error view.
        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ChangeUserPasswordCli::class,
            'changeUserPasswordCli.php',
            ['password' => 'newpass' . $this->tok]
        );

        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_USAGE, \OWA\Core\Controller\Cli::$exit_code,
            'change-password without a user should fail validation.');
    }

    public function testChangePasswordRejectsShortPassword(): void
    {
        // The length rule (>= 6 chars) is a 'stringLength' validation. It was
        // mistyped as 'required', which ignored the length config and let any
        // non-empty password through; assert a 5-char password is now rejected.
        $user = $this->makeUser('viewer', 'pwshort', 'oldpass' . $this->tok);

        $before = \OWA\Core\CoreAPI::entityFactory('base.user');
        $before->load($user['user_id'], 'user_id');
        $oldHash = $before->get('password');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ChangeUserPasswordCli::class,
            'changeUserPasswordCli.php',
            ['user' => $user['user_id'], 'password' => 'short']
        );

        $this->assertSame(\OWA\Core\Controller\Cli::EXIT_USAGE, \OWA\Core\Controller\Cli::$exit_code,
            'A password shorter than 6 characters should fail validation.');

        $after = \OWA\Core\CoreAPI::entityFactory('base.user');
        $after->load($user['user_id'], 'user_id');
        $this->assertSame($oldHash, $after->get('password'),
            'A rejected short password must not rotate the stored hash.');
    }

    public function testChangePasswordRejectsUnprivilegedUser(): void
    {
        $target = $this->makeUser('viewer', 'pwtarget', 'oldpass' . $this->tok);
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ChangeUserPasswordCli::class,
            'changeUserPasswordCli.php',
            ['user' => $target['user_id'], 'password' => 'newpass' . $this->tok]
        );

        $this->assertNotCapable($result, 'change-password requires edit_settings.');
    }

    // =================================================================
    // Module lifecycle: activate / deactivate
    // (cap: edit_modules) -- exercised against the schema-less test fixture
    // module (tests/fixtures/TestFixtureModule.php) so there are no tables to
    // create/drop. Each test deletes the fixture's stored rows afterward.
    // =================================================================

    public function testActivateModuleActivatesTheModule(): void
    {
        $restore = $this->forgetFixtureAfter();
        try {
            // Start from deactivated so activation is observable.
            \OWA\Core\CoreAPI::deactivateModule(self::MODULE);

            $result = $this->runCommand(
                \OWA\Module\Base\Controller\ModuleActivateCli::class,
                'moduleActivateCli.php',
                ['module' => self::MODULE]
            );

            $this->assertNull($result['view'], 'activate should run cleanly.');
            $this->assertTrue((bool) \OWA\Core\CoreAPI::getSetting(self::MODULE, 'is_active'),
                "activate should set the module's is_active to true.");
        } finally {
            $restore();
        }
    }

    public function testDeactivateModuleDeactivatesTheModule(): void
    {
        $restore = $this->forgetFixtureAfter();
        try {
            \OWA\Core\CoreAPI::installModule(self::MODULE);

            $result = $this->runCommand(
                \OWA\Module\Base\Controller\ModuleDeactivateCli::class,
                'moduleDeactivateCli.php',
                ['module' => self::MODULE]
            );

            $this->assertNull($result['view'], 'deactivate should run cleanly.');
            $this->assertFalse((bool) \OWA\Core\CoreAPI::getSetting(self::MODULE, 'is_active'),
                "deactivate should set the module's is_active to false.");
        } finally {
            $restore();
        }
    }

    public function testDeactivateModuleWorksForNotBootLoadedModule(): void
    {
        // deactivateModule() loads a fresh instance via moduleClassFactory(), like
        // activate/install do: service::getModule() returns only boot-loaded (active)
        // modules, so an inactive module would be false.
        // The fixture, made inactive first: a harmless no-op to drive.
        $module  = self::MODULE;
        $restore = $this->forgetFixtureAfter();
        \OWA\Core\CoreAPI::deactivateModule($module);

        try {
            $result = $this->runCommand(
                \OWA\Module\Base\Controller\ModuleDeactivateCli::class,
                'moduleDeactivateCli.php',
                ['module' => $module]
            );
            $active = (bool) \OWA\Core\CoreAPI::getSetting($module, 'is_active');
        } finally {
            $restore();
        }

        $this->assertNull($result['view'],
            'Deactivating a not-boot-loaded module should run cleanly, not fatal.');
        $this->assertFalse($active, "{$module} should remain inactive after a no-op deactivate.");
    }

    public function testActivateModuleRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ModuleActivateCli::class,
            'moduleActivateCli.php',
            ['module' => self::MODULE]
        );

        $this->assertNotCapable($result, 'activate requires edit_modules.');
    }

    public function testDeactivateModuleRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ModuleDeactivateCli::class,
            'moduleDeactivateCli.php',
            ['module' => self::MODULE]
        );

        $this->assertNotCapable($result, 'deactivate requires edit_modules.');
    }

    public function testPruneEventQueueArchivesRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            \OWA\Module\Base\Controller\PruneEventQueueArchivesCli::class,
            'pruneEventQueueArchivesCli.php',
            []
        );

        $this->assertNotCapable($result, 'prune-event-queue-archives requires edit_modules.');
    }

    /** Against a scratch intake: the install's own archive is not this test's to prune. */
    public function testPruneEventQueueArchivesRunsForAdmin(): void
    {
        $dir = sys_get_temp_dir() . '/owa-prune-' . bin2hex(random_bytes(4)) . '/';
        $q   = new \OWA\Module\Base\Classes\FileEventQueue(['path' => $dir]);
        $q->provision();
        file_put_contents($dir . 'archive/old.txt', "x\n");
        touch($dir . 'archive/old.txt', time() - 2 * 86400);
        \OWA\Module\Base\Classes\TrackerIngest::$queue = $q;

        try {
            $result = $this->runCommand(
                \OWA\Module\Base\Controller\PruneEventQueueArchivesCli::class,
                'pruneEventQueueArchivesCli.php',
                ['queues' => 'tracker-ingest']
            );

            $this->assertNull($result['view'], 'prune-event-queue-archives should run cleanly for an admin.');
            $this->assertFileDoesNotExist($dir . 'archive/old.txt');
        } finally {
            \OWA\Module\Base\Classes\TrackerIngest::$queue = null;
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Run the scheduled prune against a scratch intake holding archives of two
     * ages in the main queue and an old one in its dead-letter queue, with
     * raw_retention_months as given.
     *
     * @return string the scratch directory, for the caller to inspect and remove
     */
    private function scheduledPrune($months, array $params = []): string
    {
        $dir = sys_get_temp_dir() . '/owa-prune-' . bin2hex(random_bytes(4)) . '/';
        $q   = new \OWA\Module\Base\Classes\FileEventQueue(['path' => $dir]);
        $q->provision();

        foreach (['archive/old.txt' => 730, 'archive/recent.txt' => 7, 'dead-letter/archive/old.txt' => 730] as $f => $days) {
            file_put_contents($dir . $f, "x\n");
            touch($dir . $f, time() - $days * 86400);
        }

        $before = \OWA\Core\CoreAPI::getSetting('base', 'raw_retention_months');
        \OWA\Core\CoreAPI::setSetting('base', 'raw_retention_months', $months);
        \OWA\Module\Base\Classes\TrackerIngest::$queue = $q;

        try {
            $result = $this->runCommand(
                \OWA\Module\Base\Controller\PruneEventQueueArchivesCli::class,
                'pruneEventQueueArchivesCli.php',
                ['queues' => 'tracker-ingest', 'scheduled' => 1] + $params
            );

            $this->assertNull($result['view']);
        } finally {
            \OWA\Module\Base\Classes\TrackerIngest::$queue = null;
            \OWA\Core\CoreAPI::setSetting('base', 'raw_retention_months', $before);
        }

        return $dir;
    }

    /** Scheduled, with raw_retention_months unset: every archive stays, whatever interval= says. */
    public function testTheScheduledPruneKeepsEverythingWithoutARetentionWindow(): void
    {
        $dir = $this->scheduledPrune(null, ['interval' => 60]);

        try {
            $this->assertFileExists($dir . 'archive/old.txt');
            $this->assertFileExists($dir . 'archive/recent.txt');
            $this->assertFileExists($dir . 'dead-letter/archive/old.txt');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** Scheduled, with a 12-month window: archives older than it go, in both queues; newer ones stay. */
    public function testTheScheduledPruneDeletesArchivesOlderThanTheRetentionWindow(): void
    {
        $dir = $this->scheduledPrune(12);

        try {
            $this->assertFileDoesNotExist($dir . 'archive/old.txt');
            $this->assertFileDoesNotExist($dir . 'dead-letter/archive/old.txt');
            $this->assertFileExists($dir . 'archive/recent.txt');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    // =================================================================
    // reset-secrets  (owa_resetSecretsCliController, cap: edit_settings)
    // Only the capability gate here: running it rewrites owa-config.php.
    // ConfigSecretsTest runs it on scratch config files.
    // =================================================================

    public function testResetSecretsRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        // Lives in modules/base/controllers/ (unlike the other CLI controllers).
        $result = $this->runCommand(
            \OWA\Module\Base\Controller\ResetSecretsCli::class,
            'controllers/resetSecretsCli.php',
            []
        );

        $this->assertNotCapable($result, 'reset-secrets requires edit_settings.');
    }

    // =================================================================
    // update  (owa_updatesApplyCliController) -- only the read-only
    // 'listpending' path is safe to run here; applying/rolling back schema
    // is deferred to a separate phase.
    // =================================================================

    public function testUpdateListPendingRunsReadOnly(): void
    {
        $result = $this->runCommand(
            \OWA\Module\Base\Controller\CoreUpdateSchemaCli::class,
            'updatesApplyCli.php',
            ['listpending' => true]
        );

        // listpending returns its own data payload and never mutates schema;
        // the contract is that it runs without routing to an error view.
        $this->assertNotSame('base.error', $result['view'],
            'update listpending should be a read-only listing, not a capability error.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * A restorer that deletes the fixture module's stored rows, so
     * module-lifecycle tests never leak state.
     *
     * @return callable():void
     */
    private function forgetFixtureAfter(): callable
    {
        return static function (): void {
            \OWA\Module\TestFixture\Module::forget();
        };
    }
}
