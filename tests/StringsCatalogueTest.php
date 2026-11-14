<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/UserFacingStrings.php';

/**
 * The English catalogue, conf/strings/en.php, and the keys the reporting
 * JavaScript uses, conf/strings/script-keys.php, are what the code says -- and
 * the user-facing messages and labels are in the catalogue.
 *
 * The catalogue is GENERATED (cli.php cmd=strings-extract), so a message added
 * or reworded without regenerating it fails here, and so does a user-facing
 * message written as a bare literal where t() belongs. Every metric and
 * dimension label, report definition title and label table value is in it.
 * TemplateStringsGuardTest does the same for the templates.
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
            'MetricSets::SETS'              => 'Core/MetricSets.php',
            'OWA.dataGrid.EMPTY_TESTS'      => 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js',
            'OWA.dataGrid.OPERATOR_PHRASES' => 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js',
            'OWA.dataGrid.CONDITIONS'       => 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js',
            'OWA.filterPills.OPERATORS'     => 'modules/Base/src/reporting/v1/owa.filterpills.js',
            'OWA.filterPills.EMPTY_TESTS'   => 'modules/Base/src/reporting/v1/owa.filterpills.js',
        ];

        $this->assertSame(array_keys($where), Strings::LABEL_TABLES);

        $scriptKeys = include Strings::scriptKeysPath();

        foreach ($where as $table => $file) {
            $source = (string) file_get_contents(\OWA\Core\Env::root() . '/' . $file);
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
            array_column(Strings::tableLabelsIn((string) file_get_contents(\OWA\Core\Env::root() . '/Core/ConfiguredReport.php'), ['ConfiguredReport::ROW_ACTIONS']), 'text'));
    }

    /**
     * Where a user-facing message or label is set, it is set through t() or
     * tn(): a bare string literal in one of these positions is text the
     * catalogue misses. UserFacingStrings::inMessageSinks() says which
     * positions.
     *
     * @dataProvider messageSites
     */
    public function testUserFacingMessagesGoThroughT(string $file): void
    {
        $source = (string) file_get_contents(\OWA\Core\Env::root() . '/' . $file);
        $found  = array_map(function ($f) use ($file) {
            return UserFacingStrings::describe($file, $f);
        }, UserFacingStrings::inMessageSinks($source, basename($file) === 'settings.php'));

        $this->assertSame([], $found, "untranslatable messages in $file:\n" . implode("\n", $found));
    }

    /** File => why its message sinks are not read by a person. */
    private const NOT_USER_FACING = [
        'modules/Domstream/Classes/Chunk.php' => 'refuse() records why a recording chunk was dropped, for the log',
        'Core/ServerRole.php'                 => 'bootProblem() messages go to the error log and the CLI; a web request is answered through t()',
    ];

    public static function messageSites(): array
    {
        $root  = dirname(__DIR__) . '/';
        $files = array_merge(
            glob($root . 'modules/*/Controller/*.php'),
            glob($root . 'modules/*/View/*.php'),
            glob($root . 'modules/*/Module.php'),
            glob($root . 'modules/*/settings.php'),
            glob($root . 'modules/*/Classes/*.php'),
            glob($root . 'modules/*/Classes/*/*.php'),
            glob($root . 'Core/*.php'),
            glob($root . 'Core/Validation/*.php'),
            [$root . 'conf/messages.php']
        );

        $out = [];

        foreach ($files as $path) {
            $file = substr($path, strlen($root));

            // CLI output is for operators and is out of the catalogue's scope.
            if (substr($file, -7) !== 'Cli.php' && !isset(self::NOT_USER_FACING[$file])) {
                $out[$file] = [$file];
            }
        }

        return $out;
    }

    /**
     * What the message-sink guard catches, each with its line.
     *
     * @dataProvider bareMessages
     */
    public function testABareMessageIsFound(string $source, int $line, string $what, bool $settings = false): void
    {
        $found = UserFacingStrings::inMessageSinks("<?php\n" . $source, $settings);

        $this->assertCount(1, $found, print_r($found, true));
        $this->assertSame($line + 1, $found[0]['line']);
        $this->assertSame($what, $found[0]['what']);
    }

    public static function bareMessages(): array
    {
        return [
            'an errorMsg'               => ["\$v = array( 'errorMsg' => 'Name is required.' );", 1, "'errorMsg' value"],
            'a label in a ternary'      => ["\$a = array(\n 'label' => \$on ? \\OWA\\Core\\CoreAPI::t( 'On' ) : 'Off' );", 2, "'label' value"],
            'a sprintf format'          => ["\$a = array( 'title' => sprintf( '%d rows', \$n ) );", 1, "'title' value"],
            'a headline set on a view'  => ["\$this->body->set( 'headline', 'User Roster' );", 1, "set( 'headline' ) value"],
            'a page title'              => ["\$this->setTitle( 'Realtime' );", 1, 'setTitle() message'],
            'a REST request error'      => ["\$rsm->addRequestError( 'rowValues must be JSON.' );", 1, 'addRequestError() message'],
            'a nav label'               => ["\$this->addNavigationLinkInSubGroup( 'Content', \$this->reportRef( 'pages' ), 'Pages', 1 );", 1, 'addNavigationLinkInSubGroup() label'],
            'a labelled setting'        => ["return array( 'x' => array( 'label' => \\OWA\\Core\\CoreAPI::t( 'X' ),\n 'description' => 'What x does.' ) );", 2, "'description' value", true],
        ];
    }

    /**
     * What passes: messages through t() or tn(), machine values, label tables.
     *
     * @dataProvider goodMessages
     */
    public function testTranslatedMessagesAndMachineValuesPass(string $source, bool $settings = false): void
    {
        $this->assertSame([], UserFacingStrings::inMessageSinks("<?php\n" . $source, $settings));
    }

    public static function goodMessages(): array
    {
        return [
            'through t()'               => ["\$v = array( 'errorMsg' => \\OWA\\Core\\CoreAPI::t( 'Name is required.' ) );"],
            'through tn() and sprintf()' => ["\$a = array( 'title' => sprintf( \\OWA\\Core\\CoreAPI::tn( '%d row', '%d rows', \$n ), \$n ) );"],
            'a value read from data'    => ["\$a = array( 'label' => \$row['label'] ?? \$key );"],
            'a compared literal'        => ["\$a = array( 'label' => \$type === 'Some Type' ? \$x : \$y );"],
            'a label table'             => ["class R { const ROW_ACTIONS = array( 'h' => array( 'label' => 'Heatmap' ) ); }"],
            'another set() key'         => ["\$this->set( 'reportId', 'Some Report' );"],
            'an unlabelled setting'     => ["return array( 'x' => array( 'description' => 'For the wiki.' ) );", true],
            'a description elsewhere'   => ["\$this->registerReport( 'r', array( 'description' => 'For the wiki.' ) );"],
        ];
    }

    /**
     * Every registered settings fieldset's legend and description is in the
     * catalogue. Fieldsets are positional arrays in each Module.php, which the
     * message-sink scan cannot recognise, so they are checked as data: an
     * unwrapped legend is never extracted, and so is missing here.
     */
    public function testEverySettingsFieldsetIsCatalogued(): void
    {
        $catalogue = include Strings::path(Strings::SOURCE_LOCALE);
        $seen      = 0;

        foreach ((array) \OWA\Core\CoreAPI::configSingleton()->registeredFieldSets() as $id => $set) {
            foreach (array('legend', 'description') as $key) {
                $text = (string) ($set[$key] ?? '');

                if ($text !== '') {
                    $this->assertArrayHasKey($text, $catalogue,
                        "fieldset $id: its $key \"$text\" is not catalogued -- wrap it in \\OWA\\Core\\CoreAPI::t() where the fieldset is registered");
                    $seen++;
                }
            }
        }

        // Base's own fieldsets alone, as on a fresh install with no other module active.
        $this->assertGreaterThan(10, $seen);
    }

    /** Every metric and dimension label and description a module ships is in the catalogue. */
    public function testEveryVocabularyLabelIsCatalogued(): void
    {
        $catalogue = include Strings::path(Strings::SOURCE_LOCALE);
        $seen      = 0;

        foreach (glob(\OWA\Core\Env::root() . '/modules/*/config/{metrics,dimensions}.php', GLOB_BRACE) as $file) {
            $declaration = include $file;
            $kind        = basename($file, '.php');

            foreach ((array) $declaration[$kind] as $name => $entry) {
                foreach (Strings::VOCABULARY_KEYS as $key) {
                    if (isset($entry[$key]) && $entry[$key] !== '') {
                        $this->assertArrayHasKey($entry[$key], $catalogue, "$kind $name: $key");
                        $seen++;
                    }
                }
            }
        }

        $this->assertGreaterThan(200, $seen);
    }

    /** Every report definition's title, suffix, description and widget titles are in the catalogue. */
    public function testEveryReportDefinitionStringIsCatalogued(): void
    {
        $catalogue = include Strings::path(Strings::SOURCE_LOCALE);
        $seen      = 0;

        foreach (glob(\OWA\Core\Env::root() . '/modules/*/reports/*.json') as $file) {
            $definition = json_decode((string) file_get_contents($file), true);
            $strings    = [];

            foreach (Strings::REPORT_KEYS as $key) {
                $strings[] = $definition[$key] ?? '';
            }

            foreach ((array) ($definition['widgets'] ?? []) as $widget) {
                foreach (Strings::REPORT_WIDGET_KEYS as $key) {
                    $strings[] = $widget[$key] ?? '';
                }
            }

            foreach (array_filter($strings) as $text) {
                $this->assertArrayHasKey($text, $catalogue, basename($file));
                $seen++;
            }
        }

        $this->assertGreaterThan(100, $seen);
    }

    /** Every VALUE_TABLES constant exists, and each of its values is in the catalogue. */
    public function testEveryValueTableIsCatalogued(): void
    {
        $catalogue = include Strings::path(Strings::SOURCE_LOCALE);

        foreach (Strings::VALUE_TABLES as $table) {
            $class = strstr($table, '::', true);
            $value = constant((class_exists('OWA\\Core\\' . $class) ? 'OWA\\Core\\' : 'OWA\\Module\\Base\\Classes\\') . $table);

            $this->assertNotEmpty($value, $table);

            foreach ((array) $value as $text) {
                $this->assertArrayHasKey($text, $catalogue, $table);
            }
        }
    }
}
