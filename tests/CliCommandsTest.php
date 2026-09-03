<?php

require_once(__DIR__ . '/CliControllerTestCase.php');

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
 * flush-cache, the module activate/deactivate/install commands against the
 * schema-less 'hello' sample module, and the crawl/queue maintenance commands
 * which no-op on an empty working set) or is asserted at the contract level
 * (command is registered + capability gate + argument validation).
 */
final class CliCommandsTest extends CliControllerTestCase
{
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
     * cmd name => module.class string it must map to (module.php:537-552).
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function commandRegistryProvider(): array
    {
        return [
            'update'                     => ['update',                     'base.updatesApplyCli'],
            'flush-cache'                => ['flush-cache',                'base.flushCacheCli'],
            'processEventQueue'          => ['processEventQueue',          'base.processEventQueue'],
            'install'                    => ['install',                    'base.installCli'],
            'activate'                   => ['activate',                   'base.moduleActivateCli'],
            'deactivate'                 => ['deactivate',                 'base.moduleDeactivateCli'],
            'install-module'             => ['install-module',             'base.moduleInstallCli'],
            'add-site'                   => ['add-site',                   'base.sitesAddCli'],
            'flush-processed-events'     => ['flush-processed-events',     'base.flushProcessedEventsCli'],
            'prune-event-queue-archives' => ['prune-event-queue-archives', 'base.pruneEventQueueArchivesCli'],
            'change-password'            => ['change-password',            'base.changeUserPasswordCli'],
            'update-document'            => ['update-document',            'base.crawlDocumentCli'],
            'reset-secrets'              => ['reset-secrets',              'base.resetSecretsCli'],
        ];
    }

    // =================================================================
    // add-site  (owa_sitesAddCliController, cap: edit_sites)
    // =================================================================

    public function testAddSiteCreatesSite(): void
    {
        $domain = 'https://owatest-cli-add-' . $this->tok . '.example.com';

        $result = $this->runCommand(
            'owa_sitesAddCliController',
            'sitesAddCli.php',
            ['domain' => $domain, 'name' => 'CLI add ' . $this->tok]
        );

        $this->assertSame('base.sitesAddCli', $result['view'],
            'A valid add-site should route to the sitesAddCli success view.');

        // Verify the row landed, then schedule it for cleanup.
        $site = owa_coreAPI::entityFactory('base.site');
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
            'owa_sitesAddCliController',
            'sitesAddCli.php',
            ['name' => 'no domain ' . $this->tok]
        );

        // sitesAddCli overrides errorAction() to the base.cli view.
        $this->assertSame('base.cli', $result['view'],
            'add-site without a domain should fail validation and route to the cli error view.');
        $this->assertArrayHasKey('validation_errors', $result['data']);
    }

    public function testAddSiteRejectsDomainWithoutProtocol(): void
    {
        // The 'http' substring-position validation requires a protocol prefix.
        $result = $this->runCommand(
            'owa_sitesAddCliController',
            'sitesAddCli.php',
            ['domain' => 'owatest-cli-noproto-' . $this->tok . '.example.com']
        );

        $this->assertSame('base.cli', $result['view'],
            'add-site should reject a domain missing the http(s):// protocol.');
    }

    public function testAddSiteRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_sitesAddCliController',
            'sitesAddCli.php',
            ['domain' => 'https://owatest-cli-denied-' . $this->tok . '.example.com']
        );

        $this->assertNotCapable($result, 'add-site requires edit_sites.');

        // And nothing was written.
        $site = owa_coreAPI::entityFactory('base.site');
        $site->load('https://owatest-cli-denied-' . $this->tok . '.example.com', 'domain');
        $this->assertEmpty($site->get('id'),
            'A denied add-site must not create a site row.');
    }

    // =================================================================
    // change-password  (owa_changeUserPasswordCliController, cap: edit_settings)
    // =================================================================

    public function testChangePasswordUpdatesTheStoredHash(): void
    {
        $user = $this->makeUser('viewer', 'pw', 'oldpass' . $this->tok);

        $before = owa_coreAPI::entityFactory('base.user');
        $before->load($user['user_id'], 'user_id');
        $oldHash = $before->get('password');

        $result = $this->runCommand(
            'owa_changeUserPasswordCliController',
            'changeUserPasswordCli.php',
            ['user' => $user['user_id'], 'password' => 'newpass' . $this->tok]
        );

        $this->assertNull($result['view'],
            'A successful change-password runs the action with no error view.');

        $after = owa_coreAPI::entityFactory('base.user');
        $after->load($user['user_id'], 'user_id');
        $this->assertNotSame($oldHash, $after->get('password'),
            'change-password should have rotated the stored password hash.');
        $this->assertNotEmpty($after->get('password'));
    }

    public function testChangePasswordRequiresUserAndPassword(): void
    {
        // Omit the user; validation must fail and route to the error view.
        $result = $this->runCommand(
            'owa_changeUserPasswordCliController',
            'changeUserPasswordCli.php',
            ['password' => 'newpass' . $this->tok]
        );

        $this->assertSame('base.changeUserPasswordCli', $result['view'],
            'change-password without a user should fail validation.');
    }

    public function testChangePasswordRejectsShortPassword(): void
    {
        // The length rule (>= 6 chars) is a 'stringLength' validation. It was
        // mistyped as 'required', which ignored the length config and let any
        // non-empty password through; assert a 5-char password is now rejected.
        $user = $this->makeUser('viewer', 'pwshort', 'oldpass' . $this->tok);

        $before = owa_coreAPI::entityFactory('base.user');
        $before->load($user['user_id'], 'user_id');
        $oldHash = $before->get('password');

        $result = $this->runCommand(
            'owa_changeUserPasswordCliController',
            'changeUserPasswordCli.php',
            ['user' => $user['user_id'], 'password' => 'short']
        );

        $this->assertSame('base.changeUserPasswordCli', $result['view'],
            'A password shorter than 6 characters should fail validation.');

        $after = owa_coreAPI::entityFactory('base.user');
        $after->load($user['user_id'], 'user_id');
        $this->assertSame($oldHash, $after->get('password'),
            'A rejected short password must not rotate the stored hash.');
    }

    public function testChangePasswordRejectsUnprivilegedUser(): void
    {
        $target = $this->makeUser('viewer', 'pwtarget', 'oldpass' . $this->tok);
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_changeUserPasswordCliController',
            'changeUserPasswordCli.php',
            ['user' => $target['user_id'], 'password' => 'newpass' . $this->tok]
        );

        $this->assertNotCapable($result, 'change-password requires edit_settings.');
    }

    // =================================================================
    // flush-cache  (owa_flushCacheCliController, no capability -- maintenance)
    // =================================================================

    public function testFlushCacheRuns(): void
    {
        // flush-cache takes no args and has no side effect worth asserting on a
        // fresh cache; the contract is simply that it runs without error and
        // does not route to an error/redirect view.
        $result = $this->runCommand(
            'owa_flushCacheCliController',
            'flushCacheCli.php',
            []
        );

        $this->assertNull($result['view'],
            'flush-cache should run cleanly with no error view.');
    }

    // =================================================================
    // Module lifecycle: activate / deactivate / install-module
    // (cap: edit_modules) -- exercised against the schema-less 'hello'
    // sample module so there are no tables to create/drop. Each test
    // snapshots hello's is_active and restores it afterward.
    // =================================================================

    public function testActivateModuleActivatesHello(): void
    {
        $restore = $this->snapshotHelloActive();
        try {
            // Start from deactivated so activation is observable.
            owa_coreAPI::deactivateModule('hello');

            $result = $this->runCommand(
                'owa_moduleActivateCliController',
                'moduleActivateCli.php',
                ['module' => 'hello']
            );

            $this->assertNull($result['view'], 'activate should run cleanly.');
            $this->assertTrue((bool) owa_coreAPI::getSetting('hello', 'is_active'),
                "activate module=hello should set hello's is_active to true.");
        } finally {
            $restore();
        }
    }

    public function testDeactivateModuleDeactivatesHello(): void
    {
        $restore = $this->snapshotHelloActive();
        try {
            owa_coreAPI::activateModule('hello');

            $result = $this->runCommand(
                'owa_moduleDeactivateCliController',
                'moduleDeactivateCli.php',
                ['module' => 'hello']
            );

            $this->assertNull($result['view'], 'deactivate should run cleanly.');
            $this->assertFalse((bool) owa_coreAPI::getSetting('hello', 'is_active'),
                "deactivate module=hello should set hello's is_active to false.");
        } finally {
            $restore();
        }
    }

    public function testDeactivateModuleWorksForNotBootLoadedModule(): void
    {
        // Regression: deactivateModule() resolved the module through
        // service::getModule(), which only returns boot-loaded (active)
        // modules and returned false otherwise -- so deactivating an already
        // inactive module fataled with deactivate()-on-bool. It now loads a
        // fresh instance via moduleClassFactory() like activate/install do.
        // 'fileCache' ships inactive, so this is a harmless no-op to drive.
        $module = 'fileCache';
        if ((bool) owa_coreAPI::getSetting($module, 'is_active')) {
            $this->markTestSkipped("Expected {$module} to be inactive for this regression.");
        }

        $result = $this->runCommand(
            'owa_moduleDeactivateCliController',
            'moduleDeactivateCli.php',
            ['module' => $module]
        );

        $this->assertNull($result['view'],
            'Deactivating a not-boot-loaded module should run cleanly, not fatal.');
        $this->assertFalse((bool) owa_coreAPI::getSetting($module, 'is_active'),
            "{$module} should remain inactive after a no-op deactivate.");
    }

    public function testInstallModuleActivatesHello(): void
    {
        $restore = $this->snapshotHelloActive();
        try {
            owa_coreAPI::deactivateModule('hello');

            // hello has no entities, so install-module just persists the schema
            // version and activates -- no tables are created.
            $result = $this->runCommand(
                'owa_moduleInstallCliController',
                'moduleInstallCli.php',
                ['module' => 'hello']
            );

            $this->assertNull($result['view'], 'install-module should run cleanly.');
            $this->assertTrue((bool) owa_coreAPI::getSetting('hello', 'is_active'),
                'install-module module=hello should leave hello active.');
        } finally {
            $restore();
        }
    }

    public function testActivateModuleRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_moduleActivateCliController',
            'moduleActivateCli.php',
            ['module' => 'hello']
        );

        $this->assertNotCapable($result, 'activate requires edit_modules.');
    }

    public function testDeactivateModuleRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_moduleDeactivateCliController',
            'moduleDeactivateCli.php',
            ['module' => 'hello']
        );

        $this->assertNotCapable($result, 'deactivate requires edit_modules.');
    }

    public function testInstallModuleRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_moduleInstallCliController',
            'moduleInstallCli.php',
            ['module' => 'hello']
        );

        $this->assertNotCapable($result, 'install-module requires edit_modules.');
    }

    // =================================================================
    // Crawl maintenance: update-document (cap: edit_settings).
    // Contract-only: with no id this crawls EVERY stored document over the
    // network (crawlDocument() does a live HTTP fetch), and with a
    // non-existent id it loads a blank row and fatals. Neither is safe to run
    // in a test, so we assert only the capability gate, which runs before any
    // crawling.
    //
    // update-referral was the same shape and is gone: OWA no longer fetches
    // referring pages at all. See RefererCrawlRemovedTest.
    // =================================================================

    public function testUpdateDocumentRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_crawlDocumentCliController',
            'crawlDocumentCli.php',
            ['doc' => '0']
        );

        $this->assertNotCapable($result, 'update-document requires edit_settings.');
    }


    // =================================================================
    // Event-queue maintenance: processEventQueue / flush-processed-events /
    // prune-event-queue-archives (cap: edit_modules).
    // =================================================================

    public function testFlushProcessedEventsRunsForAdmin(): void
    {
        // Regression guard: this command used to fatal on every invocation
        // (owa_eventDispatch::getAsyncEventQueue() does not exist). It now
        // resolves the 'processing' database queue, connects, and deletes
        // handled rows. On an empty queue that is a clean no-op.
        $result = $this->runCommand(
            'owa_flushProcessedEventsCliController',
            'flushProcessedEventsCli.php',
            []
        );

        $this->assertNull($result['view'],
            'flush-processed-events should run cleanly for an admin.');
    }

    public function testFlushProcessedEventsRejectsUnprivilegedUser(): void
    {
        // Regression guard: this command previously set NO required capability,
        // so any authenticated user could run it. It now requires edit_modules.
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_flushProcessedEventsCliController',
            'flushProcessedEventsCli.php',
            []
        );

        $this->assertNotCapable($result, 'flush-processed-events requires edit_modules.');
    }

    public function testPruneEventQueueArchivesRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_pruneEventQueueArchivesCliController',
            'pruneEventQueueArchivesCli.php',
            []
        );

        $this->assertNotCapable($result, 'prune-event-queue-archives requires edit_modules.');
    }

    public function testProcessEventQueueRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        $result = $this->runCommand(
            'owa_processEventQueueController',
            'processEventQueue.php',
            []
        );

        $this->assertNotCapable($result, 'processEventQueue requires edit_modules.');
    }

    public function testProcessEventQueueRunsForAdmin(): void
    {
        // Target the registered 'processing' queue explicitly. Draining an
        // empty queue is a clean no-op -- the contract is that the command
        // connects and returns without error, not that it processes events.
        $result = $this->runCommand(
            'owa_processEventQueueController',
            'processEventQueue.php',
            ['queues' => 'processing']
        );

        $this->assertNull($result['view'],
            'processEventQueue should drain the queue and run cleanly for an admin.');
    }

    public function testPruneEventQueueArchivesRunsForAdmin(): void
    {
        // pruneArchive() is a no-op stub for the database queue, so this is
        // safe to run for real; assert the command connects and completes.
        $result = $this->runCommand(
            'owa_pruneEventQueueArchivesCliController',
            'pruneEventQueueArchivesCli.php',
            ['queues' => 'processing']
        );

        $this->assertNull($result['view'],
            'prune-event-queue-archives should run cleanly for an admin.');
    }

    // =================================================================
    // reset-secrets  (owa_resetSecretsCliController, cap: edit_settings)
    // Contract-only: actually running it rewrites owa-config.php (rotating
    // OWA_AUTH_KEY etc.), which would break this live install. We assert the
    // capability gate, which runs before any file mutation.
    // =================================================================

    public function testResetSecretsRejectsUnprivilegedUser(): void
    {
        $this->authenticateAs('viewer');

        // Lives in modules/base/controllers/ (unlike the other CLI controllers).
        $result = $this->runCommand(
            'owa_resetSecretsCliController',
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
            'owa_updatesApplyCliController',
            'updatesApplyCli.php',
            ['listpending' => '']
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
     * Snapshot the hello module's is_active flag and return a restorer that
     * persists it back, so module-lifecycle tests never leak state.
     *
     * @return callable():void
     */
    private function snapshotHelloActive(): callable
    {
        $wasActive = (bool) owa_coreAPI::getSetting('hello', 'is_active');

        return function () use ($wasActive): void {
            if ($wasActive) {
                owa_coreAPI::activateModule('hello');
            } else {
                owa_coreAPI::deactivateModule('hello');
            }
        };
    }
}
