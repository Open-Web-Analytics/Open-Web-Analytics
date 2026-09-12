<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Every metric and dimension must describe itself.
 *
 * The documentation is generated from the registry at release time, so whatever
 * the registry says is what users read. That makes an absent or placeholder
 * description a documentation defect committed months before anyone sees it, and
 * the person who could fix it in seconds is the one registering it.
 *
 * This is deliberately NOT a check that the generated page is up to date. Nothing
 * reads that page between releases, so requiring it to be regenerated on every
 * change would add a step to ordinary work and fail a build over something that
 * is not broken. Generation belongs to the release; this belongs here.
 */
final class CatalogDescriptionsTest extends TestCase
{
    /** Descriptions that say nothing, however long they are. */
    private const PLACEHOLDERS = ['', 'n/a', 'na', 'tbd', 'todo', 'description', '-', '—'];

    private function isUseful(string $desc, string $label): ?string
    {
        $d = trim($desc);

        if (in_array(strtolower(rtrim($d, '.')), self::PLACEHOLDERS, true)) {
            return 'has no description';
        }

        if (strlen($d) < 10) {
            return 'description is too short to say anything: ' . var_export($d, true);
        }

        // A description that only restates the label tells a reader nothing they
        // did not already have from the name next to it.
        if (strcasecmp(rtrim($d, '.'), rtrim(trim($label), '.')) === 0) {
            return 'description only repeats the label: ' . var_export($d, true);
        }

        return null;
    }

    public function testEveryMetricHasAUsefulDescription(): void
    {
        $bad = [];

        foreach ((array) \OWA\Core\CoreAPI::getAllMetrics() as $name => $declarations) {

            $first = is_array($declarations) ? reset($declarations) : [];

            $problem = $this->isUseful(
                (string) ($first['description'] ?? ''),
                (string) ($first['label'] ?? '')
            );

            if ($problem !== null) {
                $bad[] = $name . ': ' . $problem;
            }
        }

        $this->assertSame([], $bad,
            "These metrics are registered without a usable description. They are what a\n"
            . "reader sees in the report builder and in the generated documentation:\n  "
            . implode("\n  ", $bad));
    }

    public function testEveryDimensionHasAUsefulDescription(): void
    {
        $bad = [];

        foreach ((array) \OWA\Core\CoreAPI::getAllDimensions() as $name => $d) {

            $problem = $this->isUseful(
                (string) ($d['description'] ?? ''),
                (string) ($d['label'] ?? '')
            );

            if ($problem !== null) {
                $bad[] = $name . ': ' . $problem;
            }
        }

        $this->assertSame([], $bad,
            "These dimensions are registered without a usable description:\n  "
            . implode("\n  ", $bad));
    }
}
