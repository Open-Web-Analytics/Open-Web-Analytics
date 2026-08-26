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

    // ---------------------------------------------------------------
    // Excerpts -- pure, so no database
    // ---------------------------------------------------------------

    /**
     * Release notes are markdown. Dropping them raw into a notification panel
     * shows people `## Overview` and `[text](url)`.
     */
    public function testTheExcerptStripsTheMarkdownThatActuallyAppears(): void
    {
        $out = NM::excerpt( "## Overview\n\n- a [link](https://x.test) and **bold** `code`" );

        $this->assertStringNotContainsString( '#', $out );
        $this->assertStringNotContainsString( '](', $out );
        $this->assertStringNotContainsString( '**', $out );
        $this->assertStringContainsString( 'link', $out, 'the link TEXT survives; only the target goes' );
    }

    public function testAShortBodyIsLeftAlone(): void
    {
        $this->assertSame( 'Just a few words.', NM::excerpt( 'Just a few words.' ) );
    }

    public function testALongBodyIsCutToTheWordLimit(): void
    {
        $out = NM::excerpt( implode( ' ', array_fill( 0, 200, 'word' ) ) );

        // The words, plus the ellipsis glued to the last one.
        $this->assertCount( NM::EXCERPT_WORDS, explode( ' ', $out ) );
        $this->assertStringEndsWith( "\xE2\x80\xA6", $out );
    }

    /**
     * A pathological body must not become a pathological row. The column is
     * TEXT and would happily take 40kB.
     */
    public function testAnExcerptIsBoundedInCharactersToo(): void
    {
        $out = NM::excerpt( implode( ' ', array_fill( 0, NM::EXCERPT_WORDS, str_repeat( 'x', 500 ) ) ) );

        $this->assertLessThanOrEqual( NM::EXCERPT_MAX_CHARS, mb_strlen( $out ) );
    }

    /** Cutting UTF-8 by bytes can end halfway through a character. */
    public function testCuttingMultibyteTextLeavesValidUtf8(): void
    {
        $out = NM::excerpt( implode( ' ', array_fill( 0, 400, 'ほげ' ) ) );

        $this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
    }

    public function testAnEmptyBodyGivesAnEmptyExcerpt(): void
    {
        $this->assertSame( '', NM::excerpt( '' ) );
        $this->assertSame( '', NM::excerpt( "\n\n  \n" ) );
    }

    /**
     * The tests above this line are pure -- decoded JSON in, items out -- and
     * must keep running everywhere. Everything below writes rows, and CI runs
     * configless with no MySQL, so those skip rather than fail on a database
     * that was never going to be there.
     */
    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable; skipping notification storage test.' );
        }
    }

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
        if ( ! owa_test_db_available() ) {
            return;
        }

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

            // ...and the per-user state that pointed at them, or the join
            // table grows orphans forever.
            $d = \OWA\Core\CoreAPI::dbSingleton();
            $d->deleteFrom( 'owa_notification_state' );
            $d->where( 'notification_id', $id );
            $d->executeQuery();
        }
    }

    public function testTheSameThingIsStoredOnce(): void
    {
        $this->requireDb();

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
        $this->requireDb();

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
        $this->requireDb();

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
        $this->requireDb();

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
        $this->requireDb();

        $source = $this->source() . '_aud';

        $this->assertSame( 1, NM::record( $this->item( 'a' ), $source, 'alice' ) );
        $this->assertSame( 1, NM::record( $this->item( 'a' ), $source, 'bob' ) );
        $this->assertSame( 0, NM::record( $this->item( 'a' ), $source, 'alice' ) );
    }

    public function testAUserSeesGlobalsAndTheirOwnButNotOtherPeoples(): void
    {
        $this->requireDb();

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
        $this->requireDb();

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

    /**
     * Reading is not dismissing. This is the distinction the whole state table
     * exists for, and collapsing them is what the first version did.
     */
    public function testReadingKeepsItInTheListButOutOfTheCount(): void
    {
        $this->requireDb();

        $source = $this->source() . '_read';

        NM::record( $this->item( 'r1', 'read me' ), $source, 'nm-frank' );

        $before = NM::unreadCountFor( 'nm-frank' );
        $listed = count( NM::undismissedFor( 'nm-frank', 1000 ) );

        $id = null;

        foreach ( NM::undismissedFor( 'nm-frank', 1000 ) as $row ) {
            if ( $row['title'] === 'read me' ) { $id = $row['id']; }
        }

        $this->assertNotNull( $id );
        NM::markRead( $id, 'nm-frank' );

        $this->assertSame( $before - 1, NM::unreadCountFor( 'nm-frank' ),
            'reading clears it from the badge' );
        $this->assertSame( $listed, count( NM::undismissedFor( 'nm-frank', 1000 ) ),
            'and leaves it on screen -- that is the whole point' );

        foreach ( NM::undismissedFor( 'nm-frank', 1000 ) as $row ) {
            if ( $row['id'] === $id ) {
                $this->assertTrue( $row['read'], 'the row carries its own read state' );
            }
        }
    }

    /**
     * Dismissing marks read too. Something you have finished with cannot still
     * be waiting to be looked at.
     */
    public function testDismissingAlsoMarksRead(): void
    {
        $this->requireDb();

        $source = $this->source() . '_dr';

        NM::record( $this->item( 'd1', 'both' ), $source, 'nm-gail' );

        $id = null;

        foreach ( NM::undismissedFor( 'nm-gail', 1000 ) as $row ) {
            if ( $row['title'] === 'both' ) { $id = $row['id']; }
        }

        $this->assertNotNull( $id );

        $unreadBefore = NM::unreadCountFor( 'nm-gail' );

        NM::dismiss( $id, 'nm-gail' );

        // One less unread and one less listed -- NOT zero: this user also sees
        // every global notification on the install, which is the audience rule
        // working.
        $this->assertSame( $unreadBefore - 1, NM::unreadCountFor( 'nm-gail' ) );
        $this->assertNotContains( $id, array_column( NM::undismissedFor( 'nm-gail', 1000 ), 'id' ) );
    }

    /** Re-reading must not move the timestamp of when it was first read. */
    public function testMarkingReadTwiceIsIdempotent(): void
    {
        $this->requireDb();

        $source = $this->source() . '_rr';

        NM::record( $this->item( 'r2', 'twice read' ), $source, 'nm-hank' );

        $id = null;

        foreach ( NM::undismissedFor( 'nm-hank', 1000 ) as $row ) {
            if ( $row['title'] === 'twice read' ) { $id = $row['id']; }
        }

        $this->assertNotNull( $id );

        $unreadBefore = NM::unreadCountFor( 'nm-hank' );
        $listedBefore = count( NM::undismissedFor( 'nm-hank', 1000 ) );

        $this->assertTrue( (bool) NM::markRead( $id, 'nm-hank' ) );
        $this->assertTrue( (bool) NM::markRead( $id, 'nm-hank' ) );

        // Reading twice is reading once, and neither reading removed it.
        $this->assertSame( $unreadBefore - 1, NM::unreadCountFor( 'nm-hank' ) );
        $this->assertCount( $listedBefore, NM::undismissedFor( 'nm-hank', 1000 ) );
    }

    /** Reading is per user, like dismissing. */
    public function testReadingIsPerUser(): void
    {
        $this->requireDb();

        $source = $this->source() . '_pu';

        NM::record( $this->item( 'shared2', 'shared read' ), $source );

        $id = null;

        foreach ( NM::undismissedFor( 'nm-ivy', 1000 ) as $row ) {
            if ( $row['title'] === 'shared read' ) { $id = $row['id']; }
        }

        $otherBefore = NM::unreadCountFor( 'nm-jack' );

        NM::markRead( $id, 'nm-ivy' );

        $this->assertSame( $otherBefore, NM::unreadCountFor( 'nm-jack' ),
            'one user reading a global must not clear it for anyone else' );
    }

    /** Two tabs, or a double click, must not create two rows to reconcile. */
    public function testDismissingTwiceIsTheSameAsOnce(): void
    {
        $this->requireDb();

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
