<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\GoalVocabulary as Vocab;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * One vocabulary for goal conditions: the columns of the stored row.
 *
 * There used to be three answers to "what can a condition name". The builder
 * offered every client and server PROPERTY name; marking matched against the
 * tracking event, so it read property names too; and GoalEventPredicate kept a
 * hand-written map of four v1 names to four v1 columns. None of the three agreed
 * with the row that actually gets written, which is why every goal declaration in
 * the field named something the row does not have.
 */
final class GoalVocabularyTest extends TestCase
{
    /**
     * EVERY RAW COLUMN IS ACCOUNTED FOR: either it is named after its own
     * property, or SOURCE says which property it comes from, or EXCLUDED says
     * why a condition may not name it.
     *
     * This is the anti-drift test and the reason SOURCE is a map rather than
     * something derived. A column added to the row with no entry anywhere would
     * otherwise become silently unconditionable -- present in the table, absent
     * from the builder, and nobody would notice until someone asked for a goal on
     * it.
     */
    public function testEveryRawColumnIsAccountedFor(): void
    {
        $declared = $this->declaredProperties();

        $this->assertGreaterThan( 80, count( $declared ),
            'the property registry is not being read, so this would pass vacuously' );

        $orphans = [];

        foreach ( $this->rawColumns() as $column ) {

            if ( array_key_exists( $column, Vocab::EXCLUDED ) ) {
                continue;
            }

            $source = Vocab::SOURCE[ $column ] ?? $column;

            if ( ! isset( $declared[ $source ] ) ) {
                $orphans[ $column ] = $source;
            }
        }

        $this->assertSame( [], $orphans,
            "These raw columns come from no declared property, so they can never be "
            . "offered for any event type. Add them to GoalVocabulary::SOURCE (naming "
            . "the property they derive from) or to EXCLUDED (with the reason):\n"
            . print_r( $orphans, true ) );
    }

    /** ...and nothing in SOURCE names a column that does not exist. */
    public function testTheSourceMapOnlyNamesRealColumns(): void
    {
        $columns = $this->rawColumns();

        foreach ( array_keys( Vocab::SOURCE ) as $column ) {

            $this->assertContains( $column, $columns,
                "GoalVocabulary::SOURCE maps $column, which is not a column of "
                . 'owa_event_raw -- so the entry does nothing.' );
        }
    }

    /* ---------------- scoped by event ---------------- */

    /**
     * A condition is offered for an event only if that event carries it.
     *
     * The old builder offered every property for every goal, so "the clicked
     * element's id" was offered on a page view -- a goal that cannot fire,
     * indistinguishable on the screen from one that can.
     */
    public function testAConditionIsScopedToWhatTheEventCarries(): void
    {
        $click     = Vocab::columnsForEvent( 'click' );
        $pageView  = Vocab::columnsForEvent( 'page_view' );
        $purchase  = Vocab::columnsForEvent( 'purchase' );

        $this->assertArrayHasKey( 'element_id', $click );
        $this->assertArrayNotHasKey( 'element_id', $pageView,
            'a page view carries no clicked element' );

        $this->assertArrayHasKey( 'revenue', $purchase );
        $this->assertArrayNotHasKey( 'revenue', $pageView,
            'a page view has no order total' );

        $this->assertArrayHasKey( 'tagged_medium', $pageView );
        $this->assertArrayNotHasKey( 'tagged_medium', $click,
            'the tags ride the landing beacon, not a click' );
    }

    /**
     * THE ROW-ONLY COLUMNS ARE OFFERED, on every event, which is the reason any
     * of this moved.
     *
     * device_type and the other five readings of the user agent exist only on the
     * row -- the handler derives them from the one parse -- so matching against
     * the tracking event could not see them and the builder could not offer them.
     * They are available on every event type because the REQUEST carries the
     * agent, not the beacon.
     */
    public function testTheUserAgentReadingsAreOfferedOnEveryEvent(): void
    {
        foreach ( Helpers::eventNames() as $event ) {

            $columns = Vocab::columnsForEvent( $event );

            foreach ( array( 'device_type', 'device_brand', 'browser_version',
                             'os_version', 'raw_ua' ) as $column ) {

                $this->assertArrayHasKey( $column, $columns,
                    "$column is not offered for $event, though the request carries "
                    . 'the user agent for every event there is.' );
            }
        }
    }

    /** The cut-up page readings are offered wherever the location is. */
    public function testThePathAndQueryAreOfferedWhereverTheLocationIs(): void
    {
        $columns = Vocab::columnsForEvent( 'page_view' );

        foreach ( array( 'page_location', 'page_path', 'page_query' ) as $column ) {
            $this->assertArrayHasKey( $column, $columns );
        }
    }

    /* ---------------- what is refused ---------------- */

    /**
     * The excluded columns are not offered for any event, and each one says why.
     *
     * The reasons are the point: this is where the judgement about what a goal IS
     * gets written down -- an identity hash is not a behaviour, a date is what a
     * report is bounded by, and a condition reading is_goal_event would make
     * marking depend on marking.
     */
    public function testTheExcludedColumnsAreOfferedNowhereAndEachHasAReason(): void
    {
        foreach ( Vocab::EXCLUDED as $column => $reason ) {

            $this->assertNotSame( '', trim( (string) $reason ),
                "$column is excluded with no reason given" );

            $this->assertFalse( Vocab::has( $column ),
                "$column is excluded and still offered" );

            foreach ( array( 'page_view', 'click', 'purchase' ) as $event ) {

                $this->assertArrayNotHasKey( $column, Vocab::columnsForEvent( $event ) );
            }
        }
    }

    /* ---------------- the legacy map ---------------- */

    /**
     * The v1 names every declaration in the field actually uses.
     *
     * Measured on both installs here before the migration: every condition named
     * page_uri or medium. page_uri was a v1 DERIVATION that has since been
     * deleted outright, so it maps to the column the row cuts from the location;
     * medium maps to tagged_medium, because the raw row records what the landing
     * URL claimed and the classified answer is the cube's.
     */
    public function testTheLegacyNamesMapToColumns(): void
    {
        $this->assertSame( 'page_path',     Vocab::columnFor( 'page_uri' ) );
        $this->assertSame( 'page_location', Vocab::columnFor( 'page_url' ) );
        $this->assertSame( 'tagged_medium', Vocab::columnFor( 'medium' ) );
        $this->assertSame( 'tagged_source', Vocab::columnFor( 'source' ) );
    }

    /** A property whose column is a rename resolves through the same map. */
    public function testARenamedPropertyResolvesToItsColumn(): void
    {
        $this->assertSame( 'element_id', Vocab::columnFor( 'dom_element_id' ) );
        $this->assertSame( 'region',     Vocab::columnFor( 'state' ) );
        $this->assertSame( 'revenue',    Vocab::columnFor( 'ct_total' ) );
    }

    /** A name with no column at all answers null rather than a guess. */
    public function testAnUntranslatableNameAnswersNull(): void
    {
        /*
         * page_type was a v1 document classification and the old builder offered
         * it. There is no v2 column for it, so the migration reports the
         * condition and switches its goal off rather than inventing a meaning.
         */
        $this->assertNull( Vocab::columnFor( 'page_type' ) );
        $this->assertNull( Vocab::columnFor( 'is_robot' ) );
        $this->assertNull( Vocab::columnFor( '' ) );
    }

    /** Idempotent: a column maps to itself, so the migration can run twice. */
    public function testAColumnMapsToItself(): void
    {
        foreach ( array( 'page_path', 'tagged_medium', 'device_type', 'host' ) as $column ) {
            $this->assertSame( $column, Vocab::columnFor( $column ) );
        }
    }

    /* ---------------- the event vocabulary ---------------- */

    /**
     * The event names come from the property registry, not a second list.
     *
     * A name is in the vocabulary because some property is declared for it, so
     * there is nothing to keep in step. The two markers the SERVER raises are in
     * it -- they carry properties of their own -- and a v1 type that is not an
     * event at all declares none and is absent.
     */
    public function testTheEventNamesComeFromTheRegistry(): void
    {
        $names = Helpers::eventNames();

        foreach ( array( 'page_view', 'click', 'purchase', 'session_start',
                         'first_visit', 'scroll' ) as $expected ) {

            $this->assertContains( $expected, $names );
        }

        foreach ( array( 'dom.stream', 'base.page_request', 'base.feed_request' ) as $v1 ) {

            $this->assertNotContains( $v1, $names,
                "$v1 is a v1 event type and must not be offered as a trigger" );
        }
    }

    /** @return array<string,bool> */
    private function declaredProperties(): array
    {
        $out = [];

        foreach ( array( Helpers::requestProperties(), Helpers::clientProperties(),
                         Helpers::serverProperties() ) as $group ) {

            foreach ( array_keys( (array) $group ) as $name ) {
                $out[ $name ] = true;
            }
        }

        return $out;
    }

    /** @return string[] */
    private function rawColumns(): array
    {
        return array_keys( (array) \OWA\Core\CoreAPI::entityFactory(
            'base.event_raw' )->getProperties() );
    }
}
