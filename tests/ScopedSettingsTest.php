<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Settings resolve down the hierarchy, and a stored false is a value.
 *
 * The blobs could not express an override. A serialized map says "not set" by
 * OMITTING a key, so absent and false are one statement -- and "inherit from my
 * Property" versus "override my Property to false" is exactly the distinction
 * an inheritance model exists to make.
 */
final class ScopedSettingsTest extends TestCase
{
    private string $key;
    private string $siteId = '';
    private string $propertyId = '';

    protected function setUp(): void
    {
        $this->key = 'scoped_probe_' . substr( md5( uniqid( '', true ) ), 0, 8 );

        if ( ! owa_test_db_available() ) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id, property_id' );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! empty( $row['property_id'] ) ) {

                $this->siteId     = $row['site_id'];
                $this->propertyId = $row['property_id'];
                break;
            }
        }
    }

    protected function tearDown(): void
    {
        if ( ! $this->siteId ) {
            return;
        }

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', $this->key );
        \OWA\Core\CoreAPI::clearScopedSetting( 'property', $this->propertyId, 'base', $this->key );
    }

    private function skipUnlessParented(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a Profile with a Property.' );
        }
    }

    /** Unscoped means exactly what it always meant. */
    public function testWithoutAScopeItIsTheInstallValue(): void
    {
        $this->assertSame(
            \OWA\Core\CoreAPI::getSetting( 'base', 'timezone' ),
            \OWA\Core\CoreAPI::getSetting( 'base', 'timezone', '', '' ),
            'An unscoped read changed meaning, which would move 200-odd call sites at once.' );
    }

    public function testAProfileInheritsFromItsProperty(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', $this->key, 'from-property' );

        $this->assertSame(
            'from-property',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ) );
    }

    /**
     * The case the store exists for.
     *
     * A Profile set to false, under a Property set to true. In a blob the false
     * is indistinguishable from "not set", so the Profile would inherit true
     * and the override would appear to save without taking.
     */
    public function testAProfileCanOverrideItsPropertyToFalse(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', $this->key, true );
        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', $this->key, false );

        $this->assertFalse(
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ),
            'The Profile inherited its Property instead of holding its own false -- which is '
            . 'the blob behaviour this store replaces.' );
    }

    /**
     * ...and it must NOT be pruned for matching the code default.
     *
     * persistSetting() drops an install value equal to the code default, so the
     * install keeps tracking code rather than pinning a stale copy. Applying
     * that rule at a scoped level would delete this row and silently hand the
     * Profile its parent's value back.
     */
    public function testAScopedValueIsNotPrunedForMatchingADefault(): void
    {
        $this->skipUnlessParented();

        /* log_robots defaults to false in code. */
        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', 'log_robots', true );
        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', 'log_robots', false );

        try {

            $this->assertFalse(
                \OWA\Core\CoreAPI::getSetting( 'base', 'log_robots', 'profile', $this->siteId ),
                'A scoped false equal to the code default was pruned, so the Profile fell '
                . 'back to its Property and the override did not take.' );

        } finally {

            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', 'log_robots' );
            \OWA\Core\CoreAPI::clearScopedSetting( 'property', $this->propertyId, 'base', 'log_robots' );
        }
    }

    /** Reading one level answers "does this scope own a value", not "what applies". */
    public function testWithoutInheritanceAnUnsetScopeAnswersNothing(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', $this->key, 'from-property' );

        $this->assertNull(
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId, false ),
            'A non-inheriting read returned an ancestor value, so a screen could not tell '
            . 'a value set here from one coming from above.' );
    }

    /** Clearing returns a scope to inheriting, which is the way back. */
    public function testClearingRestoresInheritance(): void
    {
        $this->skipUnlessParented();

        \OWA\Core\CoreAPI::setScopedSetting( 'property', $this->propertyId, 'base', $this->key, 'from-property' );
        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', $this->key, 'mine' );

        $this->assertSame( 'mine',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ) );

        \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', $this->key );

        $this->assertSame( 'from-property',
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ),
            'Once set, a scope could never go back to inheriting.' );
    }

    /**
     * A Profile with no Property still resolves.
     *
     * Unparented Profiles are real -- created before the hierarchy migration,
     * or by a path that assigns none -- so the walk skips missing ancestors
     * rather than assuming a full chain.
     */
    public function testAMissingAncestorIsSkippedNotFatal(): void
    {
        $chain = \OWA\Core\CoreAPI::settingScopeChain( 'profile', 'OWA-no-such-profile' );

        $this->assertSame(
            array( array( 'type' => 'profile', 'id' => 'OWA-no-such-profile' ) ), $chain,
            'A Profile with no Property produced a chain naming one anyway.' );
    }

    /** The site blobs are unpacked, not moved -- a rollback still reads them. */
    public function testTheMigrationCopiesRatherThanMoves(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'modules/Base/Update/Update022.php' );

        $this->assertStringNotContainsString(
            "set( 'settings'", $src,
            'The migration writes to owa_site.settings, so rolling back loses the values.' );

        /* Flat blob, so every key in it is a Base key by construction. */
        $planned = \OWA\Module\Base\Update\Update022::planForSite( array(
            'site_id'  => 'OWA-x',
            'settings' => serialize( array( 'anonymize_ips' => true ) ),
        ) );

        $this->assertSame( 'base', $planned[0]['module'] );
        $this->assertSame( 'anonymize_ips', $planned[0]['name'] );

        /* One unreadable blob must not stop every other site migrating. */
        $this->assertSame(
            array(),
            \OWA\Module\Base\Update\Update022::planForSite(
                array( 'site_id' => 'OWA-x', 'settings' => 'not-serialized-at-all' ) ) );
    }
    /**
     * Values are not all scalars.
     *
     * owa_site.settings holds nested arrays as well as scalars -- `goals` and
     * `goal_groups` are maps of goal definitions, fifteen deep on this install.
     * The value column is serialized rather than typed precisely so those
     * survive; typing it would have meant deciding a type per key and losing
     * the structured ones.
     */
    public function testANestedArrayValueSurvivesUnchanged(): void
    {
        $this->skipUnlessParented();

        $goals = array(
            1 => array( 'goal_name' => 'Signup',   'goal_type' => 'url_destination' ),
            2 => array( 'goal_name' => 'Purchase', 'goal_type' => 'url_destination' ),
        );

        \OWA\Core\CoreAPI::setScopedSetting( 'profile', $this->siteId, 'base', $this->key, $goals );

        $this->assertSame(
            $goals,
            \OWA\Core\CoreAPI::getSetting( 'base', $this->key, 'profile', $this->siteId ),
            'A structured value came back changed, so goals would not survive the store.' );
    }

    /** And the migration carries them across whole. */
    public function testTheMigrationCarriesStructuredValues(): void
    {
        $planned = \OWA\Module\Base\Update\Update022::planForSite( array(
            'site_id'  => 'OWA-x',
            'settings' => serialize( array(
                'goals' => array( 1 => array( 'goal_name' => 'Signup' ) ),
                'p3p_policy' => 'CAO PSA OUR',
            ) ),
        ) );

        $byName = array_column( $planned, 'value', 'name' );

        $this->assertSame( array( 1 => array( 'goal_name' => 'Signup' ) ), $byName['goals'] );
        $this->assertSame( 'CAO PSA OUR', $byName['p3p_policy'] );
    }
}
