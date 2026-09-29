<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Recordings belong to the Domstream module. The server knows nothing of them
 * until it is active, so neither Core nor Base may name them.
 *
 * READS THE SOURCE: an event property, a setting or a nav entry that slipped
 * back into Base would work, and nothing at runtime would say where it lives.
 *
 * The exceptions are v1's: the migration's list of v1 tables, which the drop
 * removes by name, and the compat layer, whose job is to know v1's names.
 */
final class BaseKnowsNothingOfDomstreamTest extends TestCase
{
    /** file => how many lines may mention it */
    private const V1 = [
        'modules/Base/Classes/Migration/V1Tables.php'  => 1,
        'conf/beacon_compat.php'                       => 1,
    ];

    public function testNoCoreOrBaseSourceNamesRecordings(): void
    {
        $scanned = 0;
        $offenders = [];

        foreach (['Core', 'modules/Base', 'conf'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(OWA_DIR . $dir,
                FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $path = substr($file->getPathname(), strlen(OWA_DIR));

                if (!preg_match('/\.(php|js|json|css)$/', $path) || strpos($path, '/dist/') !== false) {
                    continue;
                }

                $scanned++;

                $lines = preg_grep('/dom\.?stream/i', (array) file($file->getPathname()));

                if (!$lines) {
                    continue;
                }

                if (array_key_exists($path, self::V1)
                    && (self::V1[$path] === null || count($lines) <= self::V1[$path])) {
                    continue;
                }

                foreach ($lines as $n => $line) {
                    $offenders[] = sprintf('%s:%d  %s', $path, $n + 1, trim($line));
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'the source was not read -- this asserts nothing without it');
        $this->assertSame([], $offenders, "recordings are named outside their module:\n" . implode("\n", $offenders));
    }

    /** An exception that no longer applies is removed, not kept "just in case". */
    public function testEveryExceptionStillApplies(): void
    {
        foreach (array_keys(self::V1) as $path) {
            $this->assertNotEmpty(preg_grep('/dom\.?stream/i', (array) @file(OWA_DIR . $path)),
                "$path no longer mentions recordings; drop it from the exceptions");
        }
    }
}
