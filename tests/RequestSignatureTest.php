<?php

use PHPUnit\Framework\TestCase;

/**
 * What the request signature covers -- and what used to be carved out of it.
 *
 * isSignatureValid() strips certain parameters before recomputing the HMAC,
 * because they are appended by the client *after* the URL is signed. Each strip
 * is a hole by construction: a stripped parameter can be set to anything without
 * invalidating the signature.
 *
 *   '_'                  jQuery's cache-buster. Still needed, still stripped.
 *   'owa_signature'      cannot be part of what it signs.
 *   'owa_jsonpCallback'  REMOVED with JSONP. It existed because jQuery appended
 *                        the callback name after signing.
 *
 * That last one is why this file exists. The exemption would have outlived the
 * feature that needed it -- nothing referenced owa_jsonpCallback once the
 * overlays moved to CORS -- leaving a parameter permanently outside the
 * signature for no reason at all.
 *
 * This is a unit test rather than an e2e case on purpose: over HTTP an appended
 * parameter answers 401 whether or not the exemption is present (the exemption
 * only decides *why*), so an end-to-end assertion would pass either way. The
 * signature function is where the difference is observable.
 */
final class RequestSignatureTest extends TestCase
{
    private const KEY = 'deadbeefdeadbeefdeadbeefdeadbeef';

    private \OWA\Core\Auth $auth;
    private string $signed;
    private string $signature;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    protected function setUp(): void
    {
        $this->auth = new \OWA\Core\Auth();

        $base = 'http://owa.example.com/api/index.php'
            . '?owa_rest_params=base/v1/sites&owa_apiKey=' . self::KEY;

        $this->signature = $this->auth->generateSignature($base, self::KEY);
        $this->signed    = $base . '&owa_signature=' . urlencode($this->signature);
    }

    private function valid(string $url): bool
    {
        return (bool) $this->auth->isSignatureValid($this->signature, self::KEY, $url);
    }

    public function testASignedUrlValidates(): void
    {
        $this->assertTrue($this->valid($this->signed));
    }

    /**
     * The property the removal buys: no parameter may be bolted on afterwards.
     */
    public function testAParameterAppendedAfterSigningInvalidatesTheSignature(): void
    {
        $this->assertFalse(
            $this->valid($this->signed . '&owa_jsonpCallback=owaEvil'),
            'owa_jsonpCallback must no longer be exempt from the signature'
        );
    }

    /**
     * Not special to that one name -- the point is that nothing is exempt, so an
     * arbitrary added parameter must fail the same way.
     */
    public function testAnArbitraryAppendedParameterAlsoInvalidatesIt(): void
    {
        $this->assertFalse($this->valid($this->signed . '&owa_anything=1'));
        $this->assertFalse($this->valid($this->signed . '&owa_siteId=someone-elses-site'));
    }

    /**
     * The cache-buster must stay exempt. jQuery appends `_` after signing, so
     * removing this strip would break every signed request it makes -- the
     * mirror image of the mistake above, and the reason the strips cannot simply
     * all be deleted.
     */
    public function testTheCacheBusterRemainsExempt(): void
    {
        $this->assertTrue(
            $this->valid($this->signed . '&_=1699999999999'),
            "jQuery's cache-buster is appended after signing and must stay exempt"
        );
    }

    public function testATamperedSignatureIsRefused(): void
    {
        $this->assertFalse(
            (bool) $this->auth->isSignatureValid('not-the-signature', self::KEY, $this->signed)
        );
    }

    /** A signature that is not a string (signature[]=x) is refused, not an error. */
    public function testANonStringSignatureIsRefused(): void
    {
        $this->assertFalse((bool) $this->auth->isSignatureValid(['x'], self::KEY, $this->signed));
        $this->assertFalse((bool) $this->auth->isSignatureValid(null, self::KEY, $this->signed));
    }

    /**
     * The comparison takes the same time however much of the signature is
     * right (hash_equals()), and neither the signature sent nor the one
     * computed is logged. Read from the source: neither is observable in a
     * result.
     */
    public function testTheSignatureIsComparedInConstantTimeAndNotLogged(): void
    {
        $method = new ReflectionMethod(\OWA\Core\Auth::class, 'isSignatureValid');
        $lines  = file((string) $method->getFileName());
        $body   = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('hash_equals( $computed_signature, $signature )', $body);
        $this->assertStringNotContainsString('$signature === ', $body);
        $this->assertDoesNotMatchRegularExpression('/debug\([^;]*\$(computed_)?signature\b/', $body);

        $source = (string) file_get_contents((string) $method->getFileName());
        $this->assertDoesNotMatchRegularExpression('/debug\(\s*\'Signature: \'/', $source);
    }
}
