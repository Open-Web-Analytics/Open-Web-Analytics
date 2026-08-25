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
     * Every element of the funnel array is keyed `path`, including the one the
     * report appends itself.
     *
     * ReportGoalFunnel adds the goal's own destination as a final synthetic
     * step, and the loop that follows constrains on $step['path']. It was built
     * with 'url', so after the rename that one element -- the goal itself, the
     * last bar of the funnel -- produced `pagePath==` and matched nothing.
     *
     * Source-scanned because reaching the loop needs a configured funnel, a
     * goalNumber and traffic: no install here has a single funnel step, which
     * is exactly why the suite did not catch it.
     */
    public function testTheReportBuildsItsAppendedStepWithTheSameKeyItReads(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/ReportGoalFunnel.php' );

        $this->assertMatchesRegularExpression(
            "/\\\$funnel\\[\\]\\s*=\\s*array\\(\\s*'path'\\s*=>/", $src,
            'the appended step must be keyed path, like the stored ones' );

        $this->assertSame( 0, preg_match( "/\\\$funnel\\[\\]\\s*=\\s*array\\(\\s*'url'\\s*=>/", $src ),
            'nothing may build a funnel element keyed url any more' );

        $this->assertSame( 0, preg_match( "/\\\$step\\['url'\\]|\\\$funnel\\[[^\\]]+\\]\\['url'\\]/", $src ),
            'and nothing may read one' );
    }

    /** The class exists, declares the version the module now requires, and reverses. */
    public function testTheUpdateDeclaresTheRequiredSchemaVersion(): void
    {
        $this->assertTrue( class_exists( '\OWA\Module\Base\Update\Update017' ) );

        $u = new \ReflectionClass( '\OWA\Module\Base\Update\Update017' );

        $this->assertSame( 17, $u->getDefaultProperties()['schema_version'] );
        $this->assertTrue( $u->hasMethod( 'down' ), 'an update that rewrites data must reverse' );
    }
}
