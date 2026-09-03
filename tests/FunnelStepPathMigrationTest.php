<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update017 moves a funnel step's page from `url` to `path`.
 *
 * It was always a path -- the funnel report constrains `pagePath ==` on it and
 * checkGoalStart matches it against `page_uri` -- and only the entry form called
 * it a URL. Renaming the key without moving the stored value would leave every
 * existing funnel matching nothing, silently, which is the failure this rename
 * is meant to stop rather than cause.
 *
 * Update017::migrateGoals() is called directly -- the shipped code, not a
 * reimplementation of it. Driving up() instead would write the `goals` site
 * setting on this install; an earlier version of this test carried its own copy
 * of the transform, which would have kept passing if the real one drifted.
 */
final class FunnelStepPathMigrationTest extends TestCase
{
    /** The SHIPPED transform, not a copy of it. */
    private function migrate( array $goals ): array
    {
        return \OWA\Module\Base\Update\Update017::migrateGoals( $goals );
    }

    /** Likewise the shipped reverse. */
    private function revert( array $goals ): array
    {
        return \OWA\Module\Base\Update\Update017::revertGoals( $goals );
    }

    private function goalWith( array $steps ): array
    {
        return array( 1 => array( 'goal_number' => 1, 'goal_status' => 'active',
                                  'details' => array( 'funnel_steps' => $steps ) ) );
    }

    public function testTheValueMovesToPath(): void
    {
        $out = $this->migrate( $this->goalWith( array(
            1 => array( 'name' => 'Basket', 'url' => '/basket' ) ) ) );

        $step = $out[1]['details']['funnel_steps'][1];

        $this->assertSame( '/basket', $step['path'] );
        $this->assertArrayNotHasKey( 'url', $step,
            'the old key must go, or a later reader can still find the wrong one' );
    }

    public function testEverythingElseOnTheStepSurvives(): void
    {
        $out = $this->migrate( $this->goalWith( array(
            1 => array( 'name' => 'Basket', 'url' => '/basket', 'is_required' => 'true' ) ) ) );

        $step = $out[1]['details']['funnel_steps'][1];

        $this->assertSame( 'Basket', $step['name'] );
        $this->assertSame( 'true', $step['is_required'] );
    }

    /** Safe to run twice: an install part-way through an update is not corrupt. */
    public function testRunningItAgainChangesNothing(): void
    {
        $once  = $this->migrate( $this->goalWith( array(
            1 => array( 'name' => 'Basket', 'url' => '/basket' ) ) ) );
        $twice = $this->migrate( $once );

        $this->assertSame( $once, $twice );
    }

    /** A step carrying both keeps the new one and drops the stale one. */
    public function testAStepWithBothKeepsPath(): void
    {
        $out = $this->migrate( $this->goalWith( array(
            1 => array( 'name' => 'Basket', 'path' => '/new', 'url' => '/stale' ) ) ) );

        $step = $out[1]['details']['funnel_steps'][1];

        $this->assertSame( '/new', $step['path'] );
        $this->assertArrayNotHasKey( 'url', $step );
    }

    public function testAGoalWithNoFunnelIsUntouched(): void
    {
        $goals = array( 1 => array( 'goal_number' => 1, 'goal_status' => 'active',
                                    'details' => array( 'goal_url' => '/thanks' ) ) );

        $this->assertSame( $goals, $this->migrate( $goals ) );
    }

    /**
     * Every element of the funnel array is keyed `path`.
     *
     * The controller used to APPEND one itself -- the goal's own destination as
     * a final synthetic step -- and built it with 'url' while the loop that
     * follows reads $step['path'], so that one element matched nothing.
     *
     * There is no appended step now. A visualization's steps ARE the path and
     * its last step is the destination, so the bug this guarded cannot recur in
     * that form. What remains worth asserting is the key itself: the counting
     * loop still reads 'path', so anything building a step has to use it.
     */
    public function testTheCountingLoopReadsTheKeyStepsAreStoredUnder(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/VisualizationFunnel.php' );

        $this->assertStringContainsString( "\$step['path']", $src,
            'The counting loop reads some other key than the one steps are stored under.' );

        $this->assertStringNotContainsString( "'url'   =>", $src,
            'A step is being built with a `url` key again, which the loop cannot read.' );
    }

    /** The class exists, declares the version the module now requires, and reverses. */
    public function testTheUpdateDeclaresTheRequiredSchemaVersion(): void
    {
        $this->assertTrue( class_exists( '\OWA\Module\Base\Update\Update017' ) );

        $u = new \ReflectionClass( '\OWA\Module\Base\Update\Update017' );

        $this->assertSame( 17, $u->getDefaultProperties()['schema_version'] );
        $this->assertTrue( $u->hasMethod( 'down' ), 'an update that rewrites data must reverse' );
    }

    public function testDownMovesTheValueBackToUrl(): void {

        $out = $this->revert( $this->goalWith( array(
            1 => array( 'name' => 'Pricing', 'path' => '/pricing' ) ) ) );
        $step = $out[1]['details']['funnel_steps'][1];

        $this->assertSame( '/pricing', $step['url'] );
        $this->assertArrayNotHasKey( 'path', $step, 'down leaves one shape behind, not both' );
        $this->assertSame( 'Pricing', $step['name'], 'the rest of the step survives the reverse too' );
        $this->assertSame( array( 'name', 'url' ), array_keys( $step ),
            'and the key returns to its position, not to the end of the step' );
    }

    public function testRunningDownAgainChangesNothing(): void {

        $once  = $this->revert( $this->goalWith( array(
            1 => array( 'path' => '/pricing' ) ) ) );
        $twice = $this->revert( $once );

        $this->assertSame( $once, $twice, 'down must be safe to re-run' );
    }

    public function testDownOnAStepWithBothKeepsUrl(): void {

        $out = $this->revert( $this->goalWith( array(
            1 => array( 'url' => '/old', 'path' => '/new' ) ) ) );
        $step = $out[1]['details']['funnel_steps'][1];

        $this->assertSame( '/old', $step['url'], 'the key down migrates TO wins, as up does' );
        $this->assertArrayNotHasKey( 'path', $step );
    }

    public function testDownLeavesAGoalWithNoFunnelAlone(): void {

        $goals = array( 1 => array( 'goal_name' => 'No funnel', 'details' => array( 'goal_url' => '/x' ) ) );

        $this->assertSame( $goals, $this->revert( $goals ) );
    }

    /**
     * The property the pair has to have together: the store ends up in exactly
     * one of two shapes, and getting there twice is the same as getting there
     * once. Asserting each direction alone would not catch a reverse that lost
     * a step, reordered keys, or left both keys behind.
     */
    public function testTheMigrationRoundTrips(): void {

        $original = $this->goalWith( array(
            1 => array( 'name' => 'Pricing', 'url' => '/pricing', 'is_required' => true ) ) );

        $up   = $this->migrate( $original );
        $down = $this->revert( $up );

        // assertSame, deliberately: a rollback has to hand back what it was
        // given, so the structure must be IDENTICAL, not merely equal.
        $this->assertSame( $original, $down, 'down( up( x ) ) is x' );
        $this->assertSame( $up, $this->migrate( $down ), 'and up( down( up( x ) ) ) is up( x )' );

        // What lets assertSame hold: the key is renamed in place. Written the
        // obvious way -- set the new key, unset the old -- it would append, and
        // the rolled-back step would read name,is_required,url.
        $this->assertSame(
            array( 'name', 'path', 'is_required' ),
            array_keys( $up[1]['details']['funnel_steps'][1] ),
            'the renamed key stays where the operator put it' );
    }
}
