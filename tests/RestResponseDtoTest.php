<?php

use PHPUnit\Framework\TestCase;

/**
 * REST responses expose entity properties, not entity objects.
 *
 * Views used to hand entities straight to setResponseData(), so json_encode()
 * emitted every public member the object happened to have: the property bag, but
 * also _tableProperties, wasPersisted, cache and dirty. None of that is API
 * surface, and it made the payload a function of the entity's current shape --
 * a newly added sensitive column would ship the moment it existed.
 *
 * The reduction now happens inside setResponseData(), so an entity cannot reach
 * a response without passing through it, and the fields to withhold are declared
 * on the entity next to the columns they protect.
 */
final class RestResponseDtoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** Invoke the protected reducer directly. */
    private function reduce($data)
    {
        $m = new ReflectionMethod('\OWA\Core\View\RestApi', 'toResponseData');
        $m->setAccessible(true);

        return $m->invoke(null, $data, 0);
    }

    private function makeUser()
    {
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->set('user_id', 'dto-test@example.test');
        $u->set('real_name', 'DTO Test');
        $u->set('role', 'viewer');
        $u->set('password', 'a-password-hash');
        $u->set('temp_passkey', 'a-temp-passkey');
        $u->set('api_key', 'an-api-key');

        return $u;
    }

    public function testEntityIsReducedToItsProperties()
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->set('domain', 'https://dto.example.test');
        $site->set('name', 'DTO Test Site');

        $out = $this->reduce($site);

        $this->assertIsArray($out, 'an entity must reduce to an array');

        /*
         * The PUBLISHED shape: properties.<column>.value.
         *
         * Asserted explicitly because retiring it is what broke every deployed
         * consumer -- the WordPress plugin reads
         * $site['properties']['site_id']['value'] and got null for a month.
         * A change here needs a deprecation path, not a flip.
         */
        $this->assertArrayHasKey('properties', $out);
        $this->assertSame('https://dto.example.test', $out['properties']['domain']['value']);
        $this->assertSame('DTO Test Site', $out['properties']['name']['value']);
    }

    /**
     * The ORM bookkeeping that used to ride along. These are not fields anyone
     * asked for and they describe the storage layer, not the resource.
     */
    public function testEntityInternalsAreNotExposed()
    {
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->set('domain', 'https://dto.example.test');

        $out = $this->reduce($site);

        /*
         * 'properties' is NOT on this list any more, and that was the mistake.
         * The bag is the resource; the bookkeeping around it is the leak. #977
         * removed both, which fixed the leak and retired the contract with it.
         */
        foreach (['_tableProperties', 'wasPersisted', 'dirty', 'cache'] as $internal) {
            $this->assertArrayNotHasKey(
                $internal,
                $out,
                sprintf('%s is ORM internals and must not be serialized', $internal)
            );
        }
    }

    /** Fields an entity declares private stay out of the payload. */
    public function testUserCredentialsAreWithheld()
    {
        $out = $this->reduce($this->makeUser());

        foreach (['password', 'temp_passkey', 'api_key'] as $secret) {
            $this->assertArrayNotHasKey(
                $secret,
                $out['properties'],
                sprintf('%s must never appear in a response', $secret)
            );
        }

        // Still a usable representation of the user.
        $this->assertSame('dto-test@example.test', $out['properties']['user_id']['value']);
        $this->assertSame('DTO Test', $out['properties']['real_name']['value']);
    }

    /** Collections are the common case -- every element has to be reduced. */
    public function testArraysOfEntitiesAreReducedElementwise()
    {
        $out = $this->reduce(['a' => $this->makeUser(), 'b' => $this->makeUser()]);

        foreach (['a', 'b'] as $k) {
            $this->assertIsArray($out[$k]);
            $this->assertArrayNotHasKey('password', $out[$k]['properties']);
            $this->assertSame('dto-test@example.test', $out[$k]['properties']['user_id']['value']);
        }
    }

    /** Views that already assemble plain data must be unaffected. */
    public function testNonEntityDataPassesThroughUnchanged()
    {
        $payload = ['rows' => [1, 2, 3], 'label' => 'a string', 'n' => 42, 'flag' => true];

        $this->assertSame($payload, $this->reduce($payload));
        $this->assertSame('scalar', $this->reduce('scalar'));
        $this->assertNull($this->reduce(null));
    }

    /** A cyclic graph must terminate rather than exhaust memory. */
    public function testRecursionIsBounded()
    {
        $deep = 'leaf';
        for ($i = 0; $i < 25; $i++) {
            $deep = [$deep];
        }

        $this->assertNotNull($this->reduce($deep), 'a deep structure should still return');
    }
}
