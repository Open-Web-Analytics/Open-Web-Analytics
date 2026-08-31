<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Update\Update020;

/**
 * The shape an existing install turns into.
 *
 * plan() is pure, so every case here runs with no database -- which matters
 * because the interesting ones are awkward to seed and trivial to describe:
 * two sites that are really one website, a site with no name, a site with no
 * domain at all.
 *
 * The rule underneath all of it: a site's site_id is carried across unchanged.
 * Every fact row references it, so a migration that reissues identifiers is a
 * migration that orphans data.
 */
final class Update020PlanTest extends TestCase
{
    public function testEveryProfileKeepsItsExistingIdentifier(): void
    {
        $plan = Update020::plan( array(
            array( 'site_id' => '5ababd603b22780302dd8d83498e5172', 'domain' => 'example.com' ),
            array( 'site_id' => 'OWA-7f3a91c4e85b402d',             'domain' => 'other.example' ),
        ) );

        $ids = array_column( $plan['profiles'], 'site_id' );

        sort( $ids );

        $this->assertSame(
            array( '5ababd603b22780302dd8d83498e5172', 'OWA-7f3a91c4e85b402d' ),
            $ids,
            'A migrated profile must keep the identifier its fact rows reference, whether that '
            . 'is a legacy md5 or a minted one.' );
    }

    public function testSitesDifferingOnlyBySchemeBecomeOnePropertyWithTwoProfiles(): void
    {
        /*
         * The case the hierarchy exists to express, and one that could not be
         * created until identity stopped being md5( domain ) -- which is
         * exactly why installs have it: http:// and https:// looked like two
         * unrelated websites.
         */
        $plan = Update020::plan( array(
            array( 'site_id' => 'a', 'domain' => 'http://mysite.com',  'name' => 'My Site' ),
            array( 'site_id' => 'b', 'domain' => 'https://mysite.com', 'name' => 'My Site (SSL)' ),
        ) );

        $this->assertCount( 1, $plan['properties'], 'One website, one property.' );
        $this->assertCount( 2, $plan['profiles'],  'Both measurements survive as profiles.' );

        $this->assertSame( 'mysite.com', $plan['properties'][0]['domain'] );

        $names = array_column( $plan['profiles'], 'name' );

        $this->assertSame(
            array( 'Observation Profile 1', 'Observation Profile 2' ), $names,
            'Profiles are numbered within their property, so the second is distinguishable.' );
    }

    public function testCaseAndTrailingSlashDoNotSplitAWebsite(): void
    {
        $plan = Update020::plan( array(
            array( 'site_id' => 'a', 'domain' => 'https://MySite.com/' ),
            array( 'site_id' => 'b', 'domain' => 'mysite.com' ),
        ) );

        $this->assertCount(
            1, $plan['properties'],
            'Hosts are case-insensitive and a trailing slash is not part of one.' );
    }

    public function testDifferentDomainsStayDifferentPropertiesEvenWhenNamedAlike(): void
    {
        /*
         * The domain is the key, not the name. Two unrelated websites an
         * operator happened to call the same thing must not be merged -- that
         * would join their reporting irreversibly.
         */
        $plan = Update020::plan( array(
            array( 'site_id' => 'a', 'domain' => 'one.example', 'name' => 'Blog' ),
            array( 'site_id' => 'b', 'domain' => 'two.example', 'name' => 'Blog' ),
        ) );

        $this->assertCount( 2, $plan['properties'] );
    }

    public function testASiteWithNoDomainGetsItsOwnProperty(): void
    {
        /*
         * Nothing says an undomained site is the same website as any other, so
         * they are kept apart. Grouping them would merge unrelated reporting on
         * the strength of a missing value -- and 'owa-test-site' is a real
         * domainless value in the wild.
         */
        $plan = Update020::plan( array(
            array( 'site_id' => 'a', 'domain' => '' ),
            array( 'site_id' => 'b', 'domain' => '' ),
        ) );

        $this->assertCount( 2, $plan['properties'] );
    }

    public function testAPropertyWithNoNameFallsBackToItsDomain(): void
    {
        $plan = Update020::plan( array(
            array( 'site_id' => 'a', 'domain' => 'https://unnamed.example', 'name' => '' ),
        ) );

        $this->assertSame(
            'unnamed.example', $plan['properties'][0]['name'],
            'An unnamed property is unusable in a picker, and the domain is the only other thing '
            . 'known about it.' );
    }

    public function testASiteWithNoIdentifierIsSkippedRatherThanIssuedOne(): void
    {
        /*
         * The identifier is what fact rows reference. A row without one has
         * nothing to carry forward and nothing to attach data to, so inventing
         * one would silently create a site that never existed.
         */
        $plan = Update020::plan( array(
            array( 'site_id' => '',  'domain' => 'ghost.example' ),
            array( 'site_id' => 'a', 'domain' => 'real.example' ),
        ) );

        $this->assertCount( 1, $plan['profiles'] );
        $this->assertSame( 'a', $plan['profiles'][0]['site_id'] );
    }

    public function testAnEmptyInstallStillGetsItsOrganization(): void
    {
        $plan = Update020::plan( array() );

        $this->assertSame( 'My Organization', $plan['organization']['name'] );
        $this->assertSame( array(), $plan['properties'] );
        $this->assertSame( array(), $plan['profiles'] );
    }

    public function testEveryProfileNamesAPropertyThatExists(): void
    {
        /*
         * A dangling reference here would leave a profile unreachable from the
         * hierarchy after the migration ran -- visible to nothing, and hard to
         * explain later.
         */
        $plan = Update020::plan( array(
            array( 'site_id' => 'a', 'domain' => 'one.example' ),
            array( 'site_id' => 'b', 'domain' => 'one.example' ),
            array( 'site_id' => 'c', 'domain' => '' ),
        ) );

        $keys = array_column( $plan['properties'], 'key' );

        foreach ( $plan['profiles'] as $profile ) {

            $this->assertContains( $profile['property_key'], $keys );
        }
    }
}
