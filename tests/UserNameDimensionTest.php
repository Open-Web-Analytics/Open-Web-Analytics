<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * userName is offered by the catalog but no entity can answer it.
 *
 * It is registered NORMALIZED against the seven fact entities, all of which
 * carry user_name denormalized on the row, and with no foreign key. A
 * normalized dimension is resolved by joining its own entity through a foreign
 * key, so with none to join on it relates to nothing -- and the report is
 * refused as an impossible combination after being offered in the picker.
 *
 * The value really is on the row: user_name is a registered tracking property
 * with required => true, so every event carries it (defaulting to the '(not
 * set)' sentinel), and it lands on all seven fact tables. Denormalized is what
 * it always should have been -- exactly how `date` is registered against the
 * same list.
 *
 * Its sibling userEmail is registered against base.visitor and so reads the
 * visitor's stored identity instead. The two therefore answer different
 * questions, which is deliberate and documented in GROUNDWORK.html: the fact
 * row records who the event was attributed to AT THE TIME, while the visitor
 * row is a lens on who we now know that visitor to be.
 */
final class UserNameDimensionTest extends TestCase
{
    private function manager()
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'resultSetManager' );
    }

    public function testAReportCanActuallyUseUserName(): void
    {
        /*
         * The user-facing bug. The dimension is listed by the picker, so it can
         * be chosen; compatibleEntities() then finds no table able to answer
         * for it, and the custom report builder refuses the set at save time.
         * An offered dimension that can never be used.
         */
        $entities = $this->manager()->compatibleEntities( array( 'visits' ), array( 'userName' ) );

        $this->assertNotEmpty(
            $entities,
            'No entity can answer visits by userName, so the report is refused -- yet the '
            . 'dimension is offered in the picker.' );

        $this->assertContains( 'base.session', $entities );
    }

    public function testItBehavesLikeTheOtherDimensionsOnTheSameTables(): void
    {
        /*
         * Compared against a sibling rather than asserted in isolation, so this
         * says "userName is not special" rather than restating the fix.
         */
        $manager = $this->manager();

        $reference = $manager->compatibleEntities( array( 'visits' ), array( 'browserType' ) );
        $subject   = $manager->compatibleEntities( array( 'visits' ), array( 'userName' ) );

        $this->assertSame(
            $reference, $subject,
            'userName sits on the same fact tables as browserType and should be answerable by '
            . 'exactly the same ones.' );
    }

    public function testItResolvesAgainstTheEntityBeingQueried(): void
    {
        /*
         * The mechanism behind the symptom. Registered against seven entities
         * but stored flat, the registry kept only the last -- so it resolved to
         * base.commerce_line_item_fact whatever was being reported on, and the
         * join was built from an empty foreign key.
         */
        $manager = $this->manager();

        $this->assertTrue(
            (bool) $manager->isDimensionRelated( 'userName', 'base.session' ),
            'userName must be reachable from the session table, whose rows carry user_name.' );

        $this->assertTrue(
            (bool) $manager->isDimensionRelated( 'userName', 'base.request' ) );
    }

    public function testItIsRegisteredDenormalisedLikeTheColumnItReads(): void
    {
        /*
         * user_name is a column ON each fact row, not a key pointing at another
         * table, so denormalized is what describes it. Asserted through the
         * registry rather than by reading Module.php, so this tracks what the
         * application does.
         */
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $this->assertNotNull(
            $service->getDenormalizedDimension( 'userName', 'base.session' ),
            'userName should resolve as a denormalized dimension of base.session.' );

        $entry = $service->getDenormalizedDimension( 'userName', 'base.request' );

        $this->assertSame( 'user_name', $entry['column'] );
        $this->assertSame( 'base.request', $entry['entity'] );
    }
}
