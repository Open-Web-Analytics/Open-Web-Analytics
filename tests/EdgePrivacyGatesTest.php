<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\FileEventQueue;
use OWA\Module\Base\Classes\TrackerIngest;
use OWA\Module\Base\Classes\TrackingEventHelpers;

/**
 * What the edge (CoreAPI::logEvent()) keeps or refuses before a beacon is
 * queued: the identity fields, which are queued and processed as sent, and an
 * excluded IP matched against the full address while anonymize_ips is on.
 *
 * Queued mode against a scratch intake, so what the intake holds is read
 * back directly. The site is answered for without a database.
 */
final class EdgePrivacyGatesTest extends TestCase
{
    private const SITE = 'edge-privacy-gates-site';

    private string $dir;

    /** @var array<string,mixed> */
    private array $saved = [];

    /** @var mixed the request container's server params before this test */
    private $savedServer;

    protected function setUp(): void
    {
        \OWA\Core\CoreAPI::rememberSiteRegistration(self::SITE, true);

        // A browser request from a documentation-range address, whatever the
        // runner's own environment holds (none, under CLI in CI).
        $request = \OWA\Core\CoreAPI::requestContainerSingleton();
        $this->savedServer = $request->server;
        $request->server = [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'REMOTE_ADDR'     => '203.0.113.77',
        ];

        foreach (['queue_tracking_events', 'anonymize_ips', 'excluded_ips'] as $key) {
            $this->saved[$key] = \OWA\Core\CoreAPI::getSetting('base', $key);
        }

        \OWA\Core\CoreAPI::setSetting('base', 'queue_tracking_events', true);
        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', false);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', '');

        $this->dir = sys_get_temp_dir() . '/owa-edge-gates-' . bin2hex(random_bytes(4)) . '/';
        TrackerIngest::$queue = new FileEventQueue(['path' => $this->dir]);
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
    private function logPageView(array $properties = []): array
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setEventType('page_view');
        $event->setProperties($properties + [
            'site_id'       => self::SITE,
            'page_location' => 'https://edge.test/a',
        ]);

        \OWA\Core\CoreAPI::logEvent('page_view', $event);

        $lines = is_file($this->dir . 'events.txt') ? file($this->dir . 'events.txt', FILE_IGNORE_NEW_LINES) : [];

        return array_map(fn ($line) => json_decode($line, true)['e'], array_values(array_filter($lines)));
    }

    private const IDENTITY = [
        'user_id'        => 'person-42',
        'vps_user_name'  => 'Display Name',
        'vps_user_email' => 'person@example.test',
    ];

    // ---------------------------------------------------------------------
    // The identity fields
    // ---------------------------------------------------------------------

    public function testTheIdentityFieldsAreQueuedAsSent(): void
    {
        $queued = $this->logPageView(self::IDENTITY);

        $this->assertCount(1, $queued);

        foreach (self::IDENTITY as $name => $value) {
            $this->assertSame($value, $queued[0]['properties'][$name] ?? null, $name);
        }
    }

    /** The property stage leaves them as sent, a 1.x tracker's names renamed onto the user properties. */
    public function testThePropertyStageKeepsThemAsSent(): void
    {
        $queued = $this->logPageView(self::IDENTITY);

        $process = new \OWA\Module\Base\Controller\ProcessEvent(['event' => TrackerIngest::event($queued[0])]);
        $process->pre();

        foreach (self::IDENTITY as $name => $value) {
            $this->assertSame($value, $process->event->get($name), $name);
        }

        $old = $this->logPageView(['user_name' => 'Old Name', 'email_address' => 'old@example.test']);

        $process = new \OWA\Module\Base\Controller\ProcessEvent(['event' => TrackerIngest::event(end($old))]);
        $process->pre();

        $this->assertSame('Old Name', $process->event->get('vps_user_name'));
        $this->assertSame('old@example.test', $process->event->get('vps_user_email'));
    }

    /** A request from a user signed in to OWA is recorded like any other. */
    public function testASignedInUsersRequestIsQueued(): void
    {
        $cu  = \OWA\Core\CoreAPI::getCurrentUser();
        $was = $cu->getUserData('user_id');
        $cu->setUserData('user_id', 'edge-gates-signed-in');

        try {
            $this->assertCount(1, $this->logPageView());
        } finally {
            $cu->setUserData('user_id', $was);
        }
    }

    /** The 1.x migration maps user_name to user_id. */
    public function testTheMigrationKeepsTheUserName(): void
    {
        $migrator = new class extends \OWA\Module\Base\Classes\Migration\FactMigrator {
            protected function events(array $r, array $refs)
            {
                return [$this->baseEvent($r, $refs, 'page_view')];
            }

            public function one(array $r)
            {
                return $this->events($r, []);
            }
        };

        $row = ['id' => 1, 'site_id' => self::SITE, 'visitor_id' => '11', 'session_id' => '12',
                     'timestamp' => 1700000000, 'yyyymmdd' => 20231114, 'user_name' => 'person-7'];

        $this->assertSame('person-7', $migrator->one($row)[0]->get('user_id'));
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

    /**
     * The full address in the list excludes the request, though only the masked one would be stored.
     *
     * @covers-setting base.excluded_ips
     */
    public function testAnExcludedAddressMatchesWithAnonymisationOn(): void
    {
        $ip = $this->requestIpOrSkip();

        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', true);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', '198.51.100.1, ' . $ip);

        $this->assertSame([], $this->logPageView());
    }

    /**
     * A list written with the masked address still matches.
     *
     * @covers-setting base.excluded_ips
     */
    public function testAMaskedAddressInTheListStillMatches(): void
    {
        $ip = $this->requestIpOrSkip();

        \OWA\Core\CoreAPI::setSetting('base', 'anonymize_ips', true);
        \OWA\Core\CoreAPI::setSetting('base', 'excluded_ips', \OWA\Core\Lib::anonymizeIp($ip));

        $this->assertSame([], $this->logPageView());
    }

    /**
     * Not excluded: queued, carrying only the masked address.
     *
     * @covers-setting base.anonymize_ips
     */
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
