<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Migration\FactMigrator;
use OWA\Module\Base\Classes\TrackingEventHelpers;

/**
 * Values a real 1.x install stored that v2's columns refuse under strict
 * mode. Each failed a whole migration batch in the rehearsal on a copy of
 * one: a proxy chain as the IP, a microtime as the language.
 */
final class MigratorValueCleanupTest extends TestCase
{
    public function testAProxyChainResolvesToItsFirstPublicAddress(): void
    {
        $this->assertSame('193.50.73.146',
            FactMigrator::ipAddress('172.31.84.204, ::1, 10.130.73.2, 127.0.0.1, 193.50.73.146'));
        $this->assertSame('2405:9800:bc11:7408:6172:55e:2bd1:e403',
            FactMigrator::ipAddress('2405:9800:bc11:7408:6172:55e:2bd1:e403, 64.233.173.131'));
        $this->assertNull(FactMigrator::ipAddress('10.0.0.1, 192.168.1.1'), 'a chain of private addresses has none');
    }

    public function testASingleAddressIsKeptAsStored(): void
    {
        $this->assertSame('203.0.113.10', FactMigrator::ipAddress('203.0.113.10'));
        $this->assertSame('10.0.0.1', FactMigrator::ipAddress(' 10.0.0.1 '), '1.x already chose it');
        $this->assertNull(FactMigrator::ipAddress(''));
        $this->assertNull(FactMigrator::ipAddress(null));
    }

    public function testALanguageIsKeptWhenItFits(): void
    {
        $this->assertSame('en-US', FactMigrator::language('en-US'));
        $this->assertSame('de,en', FactMigrator::language('de,en'), 'what ingest itself stores from Accept-Language');
        $this->assertNull(FactMigrator::language('0.20504800 1616979699'));
        $this->assertNull(FactMigrator::language(''));
    }

    /** Live ingest and the migration choose an address the same way. */
    public function testChooseIpIsIngestsRule(): void
    {
        $this->assertSame('198.51.100.7', TrackingEventHelpers::chooseIp('10.1.1.1, 198.51.100.7, 203.0.113.9'));
        $this->assertSame('198.51.100.7', TrackingEventHelpers::chooseIp('198.51.100.7'));
        $this->assertSame('', TrackingEventHelpers::chooseIp('127.0.0.1'));
        $this->assertSame('', TrackingEventHelpers::chooseIp(''));
    }
}
