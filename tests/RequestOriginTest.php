<?php

use OWA\Core\RequestOrigin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * RequestOrigin: the origin a request was made to, from its scheme and Host,
 * and whether the request is same-origin with it -- what the REST API's
 * session-cookie check asks.
 *
 * Pure but for the last tests, which drive Auth::authenticateUser() with a
 * request refused before any user is looked up.
 */
final class RequestOriginTest extends TestCase
{
    private const OWN = 'https://owa.example.com';

    public function testAnOriginIsSchemeHostAndANonDefaultPort(): void
    {
        $this->assertSame('https://owa.example.com', RequestOrigin::of('https://OWA.example.com/owa/'));
        $this->assertSame('https://owa.example.com', RequestOrigin::of('https://owa.example.com:443/'));
        $this->assertSame('http://owa.example.com:8080', RequestOrigin::of('http://owa.example.com:8080/x'));
        $this->assertSame('http://localhost', RequestOrigin::of('http://localhost:80'));

        foreach (['', 'null', 'owa.example.com', '//owa.example.com', 'ftp://owa.example.com', "https://owa.example.com\\@evil.test", null, ['x']] as $bad) {
            $this->assertSame('', RequestOrigin::of($bad), 'read: ' . json_encode($bad));
        }
    }

    /** @return array<string, array{string, string, bool}> Origin, Sec-Fetch-Site, same-origin */
    public static function requests(): array
    {
        return [
            'no headers'                       => ['', '', true],
            'OWA\'s own Origin'                => ['https://owa.example.com', '', true],
            'OWA\'s own, with its default port' => ['https://owa.example.com:443', 'same-origin', true],
            'same-origin fetch metadata'       => ['', 'same-origin', true],
            'another origin'                   => ['https://shop.example.com', '', false],
            'a parent of OWA\'s host'          => ['https://example.com', '', false],
            'OWA\'s host over http'            => ['http://owa.example.com', '', false],
            'OWA\'s host on another port'      => ['https://owa.example.com:8443', '', false],
            'the opaque origin'                => ['null', '', false],
            'cross-site, no Origin'            => ['', 'cross-site', false],
            'same-site, no Origin'             => ['', 'same-site', false],
            'typed into the address bar'       => ['', 'none', false],
            'OWA\'s Origin, but cross-site'    => ['https://owa.example.com', 'cross-site', false],
        ];
    }

    /** @dataProvider requests */
    public function testWhichRequestsAreSameOrigin(string $origin, string $fetchSite, bool $same): void
    {
        $this->assertSame($same, RequestOrigin::isSameOrigin($origin, $fetchSite, self::OWN));
    }

    /** @return array<string, array{array<string, mixed>, string}> server variables, the origin */
    public static function madeTo(): array
    {
        return [
            'https, by the server'     => [['HTTPS' => 'on', 'HTTP_HOST' => 'owa.example.com'], 'https://owa.example.com'],
            'https, by the proxy'      => [['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'OWA.example.com', 'REMOTE_ADDR' => '127.0.0.1'], 'https://owa.example.com'],
            'https, said by a client'  => [['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'owa.example.com', 'REMOTE_ADDR' => '203.0.113.9'], 'http://owa.example.com'],
            'http'                     => [['HTTP_HOST' => 'owa.example.com'], 'http://owa.example.com'],
            'a port'                   => [['HTTP_HOST' => 'localhost:8080'], 'http://localhost:8080'],
            'the default port'         => [['HTTPS' => 'on', 'HTTP_HOST' => 'owa.example.com:443'], 'https://owa.example.com'],
            'no Host'                  => [[], ''],
        ];
    }

    /**
     * The origin is the request's own, whatever public_url says.
     *
     * @dataProvider madeTo
     */
    public function testTheOwnOriginIsTheRequestsSchemeAndHost(array $server, string $expected): void
    {
        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $saved   = [$request->server, $_SERVER, \OWA\Core\CoreAPI::getSetting('base', 'public_url')];

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'public_url', 'https://elsewhere.example.org/owa/');
            $_SERVER = $server;
            $request->server = $server;

            $this->assertSame($expected, RequestOrigin::own());
        } finally {
            [$request->server, $_SERVER] = $saved;
            \OWA\Core\CoreAPI::setSetting('base', 'public_url', $saved[2]);
        }
    }

    public function testWithNoOwnOriginOnlyARequestWithoutAnOriginIs(): void
    {
        $this->assertTrue(RequestOrigin::isSameOrigin('', '', ''));
        $this->assertFalse(RequestOrigin::isSameOrigin('https://owa.example.com', '', ''));
    }

    /**
     * A REST request from another origin carrying the session cookies is
     * judged as one that carried none, before any user is looked up.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function crossOrigin(): array
    {
        return [
            'another Origin'          => [['HTTP_ORIGIN' => 'https://shop.example.com']],
            'cross-site, no Origin'   => [['HTTP_SEC_FETCH_SITE' => 'cross-site']],
        ];
    }

    /** @dataProvider crossOrigin */
    public function testARestRequestFromAnotherOriginIsNotSignedInByItsCookies(array $server): void
    {
        $request = \OWA\Core\CoreAPI::serviceSingleton()->request;
        $saved   = [
            'server' => $request->server,
            'stores' => $request->state->stores,
            'type'   => \OWA\Core\CoreAPI::getSetting('base', 'request_type'),
        ];

        $auth = \OWA\Core\Auth::get_instance();
        $method = new \ReflectionProperty($auth, 'auth_method');
        $savedMethod = $method->getValue($auth);

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::REST);
            $request->server = array_merge((array) $saved['server'], ['HTTP_HOST' => 'owa.example.com'], $server);
            $request->state->stores['u'] = 'someone@owatest.example.com';
            $request->state->stores['p'] = 'a-session-credential';
            $method->setValue($auth, '');

            $status = $auth->authenticateUser(['overlay_token', 'app_password', 'cookies']);

            $this->assertFalse((bool) $status['auth_status']);
            $this->assertTrue($auth->refusedCrossOriginCookies());
            $this->assertNotSame('cookies', $auth->getAuthMethod());
        } finally {
            $request->server = $saved['server'];
            $request->state->stores = $saved['stores'];
            \OWA\Core\CoreAPI::setSetting('base', 'request_type', $saved['type']);
            $method->setValue($auth, $savedMethod);
        }
    }
}
