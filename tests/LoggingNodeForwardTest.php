<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A static-config logging node has no database to read the schema version
 * from, so the pending-updates gate would refuse every command there. Only
 * drain-tracker-ingest forward_only=1 runs past it, and only on such a node:
 * it moves beacons from the file queue to the intake and reads nothing else.
 */
final class LoggingNodeForwardTest extends TestCase
{
    private $saved;

    private $savedMode;

    protected function setUp(): void
    {
        $this->saved     = \OWA\Core\CoreAPI::getSetting('base', 'useStaticConfigOnly');
        $this->savedMode = \OWA\Core\CoreAPI::getSetting('base', 'request_mode');

        // A CLI controller built outside request_mode cli exits the process.
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', 'cli');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', $this->saved);
        \OWA\Core\CoreAPI::setSetting('base', 'request_mode', $this->savedMode);
    }

    private function drain(array $params): \OWA\Module\Base\Controller\DrainTrackerIngestCli
    {
        return new \OWA\Module\Base\Controller\DrainTrackerIngestCli($params);
    }

    public function testForwardOnlyOnAStaticNodeSkipsTheUpdateGate(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', true);

        $this->assertFalse($this->drain(['forward_only' => 1])->needsCurrentSchema());
    }

    public function testTheFullDrainOnAStaticNodeIsStillGated(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', true);

        $this->assertTrue($this->drain([])->needsCurrentSchema());
    }

    public function testForwardOnlyWithADatabaseIsStillGated(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', false);

        $this->assertTrue($this->drain(['forward_only' => 1])->needsCurrentSchema());
    }

    public function testEveryOtherControllerNeedsACurrentSchema(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', true);

        $this->assertTrue((new \OWA\Module\Base\Controller\PruneEventQueueArchivesCli(['forward_only' => 1]))->needsCurrentSchema());
    }

    public function testAStaticNodeAnswersEveryScopeWithTheInstallValue(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', true);

        $this->assertSame(
            \OWA\Core\CoreAPI::getSetting('base', 'excluded_ips'),
            \OWA\Core\CoreAPI::getSetting('base', 'excluded_ips', 'profile', 'logging-node-probe-site')
        );
    }

    public function testAStaticNodeLeavesTheSiteCheckToIngest(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'useStaticConfigOnly', true);

        $this->assertNull(\OWA\Core\CoreAPI::siteRegistration('logging-node-probe-site'));
        $this->assertFalse(\OWA\Core\CoreAPI::siteRegistration(''), 'an empty site id is still refused');
    }
}
