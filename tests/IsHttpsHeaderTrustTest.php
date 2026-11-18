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
 * The forwarding headers are a different case and are kept. X-Forwarded-Proto
 * and X-Forwarded-Port are set by a terminating proxy -- which is how TLS
 * actually reaches OWA in a normal deployment -- and describe *this* hop.
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

    /**
     * A terminating proxy's signals still count -- this is how TLS reaches OWA
     * in a normal deployment, and removing them would break every install
     * behind a load balancer.
     */
    public function testForwardedProtoIsStillTrusted(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue((bool) \OWA\Core\Lib::isHttps());
    }

    public function testForwardedPortIsStillTrusted(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PORT'] = 443;

        $this->assertTrue((bool) \OWA\Core\Lib::isHttps());
    }

    /**
     * And the direct signals.
     */
    public function testDirectTlsIsDetected(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue((bool) \OWA\Core\Lib::isHttps());

        $_SERVER = ['SERVER_PORT' => 443];
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
