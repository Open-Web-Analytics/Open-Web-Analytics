<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The English catalogue, conf/strings/en.php, and the keys the reporting
 * JavaScript uses, conf/strings/script-keys.php, are what the code says -- and
 * the user-facing messages are in the catalogue.
 *
 * The catalogue is GENERATED (cli.php cmd=strings-extract), so a message added
 * or reworded without regenerating it fails here, and so does a user-facing
 * message written as a bare literal where t() belongs.
 */
final class StringsCatalogueTest extends TestCase
{
    public function testTheCatalogueIsWhatTheCodeSays(): void
    {
        $map = Strings::extract(Strings::sourceRoots(), $problems, $scriptKeys);

        $this->assertSame([], $problems, 't(), tn(), OWA.l() or OWA.ln() called with something that cannot be catalogued');

        $this->assertSame(
            Strings::catalogueSource($map),
            (string) file_get_contents(Strings::path(Strings::SOURCE_LOCALE)),
            'conf/strings/en.php is out of date: run php cli.php cmd=strings-extract'
        );

        $this->assertSame(
            Strings::scriptKeysSource($scriptKeys),
            (string) file_get_contents(Strings::scriptKeysPath()),
            'conf/strings/script-keys.php is out of date: run php cli.php cmd=strings-extract'
        );
    }

    /**
     * Each of Strings::LABEL_TABLES is found where it is declared and gives
     * labels, so renaming or reshaping one does not quietly drop it from the
     * catalogue; and every label is a key the JavaScript is sent.
     */
    public function testEveryLabelTableIsReadAndItsLabelsAreScriptKeys(): void
    {
        $where = [
            'ConfiguredReport::ROW_ACTIONS' => 'Core/ConfiguredReport.php',
            'OWA.dataGrid.EMPTY_TESTS'      => 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js',
            'OWA.dataGrid.OPERATOR_PHRASES' => 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js',
            'OWA.dataGrid.CONDITIONS'       => 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js',
            'OWA.filterPills.OPERATORS'     => 'modules/Base/src/reporting/v1/owa.filterpills.js',
            'OWA.filterPills.EMPTY_TESTS'   => 'modules/Base/src/reporting/v1/owa.filterpills.js',
        ];

        $this->assertSame(array_keys($where), Strings::LABEL_TABLES);

        $scriptKeys = include Strings::scriptKeysPath();

        foreach ($where as $table => $file) {
            $source = (string) file_get_contents(OWA_DIR . $file);
            $labels = substr($file, -3) === '.js'
                ? Strings::scriptTableLabelsIn($source, [$table])
                : Strings::tableLabelsIn($source, [$table]);

            $this->assertNotEmpty($labels, "$table gives no labels in $file");

            foreach (array_column($labels, 'text') as $label) {
                $this->assertContains($label, $scriptKeys, "$table: '$label'");
            }
        }

        // The PHP table's labels are its row actions' labels, every one.
        $this->assertSame(
            array_values(array_column(\OWA\Core\ConfiguredReport::ROW_ACTIONS, 'label')),
            array_column(Strings::tableLabelsIn((string) file_get_contents(OWA_DIR . 'Core/ConfiguredReport.php')), 'text'));
    }

    /**
     * Where a user-facing message is set, it is set through t(). A bare string
     * literal in one of these positions is a message the catalogue misses.
     *
     * @dataProvider messageSites
     */
    public function testUserFacingMessagesGoThroughT(string $file): void
    {
        $this->assertSame([], self::bareMessages($file), "untranslatable messages in $file");
    }

    public static function messageSites(): array
    {
        $files = array_merge(
            glob(OWA_DIR . 'modules/*/Controller/*.php'),
            glob(OWA_DIR . 'Core/Validation/*.php'),
            [OWA_DIR . 'conf/messages.php', OWA_DIR . 'modules/Base/settings.php',
             OWA_DIR . 'modules/Base/Classes/CustomReports.php', OWA_DIR . 'modules/Base/Classes/Cube/Dimensions.php',
             OWA_DIR . 'modules/Base/Classes/Cube/Status.php', OWA_DIR . 'modules/Base/Classes/SchedulerHealth.php',
             OWA_DIR . 'modules/Base/Classes/InstallDatabase.php']
        );

        $out = [];

        foreach ($files as $file) {
            // CLI output is for operators and is out of the catalogue's scope.
            if (substr($file, -7) !== 'Cli.php') {
                $out[str_replace(OWA_DIR, '', $file)] = [$file];
            }
        }

        return $out;
    }

    /**
     * file:line => text of each bare literal set as a message: the value of an
     * errorMsg / error_msg / error / message / headline / msg / pattern_problem
     * key, or the first argument of refuse() / setErrorMessage() / addError().
     */
    private static function bareMessages(string $file): array
    {
        $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)), function ($t) {
            return !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
        }));

        $keys  = ["'errorMsg'", "'error_msg'", "'error'", "'message'", "'headline'", "'msg'", "'pattern_problem'"];
        $calls = ['refuse', 'setErrorMessage', 'addError'];
        $found = [];

        foreach ($tokens as $i => $t) {
            if (!is_array($t)) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $at   = null;

            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && in_array($t[1], $keys, true)
                && is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                $at = $i + 2;
            } elseif ($t[0] === T_STRING && in_array($t[1], $calls, true) && $next === '(') {
                $at = $i + 2;
            } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING && $t[1] === "'error_msg'" && $next === ',') {
                $at = $i + 2;   // ->set( 'error_msg', ... )
            }

            $value = $at === null ? null : ($tokens[$at] ?? null);

            if (is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/[a-z]{3}/', $value[1])) {
                $found[basename($file) . ':' . $value[2]] = $value[1];
            }
        }

        return $found;
    }
}
