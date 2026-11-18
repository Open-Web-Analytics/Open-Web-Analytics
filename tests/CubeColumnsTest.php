<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Columns;

/**
 * The cube's column definitions, and the guards that make the config safe.
 *
 * The definitions are data, so the value of them being data is that a mistake
 * is caught rather than executed. These are those catches.
 */
final class CubeColumnsTest extends TestCase
{
    private function derived(): array
    {
        $raw  = \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getColumns();
        $cube = \OWA\Core\CoreAPI::entityFactory('base.event')->getColumns();

        return array_values(array_diff($cube, $raw));
    }

    public function testEveryDerivedColumnHasExactlyOneDefinition(): void
    {
        $steps = (new Columns())->steps($this->derived());

        $this->assertSame($this->derived(), array_keys($steps),
            'the shipped config fills every column the cube adds, in the entity\'s order');
    }

    public function testAColumnWithNoDefinitionIsFatal(): void
    {
        // The failure this replaces was silent: the SELECT list was built by
        // appending expressions in an order that had to match the entity's.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no definition for');

        (new Columns([]))->steps(['source']);
    }

    public function testADefinitionForAColumnTheCubeLacksIsFatal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('which the cube does not have');

        (new Columns(['not_a_column' => ['kind' => 'is_exit']]))->steps([]);
    }

    public function testAnUnknownKindIsFatal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new Columns(['is_exit' => ['kind' => 'invented']]))->steps(['is_exit']);
    }

    public function testADefinitionMayNotNameAJoinAliasOrSql(): void
    {
        // Definitions name logical sources. One holding `s.s_tagged_source`
        // would be SQL in config, which A.1.23 refuses for dimensions.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a cube source');

        (new Columns([
            'campaign' => ['kind' => 'copy', 'from' => 's.s_tagged_campaign'],
        ]))->steps(['campaign']);
    }

    public function testAnUnknownSessionValueIsFatal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a value the cube carries');

        (new Columns([
            'campaign' => ['kind' => 'copy', 'from' => 'session.invented'],
        ]))->steps(['campaign']);
    }

    public function testTheWindowProjectsOnlyWhatDefinitionsReference(): void
    {
        // Demand-driven: the sort is the build's whole cost, so a value nothing
        // reads should not be in it.
        $columns = new Columns([
            'campaign' => ['kind' => 'copy', 'from' => 'session.tagged_campaign'],
        ]);

        $columns->steps(['campaign']);

        $this->assertSame(['tagged_campaign' => 's_tagged_campaign'],
            $columns->sessionSources());
    }

    public function testTheShippedConfigReferencesSeventeenSessionValues(): void
    {
        $columns = new Columns();
        $columns->steps($this->derived());

        // In the order the definitions first reference them, which is why
        // referer_host lands between the two tags source reads.
        $this->assertSame([
            'tagged_source', 'referer_host', 'tagged_medium', 'tagged_campaign',
            'tagged_ad', 'tagged_search_terms', 'page_location', 'page_path',
            'page_query', 'page_title',
            // attributed_* (PLAN 2.29): the stamped prior touch, and the
            // session's first ts that its window is measured from.
            'prior_touch_source', 'prior_touch_referer_host', 'prior_touch_medium',
            'prior_touch_campaign', 'prior_touch_ad', 'prior_touch_ts', 'ts',
        ], array_keys($columns->sessionSources()));
    }
}
