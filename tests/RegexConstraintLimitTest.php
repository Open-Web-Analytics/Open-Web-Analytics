<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A =~ or !~ report filter is refused when its pattern can make the database
 * backtrack without end, and the patterns reports actually use still run.
 */
final class RegexConstraintLimitTest extends TestCase
{
    private function problem(string $p): ?string
    {
        return \OWA\Module\Base\Classes\ResultSetManager::regexProblem($p);
    }

    public function testTheShippedReportsPatternsAreAccepted(): void
    {
        foreach (array('^[(](direct|organic|ai-agent|referral)[)]$', '^form_(start|submit)$',
                       '^/blog/', '\\.pdf$', 'a{2,5}', '(foo|bar)+') as $p) {
            $this->assertNull($this->problem($p), $p);
        }
    }

    public function testEveryShippedReportFilterPasses(): void
    {
        $seen = 0;

        foreach (glob(dirname(__DIR__) . '/modules/*/reports/*.json') as $file) {
            $walk = function ($node) use (&$walk, &$seen, $file) {
                if (!is_array($node)) {
                    return;
                }
                if (in_array($node['operator'] ?? null, array('=~', '!~'), true) && isset($node['value'])) {
                    $seen++;
                    $this->assertNull($this->problem((string) $node['value']), basename($file) . ': ' . $node['value']);
                }
                array_map($walk, $node);
            };
            $walk(json_decode((string) file_get_contents($file), true));
        }

        $this->assertGreaterThanOrEqual(3, $seen, 'the shipped reports\' regex filters were found');
    }

    public function testANestedQuantifierIsRefused(): void
    {
        foreach (array('(a+)+$', '(a*)*b', '(x{2,3})+', '(.*)*', '((ab)+)+') as $p) {
            $this->assertNotNull($this->problem($p), $p);
        }
    }

    public function testABackreferenceIsRefused(): void
    {
        $this->assertNotNull($this->problem('(a)\\1'));
    }

    public function testAHugeRepeatIsRefused(): void
    {
        $this->assertNotNull($this->problem('a{1000}'));
        $this->assertNotNull($this->problem('a{1,500}'));
        $this->assertNull($this->problem('a{1,100}'));
    }

    public function testALongOrInvalidPatternIsRefused(): void
    {
        $this->assertNotNull($this->problem(str_repeat('a', 257)));
        $this->assertNotNull($this->problem('([unclosed'));
    }

    public function testARefusedPatternIsARequestErrorNotAnUnfilteredQuery(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();
        $rsm->setConstraints(array(array('name' => 'pagePath', 'operator' => '=~', 'value' => '(a+)+$')));

        $errors = new \ReflectionProperty($rsm, 'request_errors');

        $this->assertNotEmpty((array) $errors->getValue($rsm));
    }
}
