<?php

use PHPUnit\Framework\TestCase;

/**
 * Which signals may tell OWA that the current request arrived over HTTPS.
 *
 * Not HTTP_ORIGIN or HTTP_REFERER. Both describe the *other end* of the
 * connection -- where the caller came from -- and neither says anything about
 * how this request reached this server. Anyone can send either.
 *
 * Lib::get_current_url() builds its scheme from isHttps(), and
 * Auth::isSignatureValid() recomputes an API request's signature over that
 * URL. A scheme steered by a visitor's Referer would make a signed
 * cross-origin request from an https page verify against the wrong URL and
 * 401, and would steer anything else building an absolute URL from
 * get_current_url().
 *
 * The forwarding headers are a different case. X-Forwarded-Proto and
 * X-Forwarded-Port are set by a terminating proxy -- which is how TLS
 * actually reaches OWA in a normal deployment -- and describe *this* hop, so
 * they are read when the connecting peer is a proxy of this installation's:
 * loopback, private, or in OWA_TRUSTED_PROXIES (ClientAddress::isProxy()).
 * From any other peer they are the client's own.
 */
final class IsHttpsHeaderTrustTest extends TestCase
{
    /** @var array */
    private $server;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        // A plain http request on a non-standard port, as php -S or a
        // proxied backend would see it.
        $_SERVER = ['SERVER_PORT' => 8964, 'HTTP_HOST' => 'owa.example.com'];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * Either header, set by anyone, is not proof.
     */
    public function testAClientSuppliedOriginDoesNotMakeARequestHttps(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://anyone.example.com';

        $this->assertNotTrue(
            \OWA\Core\Lib::isHttps(),
            'Origin describes where the caller came from, not how this request arrived'
        );
    }

    public function testAClientSuppliedRefererDoesNotMakeARequestHttps(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://anyone.example.com/some/page';

        $this->assertNotTrue(
            \OWA\Core\Lib::isHttps(),
            'Referer describes the page the visitor left, not this connection'
        );
    }

    /**
     * The consequence that surfaced it: the scheme in the URL a signature is
     * computed over must not move because a caller sent a header.
     */
    public function testTheCurrentUrlSchemeIsNotChangedByAClientHeader(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/index.php?owa_do=sites';

        $plain = \OWA\Core\Lib::get_current_url();

        $_SERVER['HTTP_ORIGIN'] = 'https://anyone.example.com';
        $withOrigin = \OWA\Core\Lib::get_current_url();

        $this->assertSame(
            $plain,
            $withOrigin,
            'a client header changed the scheme of the URL signatures are verified against'
        );
        $this->assertStringStartsWith('http://', $plain);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forwardedHeaders(): iterable
    {
        yield 'X-Forwarded-Proto' => [['HTTP_X_FORWARDED_PROTO' => 'https']];
        yield 'X-Forwarded-Port'  => [['HTTP_X_FORWARDED_PORT' => 443]];
    }

    /**
     * A terminating proxy's signals count -- this is how TLS reaches OWA in
     * a normal deployment: from loopback (Varnish, nginx on the same host),
     * a private address, or a listed proxy.
     *
     * @dataProvider forwardedHeaders
     */
    public function testForwardedHeadersFromAProxyAreTrusted(array $headers): void
    {
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $saved = $c->get('base', 'trusted_proxies');

        try {
            $c->set('base', 'trusted_proxies', ['198.51.100.0/24']);

            foreach (['127.0.0.1', '::1', '10.0.3.7', '192.168.1.2', '198.51.100.20'] as $peer) {
                $_SERVER = ['SERVER_PORT' => 8964, 'REMOTE_ADDR' => $peer] + $headers;
                $this->assertTrue((bool) \OWA\Core\Lib::isHttps(), $peer);
            }
        } finally {
            $c->set('base', 'trusted_proxies', $saved);
        }
    }

    /**
     * From a public peer that is not a listed proxy, they are what the client
     * chose to send.
     *
     * @dataProvider forwardedHeaders
     */
    public function testForwardedHeadersFromAPublicPeerAreIgnored(array $headers): void
    {
        $c     = \OWA\Core\CoreAPI::configSingleton();
        $saved = $c->get('base', 'trusted_proxies');

        try {
            $c->set('base', 'trusted_proxies', ['198.51.100.0/24']);

            foreach (['203.0.113.9', '2a00:1450:4001:80b::200e', '', 'not-an-address'] as $peer) {
                $_SERVER = ['SERVER_PORT' => 8964, 'REMOTE_ADDR' => $peer] + $headers;
                $this->assertNotTrue(\OWA\Core\Lib::isHttps(), var_export($peer, true));
            }

            $_SERVER = ['SERVER_PORT' => 8964, 'HTTP_HOST' => 'owa.example.com', 'REMOTE_ADDR' => '203.0.113.9', 'REQUEST_URI' => '/index.php'] + $headers;
            $this->assertStringStartsWith('http://', \OWA\Core\Lib::get_current_url());
        } finally {
            $c->set('base', 'trusted_proxies', $saved);
        }
    }

    /**
     * And the direct signals, whoever connected.
     */
    public function testDirectTlsIsDetected(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertTrue((bool) \OWA\Core\Lib::isHttps());

        $_SERVER = ['SERVER_PORT' => 443, 'REMOTE_ADDR' => '203.0.113.9'];
        $this->assertTrue((bool) \OWA\Core\Lib::isHttps());
    }

    /**
     * A plain request stays plain.
     */
    public function testAPlainRequestIsNotHttps(): void
    {
        $this->assertNotTrue(\OWA\Core\Lib::isHttps());
    }

    /**
     * An http Origin was never a positive signal and must not become one.
     */
    public function testAnHttpOriginIsNotHttps(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'http://anyone.example.com';

        $this->assertNotTrue(\OWA\Core\Lib::isHttps());
    }
}
