<?php

use PHPUnit\Framework\TestCase;

/**
 * The credential the heatmap overlay and domstream player carry.
 *
 * What this replaces: the report templates built their API URL with
 * makeApiLink( ..., $add_apiKey = true ), which embeds the signed-in user's
 * **apiKey** in the URL and HMAC-signs it. The tracker then wrote that URL to
 * an `owa_overlay` cookie on the *tracked site's* own domain, path `/`
 * (Tracker.js:362), where the overlay's JS could read it back.
 *
 * So a long-lived credential carrying a user's full privileges sat in a
 * JS-readable cookie on a domain OWA does not control -- readable by any other
 * script on that page, and re-sent by the browser on every subsequent request
 * to that site, landing in its access logs and its CDN's. It could not be
 * HttpOnly, because the overlay itself has to read it.
 *
 * The replacement is a token that is worth almost nothing if it leaks: it names
 * one user, one action, one resource, and expires in minutes. Verification is
 * stateless -- the signature is recomputed, not looked up -- so this needs no
 * table, no schema version and no cleanup job. Early revocation is moot at this
 * lifetime.
 */
final class OverlayTokenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function mint(array $overrides = []): string
    {
        $a = array_merge([
            'user_id'      => 'reporter',
            'action'       => 'reports',
            'resource_key' => 'document_id',
            'resource'     => 'doc-12345',
            'ttl'          => 300,
        ], $overrides);

        return \OWA\Core\OverlayToken::mint(
            $a['user_id'], $a['action'], $a['resource_key'], $a['resource'], $a['ttl']
        );
    }

    /** A stand-in for the request: given a param name, return its value. */
    private function request(array $params): callable
    {
        return static fn($name) => $params[$name] ?? '';
    }

    public function testAMintedTokenVerifiesAndReportsItsScope(): void
    {
        $claims = \OWA\Core\OverlayToken::verify($this->mint());

        $this->assertIsArray($claims);
        $this->assertSame('reporter', $claims['user_id']);
        $this->assertSame('reports', $claims['action']);
        $this->assertSame('doc-12345', $claims['resource']);
    }

    /**
     * The point of the whole change: a token is useless for anything but the
     * one thing it was minted for.
     */
    public function testScopeIsEnforcedOnActionAndResource(): void
    {
        $token = $this->mint(['action' => 'reports', 'resource' => 'doc-12345']);

        $this->assertTrue(\OWA\Core\OverlayToken::permits(
            $token, 'reports', $this->request(['document_id' => 'doc-12345'])
        ));

        // Right user, right action, someone else's document.
        $this->assertFalse(\OWA\Core\OverlayToken::permits(
            $token, 'reports', $this->request(['document_id' => 'doc-99999'])
        ));

        // Right resource, different endpoint.
        $this->assertFalse(\OWA\Core\OverlayToken::permits(
            $token, 'domstreams', $this->request(['document_id' => 'doc-12345'])
        ));

        // The resource arriving under a different parameter than the token
        // names does not satisfy it.
        $this->assertFalse(\OWA\Core\OverlayToken::permits(
            $token, 'reports', $this->request(['domstream_guid' => 'doc-12345'])
        ));

        // Omitting the parameter entirely must not pass unchecked.
        $this->assertFalse(\OWA\Core\OverlayToken::permits(
            $token, 'reports', $this->request([])
        ));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $expired = $this->mint(['ttl' => -1]);

        $this->assertNull(\OWA\Core\OverlayToken::verify($expired));
        $this->assertFalse(\OWA\Core\OverlayToken::permits(
            $expired, 'reports', $this->request(['document_id' => 'doc-12345'])
        ));
    }

    /**
     * Every field is covered by the signature, so none can be edited in the
     * URL -- which is the whole difference between this and a bare identifier.
     */
    public function testTamperingWithAnyClaimInvalidatesTheToken(): void
    {
        $token = $this->mint();
        [$payload, $sig] = explode('.', $token, 2);

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertIsArray($claims, 'payload should decode; the signature is what protects it');

        foreach ([
            ['user_id' => 'admin'],
            ['action' => 'sites'],
            ['resource' => 'doc-99999'],
            ['resource_key' => 'domstream_guid'],
            ['exp' => time() + 999999],
        ] as $edit) {
            $edited  = array_merge($claims, $edit);
            $forged  = rtrim(strtr(base64_encode(json_encode($edited)), '+/', '-_'), '=') . '.' . $sig;

            $this->assertNull(
                \OWA\Core\OverlayToken::verify($forged),
                'edited claim was accepted: ' . key($edit)
            );
        }
    }

    public function testAGarbageTokenIsRefusedRatherThanFatal(): void
    {
        foreach (['', 'nonsense', 'a.b', '.', 'YWJj', str_repeat('x', 5000)] as $bad) {
            $this->assertNull(\OWA\Core\OverlayToken::verify($bad), "accepted: $bad");
            $this->assertFalse(\OWA\Core\OverlayToken::permits(
                $bad, 'reports', $this->request(['document_id' => 'doc-12345'])
            ));
        }
    }

    /**
     * Two tokens for the same scope differ, so one cannot be recognised as a
     * stable identifier for a user or a page.
     */
    public function testTokensAreNotAStableIdentifier(): void
    {
        $this->assertNotSame(
            $this->mint(['resource' => 'doc-1']),
            $this->mint(['resource' => 'doc-2'])
        );
    }

    /**
     * The token is URL-safe: it travels in a query string and in a fragment,
     * so it must survive both without escaping.
     */
    public function testTheTokenIsUrlSafe(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $token = $this->mint(['resource' => 'doc-' . $i]);
            $this->assertSame($token, urlencode($token), 'token needs escaping: ' . $token);
        }
    }

    /**
     * A token minted for one resource cannot be replayed against another by
     * swapping only the signature from a second token.
     */
    public function testSignaturesAreNotInterchangeable(): void
    {
        [$payloadA, ]        = explode('.', $this->mint(['resource' => 'doc-A']), 2);
        [, $sigB]            = explode('.', $this->mint(['resource' => 'doc-B']), 2);

        $this->assertNull(\OWA\Core\OverlayToken::verify($payloadA . '.' . $sigB));
    }
}
