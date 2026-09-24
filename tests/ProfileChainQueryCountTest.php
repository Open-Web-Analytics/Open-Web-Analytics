<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A Profile's settings resolve in one query.
 *
 * The walk used to cost three: load the site row to find its property_id, load
 * the property row to find its organization_id, then ask for the settings. Two
 * whole queries to fetch two foreign keys, on the first scoped read of every
 * request that touches a site -- which is every tracking hit and every report.
 *
 * A join does the walk and the fetch together. What has to stay true is that it
 * answers the SAME thing the walk did, so these tests pin the answers as well as
 * the count: a faster query that resolves a different value is not an
 * optimisation.
 */
final class ProfileChainQueryCountTest extends TestCase
{
    private string $key;
    private string $siteId = '';
    private string $propertyId = '';
    private string $organizationId = '';

    protected function setUp(): void
    {
        $this->key = 'chain_probe_' . substr( md5( uniqid( '', true ) ), 0, 8 );

        if ( ! owa_test_db_available() ) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $site     = \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName();
        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName();

        $row = $db->get_row( sprintf(
            'SELECT s.site_id, s.property_id, p.organization_id FROM %s s'
          . ' INNER JOIN %s p ON p.id = s.property_id'
          . ' WHERE p.organization_id IS NOT NULL LIMIT 1',
            $site, $property ) );

        if ( $row ) {

            $this->siteId         = (string) $row['site_id'];
            $this->propertyId     = (string) $row['property_id'];
            $this->organizationId = (string) $row['organization_id'];
        }
    }

    protected function tearDown(): void
    {
        if ( ! $this->siteId ) {
            return;
        }

        foreach ( array(
            array( 'profile', $this->siteId ),
            array( 'property', $this->propertyId ),
            array( 'organization', $this->organizationId ),
        ) as $scope ) {

            \OWA\Core\CoreAPI::clearScopedSetting( $scope[0], $scope[1], 'base', $this->key );
        }
    }

    private function skipUnlessFullChain(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        if ( ! $this->organizationId ) {
            $this->markTestSkipped( 'Needs a Profile under a Property under an Organization.' );
        }
    }

    /**
     * Counts queries across a block. The counter is on the connection, which is
     * the only place that knows a query actually went to MySQL -- counting calls
     * to a method would count the ones the caches answer.
     */
    private function queriesDuring( callable $block ): int
    {
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $before = $db->num_queries;

        $block();

        return $db->num_queries - $before;
    }

    public function testAProfileResolvesInOneQueryAndThenNone(): void
    {
        $this->skipUnlessFullChain();

        \OWA\Core\CoreAPI::settingCacheFlush();

        $cold = $this->queriesDuring( function () {
            \OWA\Core\CoreAPI::getSetting( 'base', 'default_page', 'profile', $this->siteId );
        } );

        $this->assertSame( 1, $cold,
            'A Profile scope chain and its settings must come back in one query.' );

        $warm = $this->queriesDuring( function () {
            \OWA\Core\CoreAPI::getSetting( 'base', 'log_robots', 'profile', $this->siteId );
            \OWA\Core\CoreAPI::getSetting( 'base', 'p3p_policy', 'profile', $this->siteId );
        } );

        $this->assertSame( 0, $warm,
            'Later reads for the same Profile must be answered from the cache.' );
    }

    /** The join has to find the same ancestors the walk did. */
    public function testTheJoinedChainMatchesTheWalk(): void
    {
        $this->skipUnlessFullChain();

        \OWA\Core\CoreAPI::settingCacheFlush();

        \OWA\Core\CoreAPI::getSetting( 'base', 'default_page', 'profile', $this->siteId );

        $joined = \OWA\Core\CoreAPI::settingScopeChain( 'profile', $this->siteId );

        $this->assertSame( array(
            array( 'type' => 'profile',      'id' => $this->siteId ),
            array( 'type' => 'property',     'id' => $this->propertyId ),
            array( 'type' => 'organization', 'id' => $this->organizationId ),
        ), $joined,
            'The joined query built a different chain than walking the rows does.' );
    }

    /**
     * Rank, not row order. The join returns all three scopes' rows together, so
     * whether the nearest one wins is decided by the ORDER BY alone -- and every
     * value below has to lose to the one above it.
     */
    public function testTheNearestScopeStillWins(): void
    {
        $this->skipUnlessFullChain();

        \OWA\Core\CoreAPI::setScopedSetting( 'organization', $this->organizationId, 'base', $this->key, 'org' );
        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertSame( 'org',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ),
            'An Organization value must reach a Profile two levels down.' );

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', $this->key, 'prop' );
        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertSame( 'prop',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ) );

        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', $this->key, 'prof' );
        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertSame( 'prof',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ) );

        /* One query answered all of that, so the ordering is the join's doing. */
        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertSame( 1, $this->queriesDuring( function () {
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId );
        } ) );
    }

    /** Reading one level only still reads one level only. */
    public function testWithoutInheritanceOnlyTheProfilesOwnValueAnswers(): void
    {
        $this->skipUnlessFullChain();

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', $this->key, 'prop' );
        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertNull(
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId, false ),
            'A value inherited from the Property must not be reported as the Profile\'s own.' );
    }

    /**
     * A site_id with no Profile row. The join starts at the Profile, so it comes
     * back empty -- and the caller must still get the install answer rather than
     * an error or a null.
     */
    public function testAnUnknownProfileFallsBackToTheInstallValue(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertSame(
            \OWA\Core\CoreAPI::getSetting( 'base', 'default_page' ),
            \OWA\Core\CoreAPI::getSetting( 'base', 'default_page', 'profile', 'OWA-no-such-profile' ) );

        $this->assertSame(
            array( array( 'type' => 'profile', 'id' => 'OWA-no-such-profile' ) ),
            \OWA\Core\CoreAPI::settingScopeChain( 'profile', 'OWA-no-such-profile' ),
            'An unknown Profile must not produce a chain naming ancestors.' );
    }

    /**
     * Settings left behind by a deleted Profile.
     *
     * They are unreachable from a join that starts at owa_site, so the empty
     * result falls back to the generic query rather than answering "nothing
     * stored". An install carrying such rows saw those values before and has to
     * keep seeing them.
     */
    public function testSettingsOutlivingTheirProfileAreStillRead(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $orphan = 'OWA-orphan-' . substr( md5( uniqid( '', true ) ), 0, 8 );

        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $orphan, 'base', $this->key, 'outlived' );

        try {
            \OWA\Core\CoreAPI::settingCacheFlush();

            $this->assertSame( 'outlived',
                \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $orphan ),
                'A setting whose Profile no longer exists became unreadable.' );

            /*
             * And the fallback costs what the walk used to: the empty join, then
             * the generic read. The site lookup is not repeated, because an
             * empty result already tells us there are no ancestors.
             */
            \OWA\Core\CoreAPI::settingCacheFlush();

            $this->assertSame( 2, $this->queriesDuring( function () use ( $orphan ) {
                \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $orphan );
            } ), 'The fallback re-asked for a site row that is known not to exist.' );

        } finally {

            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $orphan, 'base', $this->key );
        }
    }
}
