<?php

use PHPUnit\Framework\TestCase;

/**
 * Which grants a submission of the site-access form actually changes.
 *
 * The old form was a `<select multiple>` posting the complete desired set, and
 * the controller replaced the whole grant list with whatever arrived:
 *
 *     if ( $this->getParam('allowed_users') ) { updateAssignedUserIds( ... ); }
 *     else                                    { updateAssignedUserIds( array() ); }
 *
 * A multi-select posts *nothing* when nothing is selected, so "deselect all", a
 * stray plain click (which collapses a nine-user selection down to one), a
 * truncated POST and a JavaScript error are indistinguishable at the server --
 * and every one of them revoked access for every user of that site, silently.
 *
 * The replacement submits a delta: the ids the form *rendered* travel with the
 * ids the operator *checked*, so absence stops meaning revocation. A user who
 * was not on the page cannot be affected by it, which also makes the form safe
 * to filter, paginate or render partially.
 *
 * These cases are all pure set arithmetic, so they need no database.
 */
final class SiteAccessGrantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function changes(array $current, array $rendered, array $checked): array
    {
        return \OWA\Module\Base\Entity\Site::computeGrantChanges($current, $rendered, $checked);
    }

    /**
     * The defect this replaces: an empty submission must revoke only what the
     * form actually offered, and when it offered nothing it must do nothing.
     */
    public function testAnEmptySubmissionWithNothingRenderedChangesNothing(): void
    {
        $changes = $this->changes([1, 2, 3], [], []);

        $this->assertSame([], $changes['grant']);
        $this->assertSame([], $changes['revoke'], 'an empty form must not revoke every user');
    }

    /**
     * Unchecking everything the form showed does revoke those users -- the
     * operator asked for it, and the rendered list is the evidence.
     */
    public function testUncheckingEverythingRenderedRevokesThoseUsers(): void
    {
        $changes = $this->changes([1, 2, 3], [1, 2, 3], []);

        $this->assertSame([], $changes['grant']);
        $this->assertEqualsCanonicalizing([1, 2, 3], $changes['revoke']);
    }

    /**
     * The headline property: a user the form never rendered is untouched,
     * whatever else the submission says.
     */
    public function testUsersNotRenderedAreNeverTouched(): void
    {
        // The form showed only user 2. User 3 keeps access; user 9 stays out.
        $changes = $this->changes([2, 3], [2], []);

        $this->assertSame([], $changes['grant']);
        $this->assertSame([2], $changes['revoke'], 'only the rendered user may be revoked');

        $changes = $this->changes([2, 3], [2], [2]);

        $this->assertSame([], $changes['grant']);
        $this->assertSame([], $changes['revoke']);
    }

    /**
     * The common edit: one user added, one removed, everyone else left alone.
     * The old code rewrote the entire set to achieve this.
     */
    public function testASingleChangeTouchesOnlyThatUser(): void
    {
        $changes = $this->changes([1, 2, 3], [1, 2, 3, 4], [1, 2, 4]);

        $this->assertSame([4], $changes['grant']);
        $this->assertSame([3], $changes['revoke']);
    }

    /**
     * Re-submitting an unchanged form writes nothing at all.
     */
    public function testAnUnchangedSubmissionIsANoOp(): void
    {
        $changes = $this->changes([1, 2, 3], [1, 2, 3], [1, 2, 3]);

        $this->assertSame([], $changes['grant']);
        $this->assertSame([], $changes['revoke']);
    }

    /**
     * A checked id the form never rendered is not a grant -- it did not come
     * from the page, so it is either stale or forged.
     */
    public function testACheckedIdThatWasNotRenderedIsIgnored(): void
    {
        $changes = $this->changes([1], [1], [1, 99]);

        $this->assertSame([], $changes['grant'], 'an unrendered id must not become a grant');
        $this->assertSame([], $changes['revoke']);
    }

    /**
     * Ids arrive from a form as strings; grants are stored as integers.
     */
    public function testIdsAreComparedAndReturnedAsIntegers(): void
    {
        $changes = $this->changes([1, 2], ['1', '2', '3'], ['1', '3']);

        $this->assertSame([3], $changes['grant']);
        $this->assertSame([2], $changes['revoke']);
    }

    /**
     * Duplicates in the submission do not produce duplicate writes.
     */
    public function testDuplicateSubmittedIdsCollapse(): void
    {
        $changes = $this->changes([], [5, 5], [5, 5]);

        $this->assertSame([5], $changes['grant']);
        $this->assertSame([], $changes['revoke']);
    }
}
