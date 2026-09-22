<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Cube\Context;
use OWA\Module\Base\Classes\Cube\SearchTermsStep;

/**
 * What a step emits, asserted on the SQL rather than through a build.
 *
 * Some of these are unreachable through the database, which is the point of
 * testing them here. The fill-never-overwrite COALESCE is the example: the
 * candidate predicate already excludes every row that has a SQL answer, so
 * inverting the COALESCE changes no stored value and a database test passes
 * either way. The two are defence in depth, and only one of them is observable
 * from the outside.
 */
final class CubeStepTest extends TestCase
{
    private function context(): Context
    {
        return new Context(
            array('name' => 'p20260901', 'start' => '20260901', 'less_than' => '20261001'),
            1700000000000000,
            1699999999000000
        );
    }

    public function testAComputeStepPutsItsFallbackFirst(): void
    {
        $step = new SearchTermsStep('search_terms');
        $sql  = $step->execute($this->context());

        $this->assertStringStartsWith('COALESCE(', $sql);

        $fallback = strpos($sql, 's.s_tagged_search_terms');
        $computed = strpos($sql, 'c.search_terms');

        $this->assertNotFalse($fallback);
        $this->assertNotFalse($computed);
        $this->assertLessThan($computed, $fallback,
            'the SQL answer must come first, or a computed value would overwrite a tag');
    }

    public function testAComputeStepDecodesTheEnginesParameter(): void
    {
        $step = new SearchTermsStep('search_terms');

        $this->assertSame('open web analytics', $step->compute(array(
            'referer_host'  => 'yandex.ru',
            'referer_query' => 'text=open+web+analytics',
        )), 'the + separators become spaces, which is the half SQL cannot do');

        $this->assertSame('a b', $step->compute(array(
            'referer_host'  => 'www.google.com',
            'referer_query' => 'q=a%20b&hl=en',
        )), 'percent-encoding decodes too');
    }

    public function testAComputeStepAnswersNullRatherThanASentinel(): void
    {
        $step = new SearchTermsStep('search_terms');

        // A known engine that withheld the term. v1 writes '(not provided)'.
        $this->assertNull($step->compute(array(
            'referer_host'  => 'www.google.com',
            'referer_query' => 'hl=en',
        )));

        // Not an engine at all.
        $this->assertNull($step->compute(array(
            'referer_host'  => 'news.example.test',
            'referer_query' => 'q=whatever',
        )));

        // Nothing to read.
        $this->assertNull($step->compute(array('referer_host' => 'yandex.ru', 'referer_query' => '')));
    }

    public function testAComputeStepStripsControlBytesFromWhatItComputes(): void
    {
        // The unresolved sentinel is a control byte, so a value a visitor can
        // shape must not be able to contain one.
        $step = new SearchTermsStep('search_terms');

        $this->assertSame('ab', $step->compute(array(
            'referer_host'  => 'yandex.ru',
            'referer_query' => 'text=a' . rawurlencode("\x1A") . 'b',
        )));
    }

    public function testItSelectsInSqlRatherThanInPhp(): void
    {
        // "every referral with a query string" would stream the partition
        // through PHP, moving the problem rather than solving it.
        $step = new SearchTermsStep('search_terms');

        $columns = array();

        foreach ($step->when() as $clause) {
            $columns[] = $clause[0];
        }

        $this->assertContains('tagged_search_terms', $columns);
        $this->assertContains('referer_query', $columns);
        $this->assertContains('referer_host', $columns);
    }
}
