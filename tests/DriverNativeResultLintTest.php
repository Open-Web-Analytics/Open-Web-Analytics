<?php

use PHPUnit\Framework\TestCase;

/**
 * A driver's own result object stays inside that driver.
 *
 * WHAT THIS EXISTS FOR
 *
 * `Db::query()` returns whatever the DRIVER returns: a PDOStatement under the
 * pdo driver, a mysqli_result under mysqli. They share no interface. So
 * `$db->query( $sql, $params )->fetchAll( PDO::FETCH_ASSOC )` works perfectly
 * on one install and is a fatal `Call to undefined method
 * mysqli_result::fetchAll()` on the other.
 *
 * It is a mistake that hides completely on a developer machine, because a
 * machine has one driver. It surfaced in CI's isolation sweep -- the mysqli job
 * failing while the pdo job passed -- on the goal funnel and the domstreams
 * list, both written against the driver the author happened to be running.
 *
 * WHAT TO USE INSTEAD
 *
 * `get_results( $sql, $params )` and `get_row( $sql, $params )`. Both drivers
 * implement them with the same contract: associative rows, and NULL for both
 * "no rows" and "the query failed". A caller has to allow for the null; that is
 * the price of the portability and it is documented on both.
 *
 * WHY A SOURCE SCAN
 *
 * Because the alternative is a test suite that runs under both drivers, which
 * this project does not have outside CI -- and CI is where the cost of finding
 * it is highest. Reading the source costs nothing and finds it before the push.
 */
final class DriverNativeResultLintTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Source scanning needs no database, but it does need OWA_DIR -- and
        // the portable-pair assertion needs the drivers autoloadable.
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /**
     * Where a driver-native result legitimately lives: the drivers themselves.
     *
     * Core/Db/Pdo.php and PdoMysql.php ARE the PDO driver -- naming PDO is what
     * they are for.
     */
    private const DRIVER_DIR = 'Core/Db/';

    /**
     * Methods that exist on PDOStatement and not on mysqli_result, or the
     * reverse. Calling one of these on a `query()` result is the bug.
     */
    private const NATIVE_CALLS = array(
        '->fetchAll('     => 'PDOStatement only; use $db->get_results( $sql, $params )',
        '->fetchColumn('  => 'PDOStatement only; use $db->get_row() and read the column',
        '->rowCount('     => 'PDOStatement only; count in SQL, or count the rows returned',
        '->fetch_assoc('  => 'mysqli_result only; use $db->get_results()',
        '->fetch_all('    => 'mysqli_result only; use $db->get_results()',
        'PDO::FETCH_'     => 'a PDO fetch mode outside the pdo driver',
        'instanceof \PDOStatement' => 'only the pdo driver knows what its result is',
    );

    /** @return string[] repo-relative paths of the PHP we ship */
    private function shippedPhpFiles(): array
    {
        $root = rtrim(OWA_DIR, '/');
        $out  = array();

        $skip = array('vendor', 'node_modules', 'tests', '.git', 'public', 'build');

        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($current) use ($skip) {
                    return !($current->isDir() && in_array($current->getFilename(), $skip, true));
                }
            )
        );

        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $out[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($out);

        return $out;
    }

    public function testNoShippedCodeCallsADriverNativeResultMethod(): void
    {
        $files = $this->shippedPhpFiles();

        // Guard the guard: an empty file list would pass this vacuously.
        $this->assertGreaterThan(200, count($files),
            'the scan found almost no PHP, so it is not scanning the tree');

        $offences = array();

        foreach ($files as $relative) {

            if (strpos($relative, self::DRIVER_DIR) === 0) {
                continue;
            }

            $source = (string) file_get_contents(OWA_DIR . $relative);

            foreach (self::NATIVE_CALLS as $needle => $why) {

                if (strpos($source, $needle) === false) {
                    continue;
                }

                // Report the line, so the failure names the call site rather
                // than only the file.
                foreach (explode("\n", $source) as $n => $line) {

                    if (strpos($line, $needle) !== false) {

                        // A mention inside a comment is documentation -- this
                        // file's own explanation of the rule, and the comments
                        // on the two call sites that were fixed.
                        $code = trim($line);

                        if ($code === '' || $code[0] === '*' || strpos($code, '//') === 0
                            || strpos($code, '/*') === 0) {
                            continue;
                        }

                        $offences[] = sprintf('%s:%d  %s  -- %s',
                            $relative, $n + 1, trim($line), $why);
                    }
                }
            }
        }

        $this->assertSame(array(), $offences,
            "A driver's own result object escaped its driver. Db::query() returns a\n"
            . "PDOStatement under pdo and a mysqli_result under mysqli, and they share\n"
            . "no interface -- so this works on one install and fatals on the other:\n\n"
            . implode("\n", $offences));
    }

    /**
     * ...and the portable pair really is portable.
     *
     * Asserted rather than assumed, because the rule above is only worth
     * anything if what it points people at exists on both drivers.
     */
    public function testBothDriversImplementThePortablePair(): void
    {
        foreach (array('\OWA\Core\Db\Pdo', '\OWA\Core\Db\Mysql') as $driver) {

            $this->assertTrue(class_exists($driver), "$driver is not loadable");

            foreach (array('get_results', 'get_row') as $method) {

                $this->assertTrue(method_exists($driver, $method),
                    "$driver::$method() is what callers are told to use instead");
            }
        }
    }
}
