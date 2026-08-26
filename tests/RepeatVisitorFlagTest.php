<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * `is_repeat_visitor` is false for a new visitor, not null.
 *
 * The derivation returned true for a returning visitor and fell off the end
 * for a new one, so the false case was NULL. Because the property is REQUIRED,
 * every new visitor's session stored NULL.
 *
 * The damage is in reporting rather than in tracking: NULL and 0 are two
 * distinct values for a two-state fact, so anything GROUPing on the column
 * gets three buckets -- and the dashboard's Repeat Visitors pie drew two
 * separate slices both labelled "No".
 */
final class RepeatVisitorFlagTest extends TestCase
{
    private function eventFor( bool $isNewVisitor )
    {
        $event = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'event' );
        $event->set( 'is_new_visitor', $isNewVisitor );

        return $event;
    }

    public function testANewVisitorIsNotARepeatVisitor(): void
    {
        $flag = Helpers::setRepeatVisitorFlag( null, $this->eventFor( true ) );

        $this->assertFalse( $flag );
        $this->assertNotNull( $flag,
            'null is a THIRD value for a two-state fact, and it groups as its own bucket' );
    }

    public function testAReturningVisitorIsARepeatVisitor(): void
    {
        $this->assertTrue( Helpers::setRepeatVisitorFlag( null, $this->eventFor( false ) ) );
    }

    /**
     * Both branches must return the same TYPE. A boolean and a null differ in
     * the database as 0 and NULL, which is the whole defect.
     */
    public function testBothBranchesReturnABoolean(): void
    {
        foreach ( array( true, false ) as $isNew ) {

            $this->assertIsBool( Helpers::setRepeatVisitorFlag( null, $this->eventFor( $isNew ) ) );
        }
    }

    /**
     * The property is REQUIRED, which is why a null return reached storage
     * rather than being skipped as an absent value.
     */
    public function testTheFlagIsARequiredDerivedProperty(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'modules/Base/Module.php' );

        $this->assertMatchesRegularExpression(
            "/'is_repeat_visitor'\s*=>\s*array\(\s*'required'\s*=>\s*true/",
            $src );
    }
}
