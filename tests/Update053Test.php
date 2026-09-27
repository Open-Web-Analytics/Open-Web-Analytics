<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A saved custom report loses the dimension the release removed.
 *
 * element_path's dimension, `elementPath`, could be chosen in the report builder
 * -- so a saved definition may name it, and a dimension the registry no longer
 * knows is REFUSED rather than ignored. Unlike Update052's browser ->
 * browser_type there is nothing lossless to map it onto, so the widget that
 * asked for it loses that grouping.
 *
 * THE REWRITE IS TESTED WITHOUT THE DATABASE. withoutDimension() is a pure
 * function over the decoded definition, which is the whole of the judgement --
 * which keys to walk, what to do with a widget left with none, and the sort that
 * would otherwise name a column the query no longer selects. The surrounding
 * load-edit-save loop is the same shape Update052 already has.
 */
final class Update053Test extends TestCase
{
    /** @var \OWA\Module\Base\Update\Update053 */
    private $update;

    protected function setUp(): void
    {
        $this->update = new \OWA\Module\Base\Update\Update053();
    }

    /** @return array the rewritten definition */
    private function strip( array $definition, string $dimension = 'elementPath' ): array
    {
        $method = new ReflectionMethod( $this->update, 'withoutDimension' );
        $method->setAccessible( true );

        return $method->invoke( $this->update, $definition, $dimension );
    }

    public function testTheDimensionGoesAndTheOthersStay(): void
    {
        $out = $this->strip( [ 'widgets' => [
            [ 'id' => 'w1', 'query' => [
                'metrics' => 'eventCount', 'dimensions' => 'pagePath,elementPath,domElementId' ] ],
        ] ] );

        $this->assertSame( 'pagePath,domElementId',
            $out['widgets'][0]['query']['dimensions'],
            'the other groupings are what the report is still for' );

        $this->assertSame( 'eventCount', $out['widgets'][0]['query']['metrics'],
            'nothing else in the query is touched' );
    }

    /**
     * A widget with nothing left loses the KEY, not a widget with an empty one.
     *
     * `dimensions => ''` is not what an unspecified grouping looks like anywhere
     * else: parseDimensionsString() would split it into one empty name, which
     * cannot resolve. Absent is the shape the rest of the system means by "no
     * dimensions".
     */
    public function testAWidgetLeftWithNoneLosesTheKey(): void
    {
        $out = $this->strip( [ 'widgets' => [
            [ 'id' => 'w1', 'query' => [ 'metrics' => 'eventCount', 'dimensions' => 'elementPath' ] ],
        ] ] );

        $this->assertArrayNotHasKey( 'dimensions', $out['widgets'][0]['query'] );
        $this->assertSame( 'eventCount', $out['widgets'][0]['query']['metrics'],
            'the widget survives; only its grouping is gone' );
    }

    /**
     * And the sort goes with it, ascending or descending.
     *
     * A sort on a column the SELECT no longer carries is refused by the resolver,
     * so leaving it would turn a report that lost a grouping into a report that
     * returns an error.
     */
    public function testASortOnTheDroppedDimensionGoesToo(): void
    {
        foreach ( [ 'elementPath', 'elementPath-' ] as $sort ) {

            $out = $this->strip( [ 'widgets' => [
                [ 'query' => [ 'dimensions' => 'pagePath,elementPath', 'sort' => $sort ] ],
            ] ] );

            $this->assertArrayNotHasKey( 'sort', $out['widgets'][0]['query'],
                "a sort on the dropped dimension must not survive ($sort)" );
        }

        // A sort on something else is left exactly as it was.
        $out = $this->strip( [ 'widgets' => [
            [ 'query' => [ 'dimensions' => 'pagePath,elementPath', 'sort' => 'eventCount-' ] ],
        ] ] );

        $this->assertSame( 'eventCount-', $out['widgets'][0]['query']['sort'] );
    }

    /**
     * A definition that does not name it comes back IDENTICAL, which is what
     * makes the migration idempotent: the caller skips a report whose rewrite
     * changed nothing, so a second run writes no rows at all.
     */
    public function testADefinitionWithoutItIsUnchanged(): void
    {
        $definition = [ 'title' => 'Pages', 'metrics' => 'pageViews', 'widgets' => [
            [ 'id' => 'w1', 'query' => [ 'metrics' => 'pageViews', 'dimensions' => 'pagePath' ] ],
        ] ];

        $this->assertSame( $definition, $this->strip( $definition ) );
    }

    /** A funnel carries steps and no widgets, and must survive untouched. */
    public function testAShapeWithNoWidgetsIsUntouched(): void
    {
        $definition = [ 'steps' => [ [ 'name' => 'Docs', 'path' => '/docs', 'step_number' => 1 ] ] ];

        $this->assertSame( $definition, $this->strip( $definition ) );
    }

    /**
     * The update and the entity describe the same swap.
     *
     * Update053 is what makes an EXISTING install agree with the entity, so the
     * two disagreeing is an install that reports "up to date" while the cube is
     * missing a column every build names -- which fails with "Unknown column in
     * field list" having published nothing.
     *
     * required_schema_version is not asserted here: UpdateDiscoveryTest already
     * reads it out of Base/Module.php and fails if it does not cover the highest
     * update on disk, which is the same fact stated once.
     */
    public function testTheUpdateAndTheEntityAgree(): void
    {
        $this->assertSame( 53, $this->update->schema_version );

        $columns = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();

        $this->assertContains( 'is_outbound', $columns );
        $this->assertNotContains( 'element_path', $columns );
    }
}
