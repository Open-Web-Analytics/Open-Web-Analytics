<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Goals are rows now, and the row is shaped for v2 goal events.
 *
 * Twenty numbered slots lived inside ONE serialized array -- all twenty present
 * whether used or not -- in a single settings row per Profile. A live install
 * here held fifteen entries in 2,135 bytes to describe one real goal. That
 * shape cannot be queried or indexed, loses one of two concurrent edits
 * wholesale, and put a RECORD inside a settings blob.
 *
 * The columns are what v2 needs, not what 1.x had, so the v2 migration reads
 * this table rather than reinterpreting it: an author names a goal event, gives
 * it an event type and a condition, and the server materialises a row whose
 * event_type IS that name (PLAN.html §7.14).
 */
final class GoalEventDefinitionStorageTest extends TestCase
{
    private array $created = [];

    /** A real Profile with a Property -- goal events hang off the Property. */
    private string $siteId = '';

    /** Its Property. Resolved once, and asserted, for the reason below. */
    private string $propertyId = '';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        /*
         * A REAL Profile, not an invented id.
         *
         * Goal events belong to the Property, so a made-up site id resolves to
         * no Property and the manager correctly answers with nothing -- these
         * tests would then pass by measuring an empty list.
         */
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

        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a Profile with a Property.' );
        }

        /*
         * Held and asserted rather than re-resolved per query.
         *
         * Db::where() DROPS a clause whose value is empty instead of matching
         * nothing, so a query filtered on an empty property id silently widens
         * to every Property on the install. A test that does that does not fail
         * -- it counts other people's rows and reports a number that looks like
         * a bug in the code under test.
         */
        $this->assertNotSame( '', $this->propertyId );
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $id ) {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $e->delete( $id );
        }

        foreach ( $this->createdConditions as $id ) {

            $e = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );
            $e->delete( $id );
        }

        $this->createdConditions = [];

        $this->created = [];
    }

    /* ---------------- conditions ---------------- */

    /**
     * The comparisons, including the ones 1.x could not express.
     *
     * 1.x offered exact, begins and regex against one URL. There was no way to
     * say "not this page", "contains", or anything numeric -- so a goal event
     * describing "a purchase over 50" could not be written at all.
     */
    public function testTheComparisons(): void
    {
        $ge = '\OWA\Module\Base\Entity\GoalEventDefinition';

        $this->assertTrue(  $ge::compare( '/thanks', 'exact', '/thanks' ) );
        $this->assertFalse( $ge::compare( '/thanks', 'exact', '/other' ) );

        $this->assertTrue(  $ge::compare( '/thanks', 'not', '/other' ) );
        $this->assertFalse( $ge::compare( '/thanks', 'not', '/thanks' ) );

        $this->assertTrue(  $ge::compare( '/a/thanks', 'contains', 'thanks' ) );
        $this->assertTrue(  $ge::compare( '/thanks', 'begins', '/thanks' ) );
        $this->assertFalse( $ge::compare( '/a/thanks', 'begins', 'thanks' ) );

        $this->assertTrue(  $ge::compare( '/thanks/2', 'regex', 'thanks' ) );

        $this->assertTrue(  $ge::compare( '60', 'gt', '50' ) );
        $this->assertFalse( $ge::compare( '40', 'gt', '50' ) );
        $this->assertTrue(  $ge::compare( '40', 'lt', '50' ) );
    }

    /**
     * A match at position zero is a match.
     *
     * strpos() answers 0 there, which is falsy -- so a truthy test reads
     * "/thanks contains /" as no match. That exact trap has been found in this
     * codebase before.
     */
    public function testContainsMatchesAtPositionZero(): void
    {
        $this->assertTrue(
            \OWA\Module\Base\Entity\GoalEventDefinition::compare( '/thanks', 'contains', '/' ) );
    }

    /**
     * Greater/less than are NUMERIC, and answer no when either side is not.
     *
     * PHP would happily compare two strings and return something, but "greater
     * than" on a page URL is not a question anyone asked, and a silent
     * lexicographic answer is worse than no match.
     */
    public function testNumericComparisonsRefuseNonNumbers(): void
    {
        $ge = '\OWA\Module\Base\Entity\GoalEventDefinition';

        $this->assertFalse( $ge::compare( '/pricing', 'gt', '50' ) );
        $this->assertFalse( $ge::compare( '60', 'gt', 'fifty' ) );
    }

    /**
     * A malformed pattern does not match, and is suppressed at compare time.
     *
     * Suppressed there because the alternative is a warning per tracked event.
     * It is REFUSED at save time instead -- see GoalEventDefinitionSave -- so nobody
     * stores a pattern that can never match.
     *
     * The handler respects error_reporting(), which @ sets to 0: a handler that
     * throws regardless would report the suppression working as a failure.
     */
    public function testABrokenRegexDoesNotMatchAndIsSuppressed(): void
    {
        $seen = false;

        set_error_handler(
            static function ( $no, $str ) use ( &$seen ) {

                if ( ! ( error_reporting() & $no ) ) {

                    return true;   // suppressed at the call site
                }

                $seen = true;

                return true;
            } );

        try {
            $this->assertFalse(
                \OWA\Module\Base\Entity\GoalEventDefinition::compare( '/thanks', 'regex', '(' ) );

            $this->assertFalse( $seen,
                'A broken pattern warns on every tracked event.' );

        } finally {
            restore_error_handler();
        }
    }

    /** And the form refuses it, rather than storing something inert. */
    public function testABrokenRegexIsRefusedOnSave(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/GoalEventDefinitionSave.php' );

        $this->assertStringContainsString( 'The regular expression is not valid.', $src,
            'A pattern that cannot compile can be saved, and then matches nothing '
            . 'for ever without saying so.' );
    }

    /**
     * An @ in a regex condition is part of the pattern (#909).
     *
     * The pattern is delimited by @, so an email address in it used to end it
     * early: it never matched, and the save form called it invalid.
     */
    public function testAnAtSignInARegexIsPartOfThePattern(): void
    {
        $ge = \OWA\Module\Base\Entity\GoalEventDefinition::class;

        $this->assertTrue(  $ge::compare( '/signup?email=user@example.com', 'regex', 'user@example\.com' ) );
        $this->assertFalse( $ge::compare( '/signup?email=user.example.com', 'regex', 'user@example\.com' ) );
        $this->assertTrue(  $ge::compare( 'a@b', 'regex', '@' ) );
        $this->assertTrue(  $ge::compare( 'x@@y', 'regex', 'x@@y' ) );

        // Already escaped by the author: left as written.
        $this->assertTrue(  $ge::compare( 'user@example', 'regex', 'user\@example' ) );
        // An escaped backslash, then a bare @.
        $this->assertTrue(  $ge::compare( 'a\@b', 'regex', 'a\\\\@b' ) );

        $this->assertSame( '@user\@example@i', $ge::regexPattern( 'user@example' ) );
        $this->assertSame( '@user\@example@i', $ge::regexPattern( 'user\@example' ) );
    }

    /** ...and the form accepts it, while still refusing a pattern that cannot compile. */
    public function testTheSaveFormAcceptsAnAtSign(): void
    {
        $this->assertNotContains( 'The regular expression is not valid.', $this->saveErrors( 'user@example\.com' ) );
        $this->assertContains( 'The regular expression is not valid.', $this->saveErrors( '(' ) );
    }

    /* ---------------- the name is an event name ---------------- */

    /** Update075's renaming: other characters to one underscore, a letter first, 40 at most. */
    public function testANameIsMadeFromFreeText(): void
    {
        $from = array( \OWA\Module\Base\Entity\GoalEventDefinition::class, 'nameFrom' );

        $this->assertSame( 'Bookshop_com_Buy', $from( 'Bookshop.com-Buy' ) );
        $this->assertSame( 'Signup_Completed', $from( '  Signup  Completed! ' ) );
        $this->assertSame( 'goal_2024_launch', $from( '2024 launch' ) );
        $this->assertSame( 40, strlen( $from( str_repeat( 'a', 60 ) ) ) );
    }

    /**
     * The names Update075 renames to, which the v1 migration also stores
     * goal events under: unique within a Property, case-blind, in id order,
     * and a Property's goal events alone give what all of them do.
     */
    public function testTheNamesGoalEventDefinitionsAreGivenAreUniqueWithinTheirProperty(): void
    {
        $rows = array(
            array( 'id' => '11', 'property_id' => '1', 'name' => 'Contact form' ),
            array( 'id' => '12', 'property_id' => '1', 'name' => 'contact_form' ),
            array( 'id' => '13', 'property_id' => '1', 'name' => 'Contact-form' ),
            array( 'id' => '14', 'property_id' => '1', 'name' => 'click' ),
            array( 'id' => '15', 'property_id' => '1', 'name' => 'Fine_name' ),
            array( 'id' => '21', 'property_id' => '2', 'name' => 'Contact form' ),
        );

        $names = \OWA\Module\Base\Entity\GoalEventDefinition::eventNames( $rows );

        $this->assertSame( array( 11 => 'Contact_form_2', 13 => 'Contact_form_3', 14 => 'click_2', 21 => 'Contact_form' ), $names,
            'a usable name keeps it; another Property is not a collision' );

        $this->assertSame( array( 21 => 'Contact_form' ),
            \OWA\Module\Base\Entity\GoalEventDefinition::eventNames( array( $rows[5] ) ) );
        $this->assertSame( array_intersect_key( $names, array( 11 => 1, 13 => 1, 14 => 1 ) ),
            \OWA\Module\Base\Entity\GoalEventDefinition::eventNames( array_slice( $rows, 0, 5 ) ) );

        foreach ( $rows as $i => $row ) {
            $rows[ $i ]['name'] = $names[ $row['id'] ] ?? $row['name'];
        }

        $this->assertSame( array(), \OWA\Module\Base\Entity\GoalEventDefinition::eventNames( $rows ), 'once renamed, nothing is' );
    }

    public function testANameOWACollectsOrThatIsNotAnEventNameIsRefused(): void
    {
        $problem = array( \OWA\Module\Base\Entity\GoalEventDefinition::class, 'nameProblem' );

        $this->assertSame( '', $problem( 'Signup_Completed' ) );
        $this->assertNotSame( '', $problem( 'click' ), 'its rows would count as clicks' );
        $this->assertNotSame( '', $problem( 'session_start' ) );
        $this->assertNotSame( '', $problem( 'has-a-hyphen' ) );
        $this->assertNotSame( '', $problem( 'Signup completed' ) );
        $this->assertNotSame( '', $problem( str_repeat( 'a', 41 ) ) );
        $this->assertNotSame( '', $problem( '' ) );
    }

    public function testTheSaveRefusesANameThatIsNotAnEventNameOrThatOWACollects(): void
    {
        $this->assertContains( 'A name starts with a letter and has up to 40 letters, digits and underscores.',
            $this->saveErrors( 'x', array( 'name' => 'Signup by email' ) ) );
        $this->assertContains( 'click is an event OWA collects.', $this->saveErrors( 'x', array( 'name' => 'click' ) ) );
        $this->assertSame( array(), array_filter( $this->saveErrors( 'x', array( 'name' => 'Unused_name_75' ) ),
            static function ( $m ) { return strpos( $m, 'name' ) !== false; } ) );
    }

    /** Case-blind, like the column: Taken and taken would group as one event. */
    public function testTheSaveRefusesANameAnotherGoalEventDefinitionOfThePropertyHas(): void
    {
        $id = $this->storedGoal( 'Taken_name_75' );

        try {
            $this->assertContains( 'Another goal event definition is called Taken_name_75.',
                $this->saveErrors( 'x', array( 'name' => 'Taken_name_75' ) ) );
            $this->assertContains( 'Another goal event definition is called taken_NAME_75.',
                $this->saveErrors( 'x', array( 'name' => 'taken_NAME_75' ) ) );
            $this->assertNotContains( 'Another goal event definition is called Taken_name_75.',
                $this->saveErrors( 'x', array( 'name' => 'Taken_name_75', 'goalEventDefinitionId' => $id ) ), 'itself' );
        } finally {
            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->delete( $id );
        }
    }

    /** A saved goal event definition keeps its name: its goal events already carry it. */
    public function testASavedGoalEventDefinitionKeepsItsName(): void
    {
        $id = $this->storedGoal( 'Kept_name_75' );

        try {
            $save = new \OWA\Module\Base\Controller\GoalEventDefinitionSave( array(
                'propertyId' => $this->propertyId, 'goalEventDefinitionId' => $id, 'name' => 'Ignored_name_75',
                'value' => '4.00', 'triggerEvent' => 'page_view',
                'conditionProperty' => array( 'page_path' ), 'conditionOperator' => array( 'exact' ),
                'conditionValue' => array( '/after' ) ) );
            $save->action();

            $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
                'SELECT name, value FROM owa_goal_event_definition WHERE id = ?', array( $id ) );

            $this->assertSame( 'Kept_name_75', $row['name'] );
            $this->assertSame( 400, (int) $row['value'], 'the rest of the save went through' );
        } finally {
            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->delete( $id );
        }
    }

    /** What may trigger a goal event: OWA's goal triggers, or a site's own event. */
    public function testATriggerIsAGoalTriggerOrAnEventOfTheSitesOwn(): void
    {
        $problem = array( \OWA\Module\Base\Entity\GoalEventDefinition::class, 'triggerProblem' );
        $custom  = array( \OWA\Module\Base\Entity\GoalEventDefinition::class, 'isCustomTrigger' );

        foreach ( array( 'page_view', 'click', 'first_visit' ) as $ok ) {
            $this->assertSame( '', $problem( $ok ), $ok );
            $this->assertFalse( $custom( $ok ), "$ok is OWA's" );
        }

        $this->assertSame( '', $problem( 'newsletter_signup' ) );
        $this->assertTrue( $custom( 'newsletter_signup' ) );

        // A module's event is refused while the module is active, which this process may not have loaded.
        $modules = array_values( array_diff( \OWA\Core\CoreAPI::trackingEventTypes(),
            \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames() ) );

        foreach ( array_merge( array( 'exception', 'owa_signup', 'Owa_signup', '9lives', 'two words',
                \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM, str_repeat( 'a', 41 ) ), $modules ) as $refused ) {
            $this->assertNotSame( '', $problem( $refused ), $refused );
            $this->assertFalse( $custom( $refused ), $refused );
        }

        $this->assertFalse( $custom( '' ) );
    }

    /** The builder's script names the picker's custom value as the server does. */
    public function testTheBuilderKnowsTheCustomTriggerValue(): void
    {
        $source = (string) file_get_contents( OWA_DIR . 'modules/Base/src/reporting/v1/owa.goalbuilder.js' );

        $this->assertMatchesRegularExpression( '/export const CUSTOM_TRIGGER = ' . preg_quote(
            "'" . \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM . "';", '/' ) . '/', $source );
    }

    /** A goal on a site's own event with no condition counts every one; on OWA's, none. */
    public function testWithNoConditionsOnlyACustomEventMatches(): void
    {
        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        $goal->set( 'trigger_event_type', 'newsletter_signup' );
        $this->assertTrue( $goal->matchesRow( array( 'event_type' => 'newsletter_signup' ), array() ) );
        $this->assertFalse( $goal->matchesRow( array( 'event_type' => 'page_view' ), array() ), 'the trigger still gates' );

        $goal->set( 'trigger_event_type', 'page_view' );
        $this->assertFalse( $goal->matchesRow( array( 'event_type' => 'page_view' ), array() ),
            'every page view is not a goal' );
    }

    /** The validation messages for a save with these params, over a valid base. */
    private function triggerErrors( array $params ): array
    {
        $save = new \OWA\Module\Base\Controller\GoalEventDefinitionSave( $params + array(
            'propertyId' => $this->propertyId,
            'name'       => 'Signed_up_75',
        ) );
        $save->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $save );
        $validator->doValidations();

        return array_map( static function ( $msg ) {

            return is_array( $msg ) ? (string) ( $msg['message'] ?? '' ) : (string) $msg;
        }, array_values( (array) $validator->getErrorMsgs() ) );
    }

    /** A custom event is chosen in the picker or typed beside it, and needs no condition. */
    public function testACustomEventTriggerIsSavedWithoutAConditionAndNothingElseIs(): void
    {
        $this->assertSame( array(), $this->triggerErrors( array( 'triggerEvent' => 'newsletter_signup' ) ) );
        $this->assertSame( array(), $this->triggerErrors( array(
            'triggerEvent' => \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM,
            'customTriggerEvent' => ' newsletter_signup ' ) ) );

        $this->assertContains( 'Enter a value to compare against.',
            $this->triggerErrors( array( 'triggerEvent' => 'page_view' ) ), 'OWA\'s events still need one' );

        $this->assertContains( 'Enter the name of the custom event.', $this->triggerErrors( array(
            'triggerEvent' => \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM, 'customTriggerEvent' => '' ) ) );

        $this->assertContains( 'A goal event definition cannot be triggered by exception.',
            $this->triggerErrors( array( 'triggerEvent' => \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM,
                'customTriggerEvent' => 'exception' ) ) );

        $this->assertContains( 'A goal event definition cannot have the name of the event that triggers it, signed_up_75.',
            $this->triggerErrors( array( 'triggerEvent' => 'signed_up_75' ) ) );
    }

    /** The save stores the typed name as the trigger, and no conditions. */
    public function testATypedCustomEventIsStoredAsTheTrigger(): void
    {
        $save = new \OWA\Module\Base\Controller\GoalEventDefinitionSave( array(
            'propertyId' => $this->propertyId, 'name' => 'Signed_up_typed_75', 'value' => '0',
            'triggerEvent' => \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM,
            'customTriggerEvent' => 'newsletter_signup',
            'conditionProperty' => array( 'page_path' ), 'conditionOperator' => array( 'exact' ),
            'conditionValue' => array( '' ) ) );
        $save->action();

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT id, trigger_event_type FROM owa_goal_event_definition WHERE property_id = ? AND name = ?',
            array( $this->propertyId, 'Signed_up_typed_75' ) );

        try {
            $this->assertSame( 'newsletter_signup', $row['trigger_event_type'] );

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $goal->load( $row['id'] );
            $this->assertSame( array(), (array) $goal->loadConditions(), 'the empty row is not a condition' );
        } finally {
            \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->delete( $row['id'] );
        }
    }

    /** A goal event of the fixture Property, stored directly. */
    private function storedGoal( string $name ): string
    {
        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
        $id   = $goal->generateId( 'goal_event:name-probe:' . uniqid( '', true ) );
        $goal->setProperties( array( 'id' => $id, 'property_id' => $this->propertyId, 'name' => $name,
            'trigger_event_type' => 'page_view', 'is_active' => 0 ) );
        $goal->create();

        return $id;
    }

    /** The validation messages GoalEventDefinitionSave raises for one regex condition. */
    private function saveErrors( string $pattern, array $params = array() ): array
    {
        // A real Property and a name, so validation reaches the conditions.
        $save = new \OWA\Module\Base\Controller\GoalEventDefinitionSave( $params + array(
            'propertyId'        => $this->propertyId,
            'name'              => 'Signup_by_email',
            'conditionOperator' => array( 'regex' ),
            'conditionValue'    => array( $pattern ),
        ) );
        $save->validate();

        $v = new \ReflectionProperty( \OWA\Core\Controller::class, 'v' );
        $v->setAccessible( true );
        $validator = $v->getValue( $save );
        $validator->doValidations();

        return array_map( static function ( $msg ) {

            return is_array( $msg ) ? (string) ( $msg['message'] ?? '' ) : (string) $msg;
        }, array_values( (array) $validator->getErrorMsgs() ) );
    }

    /** Several conditions, combined with all or any. */
    public function testConditionsCombineWithAllOrAny(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'page_path', 'begins', '/checkout' ),
            array( 'revenue',   'gt',     '50' ),
        ) );

        $over  = $this->row( array( 'page_path' => '/checkout/done', 'revenue' => '60' ) );
        $under = $this->row( array( 'page_path' => '/checkout/done', 'revenue' => '40' ) );

        $this->assertTrue(  $goalEventDefinition->matchesRow( $over ) );
        $this->assertFalse( $goalEventDefinition->matchesRow( $under ),
            'Under ALL, one failing condition must fail the whole rule.' );

        $goalEventDefinition->set( 'condition_match', 'any' );

        $this->assertTrue( $goalEventDefinition->matchesRow( $under ),
            'Under ANY, one matching condition is enough.' );
    }

    /**
     * A goal event with NO conditions matches nothing.
     *
     * An empty rule is vacuously true, and treating it that way would count
     * every event on the site. This install already had a goal that could never
     * fire; one that fires for everything is the worse direction to be wrong in.
     */
    public function testAGoalEventDefinitionWithNoConditionsMatchesNothing(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array() );

        $this->assertFalse( $goalEventDefinition->matchesRow(
            $this->row( array( 'page_path' => '/anything' ) ) ) );
    }

    /**
     * THE TRIGGER GATES THE MATCH.
     *
     * trigger_event_type has been stored since Update025 and was read by
     * NOTHING, so a goal declared on a page view was evaluated against every
     * event on the site. It went unnoticed because a condition on a page column
     * finds that column NULL on a click -- but a condition on host, or on
     * device_type, would have fired on scrolls, clicks and session_start alike,
     * counting several goal events for one visit to one page.
     */
    public function testAGoalOnlyMatchesItsTriggerEventType(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'host', 'exact', 'example.com' ),
        ) );

        $goalEventDefinition->set( 'trigger_event_type', 'page_view' );

        $this->assertTrue( $goalEventDefinition->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'host' => 'example.com' ) ) ) );

        $this->assertFalse( $goalEventDefinition->matchesRow( $this->row( array(
            'event_type' => 'click', 'host' => 'example.com' ) ) ),
            'A goal triggered on a page view counted a click as a goal event.' );
    }

    /**
     * AN EMPTY TRIGGER MATCHES NOTHING.
     *
     * It used to mean every event type. No writer produces one -- Update025
     * gives every migrated goal event a trigger and GoalEventDefinitionSave defaults one --
     * and "every event" would mark a page view and the session_start and
     * first_visit materialized beside it: three goal events for one visit.
     */
    public function testAnEmptyTriggerMatchesNothing(): void
    {
        $conditions = array( array( 'host', 'exact', 'example.com' ) );

        $triggered = $this->makeGoalEventDefinitionWithConditions( $conditions );

        $this->assertTrue( $triggered->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'host' => 'example.com' ) ) ),
            'with a trigger the same conditions match, so the refusals below are the empty trigger' );

        // Never set, since an entity ignores '' on a string column.
        $untriggered = $this->makeGoalEventDefinitionWithConditions( $conditions, null );

        $this->assertSame( '', (string) $untriggered->get( 'trigger_event_type' ) );

        foreach ( array( 'page_view', 'click', 'session_start' ) as $type ) {

            $this->assertFalse( $untriggered->matchesRow( $this->row( array(
                'event_type' => $type, 'host' => 'example.com' ) ) ),
                "An empty trigger matched $type." );
        }
    }

    /**
     * A COLUMN THE ROW DOES NOT CARRY CANNOT ANSWER, so it does not match --
     * whatever the operator.
     *
     * The operator is the point. Handing NULL to compare() makes `not` TRUE for
     * every row that simply has no value there: a condition meant to exclude one
     * medium would mark every event with no medium at all, which is most of
     * them. Both spellings of absence are checked because the row has both -- a
     * key that is not there at all, and a column that is there and NULL, which
     * is what tagged_medium is on every event but the landing one.
     */
    public function testAnAbsentOrNullColumnDoesNotMatchEvenUnderNot(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'tagged_medium', 'not', 'organic' ),
        ) );

        $this->assertFalse( $goalEventDefinition->matchesRow( $this->row( array(
            'event_type' => 'page_view' ) ) ),
            'A row with no tagged_medium key matched "medium is not organic".' );

        $this->assertFalse( $goalEventDefinition->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'tagged_medium' => null ) ) ),
            'A row whose tagged_medium is NULL matched "medium is not organic".' );

        $this->assertTrue( $goalEventDefinition->matchesRow( $this->row( array(
            'event_type' => 'page_view', 'tagged_medium' => 'cpc' ) ) ),
            'and a row that does carry a different medium must still match' );
    }

    /**
     * THE ROW-ONLY COLUMNS ARE MATCHABLE, which is the reason marking moved.
     *
     * device_type is derived in the handler from the user-agent parse and the
     * tagged_* columns are transcribed there; neither exists as an event
     * property. Matching from the event could not see them at all, so "a signup
     * from mobile" was not expressible -- and a goal declared on it matched
     * nothing without saying so.
     */
    public function testAConditionCanTestAColumnThatOnlyExistsOnTheRow(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'device_type',   'exact', 'mobile' ),
            array( 'tagged_medium', 'exact', 'organic' ),
        ) );

        $this->assertTrue( $goalEventDefinition->matchesRow( $this->row( array(
            'device_type' => 'mobile', 'tagged_medium' => 'organic' ) ) ) );

        $this->assertFalse( $goalEventDefinition->matchesRow( $this->row( array(
            'device_type' => 'desktop', 'tagged_medium' => 'organic' ) ) ) );
    }

    /** A raw row, with the keys a condition is allowed to name. */
    private function row( array $columns )
    {
        return array_merge( array(
            'event_type' => 'page_view',
            'site_id'    => $this->siteId,
        ), $columns );
    }

    /** A goal event carrying the given conditions, cleaned up afterwards. */
    private function makeGoalEventDefinitionWithConditions( array $conditions, ?string $trigger = 'page_view' )
    {
        $id = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )
            ->generateId( 'goal_event:cond-probe:' . uniqid( '', true ) );

        $this->created[] = $id;

        $goalEventDefinition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
        $goalEventDefinition->set( 'id', $id );
        $goalEventDefinition->set( 'property_id', $this->propertyId );
        $goalEventDefinition->set( 'name', 'Condition_probe' );
        if ( $trigger !== null ) {
            $goalEventDefinition->set( 'trigger_event_type', $trigger );
        }

        $goalEventDefinition->set( 'is_active', 1 );
        $goalEventDefinition->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $goalEventDefinition->create();

        $n = 0;

        foreach ( $conditions as $c ) {

            $n++;

            $row = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );
            $row->set( 'id', $row->generateId( 'goal_event_condition:' . $id . ':' . $n ) );
            $row->set( 'goal_event_definition_id', $id );
            $row->set( 'sort_order', $n );
            $row->set( 'condition_property', $c[0] );
            $row->set( 'condition_operator', $c[1] );
            $row->set( 'condition_value', $c[2] );
            $row->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $row->create();

            $this->createdConditions[] = $row->get( 'id' );
        }

        return $goalEventDefinition;
    }

    /** @var array ids to clean up */
    private array $createdConditions = [];

    /* ---------------- deleting ---------------- */

    /**
     * DELETING A GOAL EVENT DELETES ITS CONDITIONS.
     *
     * It did not. Entity::delete() removes one row from one table and nothing
     * overrode it, so every goal event ever deleted left its conditions behind
     * with nothing able to reach them: a condition row carries no site_id and no
     * property_id, and is reachable only through its goal event.
     *
     * The count on the test install when it was found: 31 of 40 condition rows
     * pointed at a goal event that no longer existed, left by e2e fixtures that
     * create a goal and delete it. The reason nobody noticed is that an
     * unreachable row is also an invisible one -- until one of those 64-bit ids
     * collides with a new goal event's and a condition somebody deleted starts
     * deciding what is marked.
     */
    public function testDeletingAGoalEventDefinitionDeletesItsConditions(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'page_uri', 'begins', '/thanks' ),
            array( 'medium', 'exact', 'organic-search' ),
        ) );

        $id = $goalEventDefinition->get( 'id' );

        $this->assertCount( 2, $this->conditionRowsFor( $id ),
            'the fixture did not store its conditions, so this would pass on nothing' );

        $goalEventDefinition->delete( $id );

        $this->assertSame( array(), $this->conditionRowsFor( $id ),
            'The goal event is gone and its conditions are still there -- unreachable, '
            . 'because nothing but the goal event points at them.' );
    }

    /**
     * DELETE BY A COLUMN OTHER THAN id CASCADES AS WELL.
     *
     * Entity::delete( $value, $col ) accepts any column, so a caller can remove
     * every goal event belonging to a Property in one call. A cascade that only
     * understood the id would leak on exactly the delete that removes the most
     * rows.
     */
    public function testDeletingByPropertyIdCascades(): void
    {
        $goalEventDefinition = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'page_uri', 'exact', '/one' ),
        ) );

        $id = $goalEventDefinition->get( 'id' );

        $this->assertCount( 1, $this->conditionRowsFor( $id ) );

        /*
         * The Property really does own it -- makeGoalEventDefinitionWithConditions sets
         * property_id -- so this is the live shape and not an invented column.
         */
        $goalEventDefinition->delete( $this->propertyId, 'property_id' );

        $this->assertSame( array(), $this->conditionRowsFor( $id ),
            'Deleting by property_id left the conditions behind.' );
    }

    /**
     * Update048 deletes the orphans that accumulated before the cascade existed,
     * and ONLY those.
     *
     * The second half is the part worth asserting: a cleanup that also removed a
     * live definition's conditions would silently stop that definition marking, and
     * the install would look tidy.
     *
     * On the tables under the names they had before Update076, which is what an
     * upgrade meets when it reaches Update048.
     */
    public function testTheCleanupTakesOrphansAndLeavesLiveConditions(): void
    {
        $live = $this->makeGoalEventDefinitionWithConditions( array(
            array( 'page_uri', 'exact', '/live' ),
        ) );

        $liveId = $live->get( 'id' );

        // An orphan: a condition whose goal_event_definition_id names nothing.
        $orphan = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );
        $orphanId = $orphan->generateId( 'goal_event_condition:orphan-probe:' . uniqid( '', true ) );
        $orphan->set( 'id', $orphanId );
        $orphan->set( 'goal_event_definition_id', '9' . substr( (string) $orphanId, 1 ) );
        $orphan->set( 'sort_order', 1 );
        $orphan->set( 'condition_property', 'page_uri' );
        $orphan->set( 'condition_operator', 'exact' );
        $orphan->set( 'condition_value', '/orphan' );
        $orphan->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $orphan->create();

        $this->createdConditions[] = $orphanId;

        $renames = new \OWA\Module\Base\Update\Update076();
        $this->assertTrue( $renames->down(), 'the goal tables under their names before Update076' );

        try {

            $update = new \OWA\Module\Base\Update\Update048;

            $this->assertTrue( $update->up(), 'the cleanup reported failure' );

            $this->assertCount( 1, $this->conditionRowsFor( $liveId, true ),
                'The cleanup deleted a live definition\'s condition, which stops it marking.' );

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->selectFrom( \OWA\Core\CoreAPI::entityFactory(
                'base.goal_event_definition_condition' )->namedBefore076()->getTableName() );
            $db->selectColumn( 'id' );
            $db->where( 'id', $orphanId );

            $this->assertSame( array(), (array) $db->getAllRows(),
                'The orphan survived the cleanup.' );

            // Idempotent: a second run finds nothing left to do.
            $this->assertTrue( $update->up(), 'a second run of the cleanup failed' );

            $this->assertCount( 1, $this->conditionRowsFor( $liveId, true ),
                'The second run deleted the live condition.' );

        } finally {

            $this->assertTrue( $renames->up(), 'the goal tables renamed back' );
        }
    }

    /**
     * The condition rows pointing at one definition, by query, not by cache.
     *
     * @param bool $before076  on the names before Update076 (namedBefore076())
     */
    private function conditionRowsFor( $definitionId, bool $before076 = false )
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );

        if ( $before076 ) {
            $entity->namedBefore076();
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( $before076
            ? \OWA\Module\Base\Entity\GoalEventDefinitionCondition::DEFINITION_COLUMN_BEFORE_076
            : \OWA\Module\Base\Entity\GoalEventDefinitionCondition::DEFINITION_COLUMN, $definitionId );

        return (array) $db->getAllRows();
    }

    /* ---------------- funnels ---------------- */
    /* ---------------- money ---------------- */

    /**
     * Hundredths of what the author typed (GoalEventDefinition's value column).
     *
     * round() before the cast: (int) truncates, so 0.29 through float
     * representation becomes 28 -- the classic money-in-floats error, and one
     * that under-reports every time rather than averaging out.
     */
    public function testDecimalValuesBecomeWholeCents(): void
    {
        $ke = '\OWA\Module\Base\Entity\GoalEventDefinition';

        $this->assertSame( 200, $ke::decimalToCents( '2' ) );
        $this->assertSame( 250, $ke::decimalToCents( '2.50' ) );
        $this->assertSame( 29,  $ke::decimalToCents( '0.29' ),
            'A value truncated instead of rounded, which under-reports every time.' );
        $this->assertSame( 0,   $ke::decimalToCents( '' ) );
    }

    /** A value nobody can convert is reported, not silently zeroed. */
    public function testANonNumericValueIsDistinguishableFromZero(): void
    {
        $ke = '\OWA\Module\Base\Entity\GoalEventDefinition';

        $this->assertNull( $ke::decimalToCents( 'about five pounds' ),
            'A non-numeric value is indistinguishable from a real zero, so the migration '
            . 'cannot report that someone typed something it could not keep.' );

        $this->assertSame( 0, $ke::decimalToCents( '0' ) );
    }

    /** And back, because 1.x reporting reads a decimal string. */
    public function testCentsRoundTripBackToTheDecimalFormReportingExpects(): void
    {
        $ke = '\OWA\Module\Base\Entity\GoalEventDefinition';

        $this->assertSame( '2.00', $ke::centsToDecimal( 200 ) );
        $this->assertSame( '0.29', $ke::centsToDecimal( 29 ) );
    }

    /* ---------------- counting ---------------- */

    /*
     * The count mode was clamped to once per session on every save, because a
     * 1.x goal was a column on the session row and could not record a second
     * match. Goal events are rows now and marking honours both modes
     * (GoalMarkingTest), so the save stores what was chosen, and the form and
     * the list are GoalEventDefinitionCountModeTest's.
     */

    /** The save stores the submitted count mode through countMode(), and nothing clamps it. */
    public function testTheSaveStoresTheCountModeItWasGiven(): void
    {
        $body = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/GoalEventDefinitionSave.php' );

        $write = substr( $body, (int) strpos( $body, "set( 'count_mode'" ) );
        $write = substr( $write, 0, (int) strpos( $write, ';' ) );

        // Single-quoted: in double quotes $this would interpolate and the needle be something else.
        $this->assertStringContainsString( 'self::countMode( $this->getParam( \'countMode\' ) )', $write,
            'The save does not store the count mode it was given.' );

        $this->assertSame( 1, substr_count( $body, "set( 'count_mode'" ), 'one write, not a clamp after it' );
    }

    /** ...and a definition saved with either one reads back as it. */
    public function testEitherCountModeIsStoredAndReadBack(): void
    {
        foreach ( array( \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_EVENT,
                         \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION ) as $mode ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $id     = $entity->generateId( 'goal_event:count-mode-probe:' . uniqid( '', true ) );

            $this->created[] = $id;

            $entity->set( 'id', $id );
            $entity->set( 'property_id', $this->propertyId );
            $entity->set( 'name', 'Count_mode_probe' );
            $entity->set( 'trigger_event_type', 'page_view' );
            $entity->set( 'count_mode', \OWA\Module\Base\Controller\GoalEventDefinitionSave::countMode( $mode ) );
            $entity->create();

            $read = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $read->load( $id );

            $this->assertSame( $mode, $read->countMode() );
        }
    }
}
