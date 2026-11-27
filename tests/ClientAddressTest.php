<?php

use OWA\Core\ClientAddress;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The client is the first address, from the right of X-Forwarded-For and
 * REMOTE_ADDR, that no trusted proxy added (Core\ClientAddress).
 */
final class ClientAddressTest extends TestCase
{
    private $server;

    protected function setUp(): void
    {
        $this->server = \OWA\Core\CoreAPI::serviceSingleton()->request->server;
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::serviceSingleton()->request->server = $this->server;
        \OWA\Core\CoreAPI::setSetting('base', 'trusted_proxies', []);
    }

    /** The request's server params, as the request container holds them. */
    private function request(array $server): void
    {
        // (array): a run without owa-config.php has no server params at all.
        \OWA\Core\CoreAPI::serviceSingleton()->request->server = $server + (array) $this->server;
    }

    /** @return array<string, array{string, string, list<string>, string}> */
    public static function chains(): array
    {
        return [
            'a load balancer and a cache on this host' => ['127.0.0.1', 'spoofed, 203.0.113.9, 10.0.1.5', [], '203.0.113.9'],
            'a direct connection'                      => ['203.0.113.9', '198.51.100.1', [], '203.0.113.9'],
            'no header'                                => ['203.0.113.9', '', [], '203.0.113.9'],
            'an SDK server, not trusted'               => ['127.0.0.1', '198.51.100.7, 192.0.2.10, 10.0.1.5', [], '192.0.2.10'],
            'an SDK server, trusted'                   => ['127.0.0.1', '198.51.100.7, 192.0.2.10, 10.0.1.5', ['192.0.2.10'], '198.51.100.7'],
            'an SDK server in a trusted range'         => ['127.0.0.1', '198.51.100.7, 192.0.2.10, 10.0.1.5', ['192.0.2.0/24'], '198.51.100.7'],
            'a range that does not hold it'            => ['127.0.0.1', '198.51.100.7, 192.0.2.10', ['192.0.2.0/29'], '192.0.2.10'],
            'an IPv6 range'                            => ['127.0.0.1', '2a00:1450:4001:80b::200e, 2600:1f18:aa::5', ['2600:1f18::/32'], '2a00:1450:4001:80b::200e'],
            'an IPv6 range that does not hold it'      => ['127.0.0.1', '2a00:1450:4001:80b::200e, 2600:1f18:aa::5', ['2600:1f19::/32'], '2600:1f18:aa::5'],
            'an IPv4 range never holds IPv6'           => ['::1', '2600:1f18:aa::5', ['0.0.0.0/0'], '2600:1f18:aa::5'],
            'all private'                              => ['127.0.0.1', '192.168.1.20, 10.0.1.5', [], '192.168.1.20'],
            'all private, no header'                   => ['10.0.1.5', '', [], '10.0.1.5'],
            'everything trusted'                       => ['127.0.0.1', '198.51.100.7, 192.0.2.10', ['198.51.100.7', '192.0.2.10'], '198.51.100.7'],
            'an invalid hop ends the walk'             => ['127.0.0.1', '203.0.113.9, garbage, 10.0.1.5', [], '10.0.1.5'],
            'an invalid hop next to the peer'          => ['127.0.0.1', '203.0.113.9, garbage', [], '127.0.0.1'],
            'ports and brackets'                       => ['127.0.0.1', '203.0.113.9:4711, [2a00:1450:4001:80b::200e]:443, 10.0.1.5:80', [], '2a00:1450:4001:80b::200e'],
            'no address at all'                        => ['', '', [], ''],
        ];
    }

    /** @dataProvider chains */
    public function testTheClientIsTheFirstUntrustedAddressFromTheRight(string $remote, string $xff, array $trusted, string $client): void
    {
        $this->assertSame($client, ClientAddress::choose($remote, $xff, $trusted));
    }

    /** Client-IP and the other headers any client can send are not read. */
    public function testTheRequestsOtherHeadersAreIgnored(): void
    {
        $this->request([
            'REMOTE_ADDR'              => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR'     => 'spoofed, 203.0.113.9, 10.0.1.5',
            'HTTP_CLIENT_IP'           => '198.51.100.66',
            'HTTP_X_CLUSTER_CLIENT_IP' => '198.51.100.67',
            'HTTP_FORWARDED_FOR'       => '198.51.100.68',
        ]);

        $this->assertSame('203.0.113.9', ClientAddress::fromRequest());
        $this->assertSame('203.0.113.9', \OWA\Module\Base\Classes\TrackingEventHelpers::requestIp());

        $hop = new ReflectionMethod(\OWA\Core\Auth::class, 'lastPublicHop');
        $this->assertSame('203.0.113.9', $hop->invoke(null), 'an application password records the same address');
    }

    public function testTheTrustedProxiesAreTheSetting(): void
    {
        $this->request(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 192.0.2.10']);

        $this->assertSame('192.0.2.10', ClientAddress::fromRequest());

        \OWA\Core\CoreAPI::setSetting('base', 'trusted_proxies', ['192.0.2.10']);
        $this->assertSame('198.51.100.7', ClientAddress::fromRequest());
    }

    public function testTheSettingIsTheConstants(): void
    {
        $this->assertSame(ClientAddress::CONSTANT,
            \OWA\Module\Base\Classes\Settings::constantForSetting('base', 'trusted_proxies'));
    }

    /** @return array<string, array{mixed, int}> */
    public static function lists(): array
    {
        return [
            'addresses and ranges' => [['192.0.2.10', '198.51.100.0/24', '2600:1f18::/32', '2a00:1450:4001:80b::200e', '0.0.0.0/0'], 0],
            'empty'                => [[], 0],
            'not a list'           => ['192.0.2.10', 1],
            'a host name'          => [['proxy.example.com'], 1],
            'a prefix too long'    => [['192.0.2.0/33', '2600:1f18::/129'], 2],
            'a bad prefix'         => [['192.0.2.0/', '192.0.2.0/x'], 2],
            'not a string'         => [[['192.0.2.10']], 1],
        ];
    }

    /** @dataProvider lists */
    public function testAnEntryThatIsNotAnAddressOrARangeIsAConfigProblem($value, int $problems): void
    {
        $found = ClientAddress::configProblems($value);

        $this->assertCount($problems, $found, print_r($found, true));

        foreach ($found as $problem) {
            $this->assertStringStartsWith('OWA_TRUSTED_PROXIES ', $problem);
        }
    }

    /** The one trust test, shared with Lib::isHttps(): private, reserved or listed. */
    public function testAProxyIsPrivateOrListed(): void
    {
        $trusted = ['198.51.100.0/24', '2001:db8:1::/48'];

        foreach (['127.0.0.1', '::1', '10.1.2.3', '172.16.0.1', '192.168.0.9', '198.51.100.7', '2001:db8:1::5', '127.0.0.1:8080'] as $proxy) {
            $this->assertTrue(ClientAddress::isProxy($proxy, $trusted), $proxy);
        }

        foreach (['203.0.113.9', '2a00:1450:4001:80b::200e', '', 'proxy.example.test'] as $client) {
            $this->assertFalse(ClientAddress::isProxy($client, $trusted), var_export($client, true));
        }
    }
}
