<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A collector has no database: no schema version to gate commands on, no
 * scoped settings, no site table. drain-tracker-ingest runs there with
 * forward_only=1 only, moving beacons from the file queue to the intake.
 */
final class LoggingNodeForwardTest extends TestCase
{
    private $savedRole;

    private $savedType;

    protected function setUp(): void
    {
        $this->savedRole = \OWA\Core\CoreAPI::getSetting('base', 'server_role');
        $this->savedType = \OWA\Core\CoreAPI::getSetting('base', 'request_type');

        // A CLI controller built outside request type cli exits the process.
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', 'cli');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', $this->savedRole);
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', $this->savedType);
    }

    private function drain(array $params): \OWA\Module\Base\Controller\DrainTrackerIngestCli
    {
        return new \OWA\Module\Base\Controller\DrainTrackerIngestCli($params);
    }

    public function testACollectorSkipsTheUpdateGate(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');

        $this->assertFalse($this->drain(['forward_only' => 1])->needsCurrentSchema());
        $this->assertFalse((new \OWA\Module\Base\Controller\InstanceInfoCli([]))->needsCurrentSchema());
    }

    public function testTheFullDrainOnACollectorIsRefused(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');

        $drain = $this->drain([]);
        $drain->action();

        $this->assertSame('refused', $drain->getCliOutcome()['outcome']);
        $this->assertStringContainsString('forward_only=1', $drain->getCliOutcome()['message']);
    }

    public function testEveryRoleWithADatabaseIsGated(): void
    {
        foreach (array('full', 'worker', 'console', 'api', 'collector,worker') as $role) {
            \OWA\Core\CoreAPI::setSetting('base', 'server_role', $role);

            $this->assertTrue($this->drain(['forward_only' => 1])->needsCurrentSchema(), $role);
        }
    }

    public function testACollectorAnswersEveryScopeWithTheInstallValue(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');

        $this->assertSame(
            \OWA\Core\CoreAPI::getSetting('base', 'excluded_ips'),
            \OWA\Core\CoreAPI::getSetting('base', 'excluded_ips', 'profile', 'logging-node-probe-site')
        );
    }

    public function testACollectorLeavesTheSiteCheckToIngest(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'server_role', 'collector');

        $this->assertNull(\OWA\Core\CoreAPI::siteRegistration('logging-node-probe-site'));
        $this->assertFalse(\OWA\Core\CoreAPI::siteRegistration(''), 'an empty site id is still refused');
    }
}
