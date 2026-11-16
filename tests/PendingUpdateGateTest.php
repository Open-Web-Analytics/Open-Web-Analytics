<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Which pending update stops an admin action.
 *
 * An update pending only because the tracker build changed stops the admin
 * screens, which show the update notice, and nothing else: the CLI (the
 * scheduler, the queue drain) and the REST API run on. A schema behind the
 * code stops all of them.
 */
final class PendingUpdateGateTest extends TestCase
{
    private $flag;
    private $schema;
    private $mode;

    protected function setUp(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->flag   = $service->update_required;
        $this->schema = $c->get('base', 'schema_version');
        $this->mode   = \OWA\Core\CoreAPI::getSetting('base', 'request_type');

        // Every module's schema current: only the tracker build is pending.
        foreach ($service->modules as $name => $module) {
            if (!$module->isSchemaCurrent()) {
                $this->markTestSkipped(sprintf('%s has a schema update pending in this install', $name));
            }
        }

        $service->update_required = true;
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->update_required = $this->flag;
        \OWA\Core\CoreAPI::configSingleton()->set('base', 'schema_version', $this->schema);
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->mode);
    }

    private function controller(): \OWA\Core\Controller
    {
        return new class([]) extends \OWA\Core\Controller {

            public $is_admin = true;

            public $ran = false;

            public function stops(): bool
            {
                return $this->pendingUpdateStopsAction();
            }

            protected function checkCapabilityAndAuthenticateUser($capability)
            {
                return true;
            }

            function action()
            {
                $this->ran = true;
            }
        };
    }

    public function testATrackerBuildUpdateStopsOnlyTheScreens(): void
    {
        $c = $this->controller();

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'ui');
        $this->assertTrue($c->stops(), 'an admin screen shows the update notice');

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
        $this->assertFalse($c->stops(), 'the CLI runs');

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'rest');
        $this->assertFalse($c->stops(), 'the REST API runs');
    }

    public function testASchemaUpdateStopsEverything(): void
    {
        $c = $this->controller();

        \OWA\Core\CoreAPI::configSingleton()->set('base', 'schema_version', 1);

        foreach (['ui', 'cli', 'rest'] as $mode) {
            \OWA\Core\CoreAPI::setSetting('base', 'request_type', $mode);
            $this->assertTrue($c->stops(), $mode);
        }
    }

    /** A CLI command reaches its action with only the tracker build pending. */
    public function testACliCommandRuns(): void
    {
        $c = $this->controller();

        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
        $c->doAction();

        $this->assertTrue($c->ran);
    }
}
