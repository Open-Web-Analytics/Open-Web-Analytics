<?php

use PHPUnit\Framework\TestCase;

/**
 * A subselect in FROM brings its bound values into the statement that embeds
 * it, in placeholder order. A segment's query is such a subselect: built on a
 * Db of its own, only its text reached the report's statement, which then had
 * more placeholders than values and failed.
 */
final class SubselectBindingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function outerStatement(): array
    {
        $inner = \OWA\Core\CoreAPI::dbFactory();
        $inner->select('s.*');
        $inner->from('owa_session', 's');
        $inner->where('s.medium', 'direct');
        $inner->where('s.site_id', 'owatest-site');

        $sql = $inner->generateSelectQuerySql();

        $outer = \OWA\Core\CoreAPI::dbFactory();
        $outer->selectColumn('count(*)', 'n');
        $outer->selectFrom(sprintf('(%s)', $sql), 'segment', $inner->getBindings());
        $outer->where('segment.yyyymmdd', '20261119');

        return [$outer->generateSelectQuerySql(), $outer->getBindings()];
    }

    public function testTheStatementHasAValueForEveryPlaceholder(): void
    {
        [$sql, $bindings] = $this->outerStatement();

        $this->assertSame(3, substr_count($sql, '?'));
        $this->assertCount(3, $bindings);
    }

    public function testTheSubselectsValuesComeBeforeTheOuterWhereClauses(): void
    {
        [, $bindings] = $this->outerStatement();

        $this->assertSame(['direct', 'owatest-site', '20261119'], array_values($bindings));
    }

    /** Generating the statement again binds each value once, not twice. */
    public function testRegeneratingDoesNotRepeatTheValues(): void
    {
        $inner = \OWA\Core\CoreAPI::dbFactory();
        $inner->select('s.*');
        $inner->from('owa_session', 's');
        $inner->where('s.medium', 'direct');
        $inner->generateSelectQuerySql();

        $outer = \OWA\Core\CoreAPI::dbFactory();
        $outer->selectColumn('count(*)', 'n');
        $outer->from('(subselect)', 'segment', $inner->getBindings());
        $outer->generateSelectQuerySql();
        $outer->generateSelectQuerySql();

        $this->assertSame(['direct'], array_values($outer->getBindings()));
    }
}
