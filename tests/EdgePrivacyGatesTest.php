<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\FileEventQueue;
use OWA\Module\Base\Classes\TrackerIngest;
use OWA\Module\Base\Classes\TrackingEventHelpers;

/**
 * What the edge (CoreAPI::logEvent()) refuses or removes before a beacon is
 * queued: the identity fields log_visitor_pii turns off, and an excluded IP
 * matched against the full address while anonymize_ips is on.
 *
 * Queued mode against a scratch intake, so what the intake holds is read
 * back directly. The site is answered for without a database.
 */
final class EdgePrivacyGatesTest extends TestCase
{
    private const SITE = 'edge-privacy-gates-site';

    private string $dir;

    /** @var array<string,mixed> */
    private array $saved = array();

    /** @var mixed the request container's server params before this test */
    private $savedServer;

    protected function setUp(): void
    {
        \OWA\Core\CoreAPI::rememberSiteRegistration(self::SITE, true);

        // A browser request from a documentation-range address, whatever the
        // runner's own environment holds (none, under CLI in CI).
        $request = \OWA\Core\CoreAPI::requestContainerSingleton();
        $this->savedServer = $request->server;
        $request->server = array(
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'REMOTE_ADDR'     => '203.0.113.77',
        );

        foreach (array('queue_tracking_events', 'log_visitor_pii', 'anonymize_ips', 'excluded_ips', 'log_named_users') as $key) {
            $this->saved[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        }

        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);
        \OWA\Core\CoreAPI::setSetting('base', 'log_named_users', true);
        \OWA\Core\CoreAPI::setSetting('base', 'log_visitor_pii', true);
        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', false);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', '');

        $this->dir = sys_get_temp_dir() . '/owa-edge-gates-' . bin2hex(random_bytes(4)) . '/';
        TrackerIngest::$queue = new FileEventQueue(array('path' => $this->dir));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            \OWA\Core\CoreAPI::setSetting('base', $key, $value);
        }

        TrackerIngest::$queue = null;
        \OWA\Core\CoreAPI::forgetRegisteredSites();
        \OWA\Core\CoreAPI::requestContainerSingleton()->server = $this->savedServer;

        gc_collect_cycles();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** Log a page view and return the envelopes the intake now holds. */
    private function logPageView(array $properties = array()): array
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType('page_view');
        $event->setProperties($properties + array(
            'site_id'       => self::SITE,
            'page_location' => 'https://edge.test/a',
        ));

        \OWA\Core\CoreAPI::logEvent('page_view', $event);

        $lines = is_file($this->dir . 'events.txt') ? file($this->dir . 'events.txt', FILE_IGNORE_NEW_LINES) : array();

        return array_map(fn ($line) => json_decode($line, true)['e'], array_values(array_filter($lines)));
    }

    private const IDENTITY = array(
        'user_id'        => 'person-42',
        'vps_user_name'  => 'Display Name',
        'vps_user_email' => 'person@example.test',
    );

    // ---------------------------------------------------------------------
    // log_visitor_pii
    // ---------------------------------------------------------------------

    public function testWithPiiOnTheIdentityFieldsAreQueued(): void
    {
        $queued = $this->logPageView(self::IDENTITY);

        $this->assertCount(1, $queued);

        foreach (self::IDENTITY as $name => $value) {
            $this->assertSame($value, $queued[0]['properties'][$name] ?? null, $name);
        }
    }

    /** Off: the intake never holds them, so neither can a retry or a dead letter. */
    public function testWithPiiOffNoIdentityFieldReachesTheIntake(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'log_visitor_pii', false);

        $queued = $this->logPageView(self::IDENTITY);

        $this->assertCount(1, $queued, 'the event itself is still recorded');
        $this->assertSame('https://edge.test/a', $queued[0]['properties']['page_location']);

        foreach (array_keys(self::IDENTITY) as $name) {
            $this->assertArrayNotHasKey($name, $queued[0]['properties'], $name);
        }
    }

    /** A 1.x tracker's user_name and email_address are renamed onto the user properties, and gated as them. */
    public function testWithPiiOffA1xTrackersNamesAreGatedToo(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'log_visitor_pii', false);

        $queued = $this->logPageView(array('user_name' => 'Old Name', 'email_address' => 'old@example.test'));

        $this->assertCount(1, $queued);
        $this->assertStringNotContainsString('Old Name', json_encode($queued[0]));
        $this->assertStringNotContainsString('old@example.test', json_encode($queued[0]));
    }

    /** The property stage asks again, for an event queued before the setting was turned off. */
    public function testThePropertyStageGatesAQueuedEventToo(): void
    {
        $queued = $this->logPageView(self::IDENTITY);

        \OWA\Core\CoreAPI::setSetting('base', 'log_visitor_pii', false);

        $event = TrackerIngest::event($queued[0]);
        TrackingEventHelpers::gateVisitorPii($event);

        foreach (array_keys(self::IDENTITY) as $name) {
            $this->assertArrayNotHasKey($name, $event->getProperties(), $name);
        }

        $source = file_get_contents(dirname(__DIR__) . '/modules/Base/Controller/ProcessEvent.php');
        $this->assertStringContainsString('TrackingEventHelpers::gateVisitorPii( $this->event )', $source);
    }

    /** The 1.x migration maps user_name to user_id, and the setting decides it there too. */
    public function testTheMigrationHonoursPii(): void
    {
        $migrator = new class extends \OWA\Module\Base\Classes\Migration\FactMigrator {
            protected function events(array $r, array $refs)
            {
                return array($this->baseEvent($r, $refs, 'page_view'));
            }

            public function one(array $r)
            {
                return $this->events($r, array());
            }
        };

        $row = array('id' => 1, 'site_id' => self::SITE, 'visitor_id' => '11', 'session_id' => '12',
                     'timestamp' => 1700000000, 'yyyymmdd' => 20231114, 'user_name' => 'person-7');

        $this->assertSame('person-7', $migrator->one($row)[0]->get('user_id'));

        \OWA\Core\CoreAPI::setSetting('base', 'log_visitor_pii', false);

        $this->assertArrayNotHasKey('user_id', $migrator->one($row)[0]->getProperties());
    }

    // ---------------------------------------------------------------------
    // excluded_ips with anonymize_ips on
    // ---------------------------------------------------------------------

    private function requestIpOrSkip(): string
    {
        $ip = TrackingEventHelpers::requestIp();

        if ($ip === '' || \OWA\Core\Lib::anonymizeIp($ip) === $ip) {
            $this->markTestSkipped('the request has no address that anonymising changes');
        }

        return $ip;
    }

    /** The full address in the list excludes the request, though only the masked one would be stored. */
    public function testAnExcludedAddressMatchesWithAnonymisationOn(): void
    {
        $ip = $this->requestIpOrSkip();

        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', true);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', '198.51.100.1, ' . $ip);

        $this->assertSame(array(), $this->logPageView());
    }

    /** A list written with the masked address still matches. */
    public function testAMaskedAddressInTheListStillMatches(): void
    {
        $ip = $this->requestIpOrSkip();

        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', true);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', \OWA\Core\Lib::anonymizeIp($ip));

        $this->assertSame(array(), $this->logPageView());
    }

    /** Not excluded: queued, carrying only the masked address. */
    public function testAnAddressNotListedIsQueuedMasked(): void
    {
        $ip = $this->requestIpOrSkip();

        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', true);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', '198.51.100.1');

        $queued = $this->logPageView();

        $this->assertCount(1, $queued);
        $this->assertSame(\OWA\Core\Lib::anonymizeIp($ip), $queued[0]['properties']['ip_address']);
        $this->assertStringNotContainsString($ip, json_encode($queued[0]));
    }
}
