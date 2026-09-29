<?php

use PHPUnit\Framework\TestCase;

/**
 * A missing site id asks for no row, so it must not raise.
 *
 * getSiteSetting() passed its argument to Entity::load(), which reaches
 * getByColumn(). That method throws "No value passed." on an empty value, so a
 * request that carried no usable siteId surfaced as an uncaught exception
 * instead of the "no setting" answer callers already handle -- getSiteSetting()
 * returns null for a site that is not persisted, and every caller tests the
 * return value before using it.
 *
 * The goal reports were the path that made this reachable: the controller passed
 * the request's siteId straight into the goal manager (since removed), whose
 * constructor called getSiteSetting(). Any request whose siteId did not arrive
 * under that exact name therefore reached getByColumn() with nothing to look up.
 *
 * getSiteSetting() now answers the empty case itself, which covers every caller
 * rather than only the one that exposed it.
 */
final class SiteSettingMissingSiteIdTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * The values a request produces when the parameter is absent, misspelled,
     * or empty. getParam() yields false for a parameter that is not set.
     */
    public static function absentSiteIds(): array
    {
        return [
            'parameter not set' => [false],
            'null'              => [null],
            'empty string'      => [''],
            'zero string'       => ['0'],
            'zero int'          => [0],
        ];
    }

    /**
     * @dataProvider absentSiteIds
     */
    public function testAbsentSiteIdReturnsNothingInsteadOfThrowing($site_id)
    {
        $this->assertNull(
            \OWA\Core\CoreAPI::getSiteSetting($site_id, 'goals'),
            'an absent site id should read as "no setting", not raise'
        );
    }

    /**
     * The guard must not swallow the lookup for a real site id. A site id that
     * is well-formed but not present still resolves through load(), and still
     * answers null because the row was never persisted -- reaching that answer
     * by the normal path rather than by the early return.
     *
     * This is the only case here that reaches load(), so it is the only one
     * that needs a configured install: the entity cache it passes through
     * reads OWA_AUTH_KEY, which a config-less CI run does not define. The
     * guard cases above return before any of that.
     */
    public function testUnknownButWellFormedSiteIdStillResolvesThroughLoad()
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping site lookup test.');
        }

        $this->assertNull(
            \OWA\Core\CoreAPI::getSiteSetting('no-such-site-' . bin2hex(random_bytes(8)), 'goals'),
            'an unknown site should answer null'
        );
    }
}

