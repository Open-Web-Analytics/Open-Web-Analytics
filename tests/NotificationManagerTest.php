<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\NotificationManager as NM;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Notifications, and who they are for.
 *
 * The parsing half is tested against the shapes GitHub actually returns --
 * including the ones that are not releases at all -- because that is the half
 * with no database in it and the half most likely to be wrong.
 */
final class NotificationManagerTest extends TestCase
{
    public function testAReleaseBecomesANotification(): void
    {
        $items = NM::fromGithubReleases( array(
            (object) array(
                'id'           => 42,
                'name'         => '1.12.0',
                'body'         => 'notes',
                'html_url'     => 'https://example.test/r/42',
                'published_at' => '2026-08-21T10:00:00Z',
            ),
        ) );

        $this->assertCount( 1, $items );
        $this->assertSame( '42', $items[0]['source_key'] );
        $this->assertSame( '1.12.0', $items[0]['title'] );
        $this->assertSame( 'https://example.test/r/42', $items[0]['url'] );
        $this->assertSame( strtotime( '2026-08-21T10:00:00Z' ), $items[0]['published_at'] );
    }

    /**
     * The endpoint answers with an OBJECT carrying a `message` when it is
     * unhappy -- rate limited, or the repository moved. Iterating that yields
     * strings, and storing one would put "API rate limit exceeded" in front of
     * every operator as though it were an announcement.
     */
    public function testAnErrorPayloadProducesNothing(): void
    {
        $this->assertSame( array(), NM::fromGithubReleases(
            (object) array( 'message' => 'API rate limit exceeded' ) ) );
    }

    /** A draft is not an announcement yet. */
    public function testADraftIsSkipped(): void
    {
        $this->assertSame( array(), NM::fromGithubReleases( array(
            (object) array( 'id' => 7, 'name' => 'wip', 'draft' => true ) ) ) );
    }

    /**
     * The id is what makes the fetch idempotent, so an item without one cannot
     * be stored -- it would arrive again on every run.
     */
    public function testAReleaseWithNoIdIsSkipped(): void
    {
        $this->assertSame( array(), NM::fromGithubReleases( array(
            (object) array( 'name' => 'nameless' ) ) ) );
    }

    /** A release can be published with no name; the tag is what people call it. */
    public function testTheTagStandsInForAMissingName(): void
    {
        $items = NM::fromGithubReleases( array(
            (object) array( 'id' => 9, 'tag_name' => 'v1.2.3' ) ) );

        $this->assertSame( 'v1.2.3', $items[0]['title'] );
    }

    // ---------------------------------------------------------------
    // Storage: audience and dismissal
    // ---------------------------------------------------------------

    private function item( string $key, string $title = 'T' ): array
    {
        return array( array( 'source_key' => $key, 'title' => $title,
                             'body' => '', 'url' => '', 'published_at' => 1000 ) );
    }

    /** Every source this class writes under starts with this. */
    private const SOURCE_PREFIX = 'unittest_';

    private function source(): string
    {
        // Unique per test run so these rows cannot collide with the real
        // github_release rows on the install this runs against.
        return self::SOURCE_PREFIX . substr( md5( __CLASS__ . getmypid() ), 0, 8 );
    }

    /**
     * Delete every row this class created.
     *
     * These tests write to a real table that a real UI reads. Left behind, the
     * rows accumulate run after run and show up in the notification panel of
     * whoever next logs into this install -- which is exactly how the badge
     * ended up reading 25 with 66 undismissed behind it.
     */
    public static function tearDownAfterClass(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_notification' );
        $db->selectColumn( '*' );

        $ids = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( strpos( (string) ( $row['source'] ?? '' ), self::SOURCE_PREFIX ) === 0 ) {

                $ids[] = $row['id'];
            }
        }

        foreach ( $ids as $id ) {

            \OWA\Core\CoreAPI::entityFactory( 'base.notification' )->delete( $id );

            // ...and the dismissals that pointed at them, or the join table
            // grows orphans forever.
            $d = \OWA\Core\CoreAPI::dbSingleton();
            $d->deleteFrom( 'owa_notification_dismissal' );
            $d->where( 'notification_id', $id );
            $d->executeQuery();
        }
    }

    public function testTheSameThingIsStoredOnce(): void
    {
        $source = $this->source() . '_once';

        $this->assertSame( 1, NM::record( $this->item( 'a' ), $source ) );
        $this->assertSame( 0, NM::record( $this->item( 'a' ), $source ),
            'the job sees the same releases every run; storing again would duplicate them' );
    }

    /**
     * The same underlying thing can legitimately be addressed to several
     * people, so dedupe asks "does THIS user have it", not "does anyone".
     */
    /**
     * A brand new install must not be greeted with a wall of history.
     *
     * The endpoint returns a page of releases, and an install that has never
     * fetched would take all of them -- a badge reading 5 and a panel of
     * announcements the operator has already lived through.
     */
    public function testTheFirstFetchTakesOnlyTheNewestFew(): void
    {
        $source = $this->source() . '_first';

        $five = array();

        for ( $i = 1; $i <= 5; $i++ ) {
            $five[] = array( 'source_key' => "k$i", 'title' => "R$i",
                             'body' => '', 'url' => '', 'published_at' => 1000 + $i );
        }

        $this->assertSame( \OWA\Module\Base\Classes\NotificationManager::INITIAL_LIMIT,
            NM::record( $five, $source ) );
    }

    /**
     * ...and the history stays out. Capping only the first fetch would merely
     * postpone the backfill: on the second run rows exist, the cap no longer
     * applies, and everything skipped arrives anyway. This is the assertion
     * that a one-time cap passes and a watermark does not.
     */
    public function testTheSkippedHistoryNeverArrivesLater(): void
    {
        $source = $this->source() . '_hist';

        $five = array();

        for ( $i = 1; $i <= 5; $i++ ) {
            $five[] = array( 'source_key' => "k$i", 'title' => "R$i",
                             'body' => '', 'url' => '', 'published_at' => 1000 + $i );
        }

        NM::record( $five, $source );

        $this->assertSame( 0, NM::record( $five, $source ),
            'the releases skipped on the first fetch must not be imported on the second' );
    }

    /** A release published after the watermark is new, and is stored. */
    public function testSomethingGenuinelyNewerIsStored(): void
    {
        $source = $this->source() . '_new';

        NM::record( array( array( 'source_key' => 'old', 'title' => 'old',
                                  'body' => '', 'url' => '', 'published_at' => 1000 ) ), $source );

        $this->assertSame( 1, NM::record( array(
            array( 'source_key' => 'old', 'title' => 'old', 'body' => '', 'url' => '', 'published_at' => 1000 ),
            array( 'source_key' => 'new', 'title' => 'new', 'body' => '', 'url' => '', 'published_at' => 5000 ),
        ), $source ) );
    }

    public function testTheSameThingCanBeAddressedToDifferentPeople(): void
    {
        $source = $this->source() . '_aud';

        $this->assertSame( 1, NM::record( $this->item( 'a' ), $source, 'alice' ) );
        $this->assertSame( 1, NM::record( $this->item( 'a' ), $source, 'bob' ) );
        $this->assertSame( 0, NM::record( $this->item( 'a' ), $source, 'alice' ) );
    }

    public function testAUserSeesGlobalsAndTheirOwnButNotOtherPeoples(): void
    {
        $source = $this->source() . '_see';

        NM::record( $this->item( 'g', 'global' ), $source );
        NM::record( $this->item( 'a', 'for alice' ), $source, 'nm-alice' );
        NM::record( $this->item( 'b', 'for bob' ), $source, 'nm-bob' );

        $titles = array_column( NM::undismissedFor( 'nm-alice', 1000 ), 'title' );

        $this->assertContains( 'global',    $titles );
        $this->assertContains( 'for alice', $titles );
        $this->assertNotContains( 'for bob', $titles,
            'a notification addressed to one user must not be readable by another' );
    }

    /**
     * Dismissing a GLOBAL notification is per user. If it were a flag on the
     * notification, one person clearing their badge would clear everyone's.
     */
    public function testDismissingAGlobalOnlyAffectsThatUser(): void
    {
        $source = $this->source() . '_dis';

        NM::record( $this->item( 'shared', 'shared thing' ), $source );

        $before = NM::unreadCountFor( 'nm-carol' );
        $mine   = null;

        foreach ( NM::undismissedFor( 'nm-carol', 1000 ) as $row ) {
            if ( $row['title'] === 'shared thing' ) { $mine = $row['id']; }
        }

        $this->assertNotNull( $mine, 'the fixture notification must be visible to begin with' );

        $otherBefore = NM::unreadCountFor( 'nm-dave' );

        NM::dismiss( $mine, 'nm-carol' );

        $this->assertSame( $before - 1, NM::unreadCountFor( 'nm-carol' ) );
        $this->assertSame( $otherBefore, NM::unreadCountFor( 'nm-dave' ),
            'one user dismissing a global must not clear it for anyone else' );
    }

    /** Two tabs, or a double click, must not create two rows to reconcile. */
    public function testDismissingTwiceIsTheSameAsOnce(): void
    {
        $source = $this->source() . '_twice';

        NM::record( $this->item( 'd2', 'twice' ), $source, 'nm-erin' );

        $id = null;

        foreach ( NM::undismissedFor( 'nm-erin', 1000 ) as $row ) {
            if ( $row['title'] === 'twice' ) { $id = $row['id']; }
        }

        $this->assertNotNull( $id );

        $before = NM::unreadCountFor( 'nm-erin' );

        $this->assertTrue( (bool) NM::dismiss( $id, 'nm-erin' ) );
        $this->assertTrue( (bool) NM::dismiss( $id, 'nm-erin' ) );

        // One less, not zero: this user also sees every GLOBAL notification on
        // the install, which is the point of the audience rule.
        $this->assertSame( $before - 1, NM::unreadCountFor( 'nm-erin' ) );
        // By ID, not by title: this table persists across runs, so an earlier
        // run's row with the same title is still there and would make a
        // title-based assertion fail for a reason that has nothing to do with
        // dismissal.
        $this->assertNotContains( $id,
            array_column( NM::undismissedFor( 'nm-erin', 1000 ), 'id' ) );
    }

    /** Nobody is not a user: a dismissal needs both halves. */
    public function testDismissNeedsANotificationAndAUser(): void
    {
        $this->assertFalse( NM::dismiss( '', 'someone' ) );
        $this->assertFalse( NM::dismiss( 'something', '' ) );
    }
}
