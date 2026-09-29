<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Debug output is one line per message, built only when debug logging is on,
 * and never carries what a request posted.
 *
 * A beacon used to write 4,233 lines: filters logged the whole registry map
 * they were handed, twice per callback, through print_r() -- which ran on
 * every install at every level, because the string is built before debug()
 * can discard it.
 */
final class DebugLoggingTest extends TestCase
{
    public function testAStringPassesThrough(): void
    {
        $this->assertSame('plain', \OWA\Core\Lib::forLog('plain'));
    }

    public function testAnArrayIsOneLineOfJson(): void
    {
        $line = \OWA\Core\Lib::forLog(['a' => 1, 'b' => ['c' => 'd/e']]);

        $this->assertSame('{"a":1,"b":{"c":"d/e"}}', $line);
        $this->assertStringNotContainsString("\n", \OWA\Core\Lib::forLog(['x' => "multi\nline"]));
    }

    public function testAnEventIsItsTypeAndPropertyNamesNotItsValues(): void
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('page_view');
        $event->setProperties(['visitor_id' => '1790000000000000011', 'ip_address' => '203.0.113.9']);

        $line = \OWA\Core\Lib::forLog($event);

        $this->assertStringStartsWith('page_view event, properties: ', $line);
        $this->assertStringContainsString('visitor_id, ip_address', $line);
        $this->assertStringNotContainsString('1790000000000000011', $line);
        $this->assertStringNotContainsString('203.0.113.9', $line);
    }

    public function testAnObjectIsItsClass(): void
    {
        $this->assertSame('stdClass', \OWA\Core\Lib::forLog(new stdClass()));
    }

    public function testALongValueIsCut(): void
    {
        $line = \OWA\Core\Lib::forLog(str_repeat('x', 5000), 100);

        $this->assertSame(str_repeat('x', 100) . '... (5000 bytes)', $line);
    }

    /** The two ways a debug statement put a whole value in the log, gone. */
    public function testNoDebugStatementFormatsAValueItselfOrLogsWhatARequestSent(): void
    {
        $offenders = [];
        $scanned = 0;
        $statements = 0;

        foreach (['Core', 'modules'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(OWA_DIR . $dir,
                FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $path = $file->getPathname();

                if (substr($path, -4) !== '.php' || strpos($path, '/vendor/') !== false) {
                    continue;
                }

                $scanned++;
                $src = (string) file_get_contents($path);

                // Each debug(...) statement, however many lines it spans.
                preg_match_all('/(?<![\/\w])(?:CoreAPI::|self::|->)debug\s*\((?:[^;]|;(?!\s*$))*?\)\s*;/m',
                    $src, $m, PREG_OFFSET_CAPTURE);

                $statements += count($m[0]);

                foreach ($m[0] as [$statement, $offset]) {
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $lineText = trim(explode("\n", substr($src, strrpos(substr($src, 0, $offset), "\n") ?: 0))[1] ?? '');

                    if (strpos($lineText, '//') === 0) {
                        continue;
                    }

                    $where = substr($path, strlen(OWA_DIR)) . ':' . $line;

                    if (preg_match('/print_r|var_export/', $statement)) {
                        $offenders[] = "$where formats the value itself: pass it as debug()'s context";
                    }

                    if (preg_match('/\$(params|post_vars|rest_params|_POST|_GET|_REQUEST)\b|->owa_params\b/', $statement)
                        && strpos($statement, 'array_keys(') === false) {
                        $offenders[] = "$where logs request values: log their names";
                    }
                }
            }
        }

        $this->assertGreaterThan(300, $scanned, 'the source was not read');
        $this->assertGreaterThan(250, $statements, 'no debug statements were matched');
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}
