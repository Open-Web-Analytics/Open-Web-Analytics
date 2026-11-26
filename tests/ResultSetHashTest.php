<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\PaginatedResultSet;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A result set's guid is a hash of what it holds: the grid skips redrawing a
 * refresh whose guid it already has. The field catalogue every result set
 * carries is not hashed.
 */
final class ResultSetHashTest extends TestCase
{
    private function rs(array $rows): PaginatedResultSet
    {
        $rs = new PaginatedResultSet();
        $rs->resultsRows = $rows;
        $rs->createResultSetHash();

        return $rs;
    }

    public function testTheGuidFollowsTheRowsNotTheCatalogue(): void
    {
        $a = $this->rs([['pageViews' => ['value' => 3]]]);
        $b = $this->rs([['pageViews' => ['value' => 3]]]);
        $c = $this->rs([['pageViews' => ['value' => 4]]]);

        $this->assertSame($a->guid, $b->guid, 'the same rows, the same guid');
        $this->assertNotSame($a->guid, $c->guid, 'new rows, a new guid');

        $b->relatedDimensions = ['page' => [['name' => 'pagePath']]];
        $b->createResultSetHash();
        $this->assertSame($a->guid, $b->guid, 'the catalogue is not hashed');
    }
}
