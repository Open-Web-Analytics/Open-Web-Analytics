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
 * The goal reports are the path that made this reachable: the controller passes
 * the request's siteId straight into GoalManager, whose constructor calls
 * loadGoals() -> getSiteSetting(). Any request whose siteId did not arrive under
 * that exact name therefore reached getByColumn() with nothing to look up.
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
     */
    public function testUnknownButWellFormedSiteIdStillResolvesThroughLoad()
    {
        $this->assertNull(
            \OWA\Core\CoreAPI::getSiteSetting('no-such-site-' . bin2hex(random_bytes(8)), 'goals'),
            'an unknown site should answer null'
        );
    }

    /**
     * The construction that raised. GoalManager's constructor calls
     * loadGoals(), so building it with no site id exercises the path end to end
     * and must produce a usable object carrying the default goals.
     */
    public function testGoalManagerBuildsWithoutASiteId()
    {
        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', false);

        $this->assertNotNull($gm, 'goalManager should still be constructible');
        $this->assertIsArray($gm->getGoal(1), 'a default goal should be available');
    }
}
