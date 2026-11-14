<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Core\Strings: user-facing strings keyed by their English, counted messages
 * and plural rules, the extractor that builds the catalogue from CoreAPI::t()
 * and tn() calls and the reporting JavaScript's OWA.l() and OWA.ln(), the
 * catalogue file, and what a page sends the JavaScript.
 */
final class StringsTest extends TestCase
{
    protected function tearDown(): void
    {
        Strings::useTranslations(null);
    }

    public function testTheEnglishIsReturnedWhenNothingTranslatesIt(): void
    {
        Strings::useTranslations([]);

        $this->assertSame('Name is required.', \OWA\Core\CoreAPI::t('Name is required.'));
    }

    public function testATranslationIsFoundByItsEnglish(): void
    {
        Strings::useTranslations(['Name is required.' => 'Le nom est obligatoire.']);

        $this->assertSame('Le nom est obligatoire.', \OWA\Core\CoreAPI::t('Name is required.'));
    }

    public function testAContextKeepsTwoMeaningsOfTheSameEnglishApart(): void
    {
        Strings::useTranslations([
            'Open' => 'Ouvrir',
            'goal status' . Strings::CONTEXT_SEPARATOR . 'Open' => 'Ouvert',
        ]);

        $this->assertSame('Ouvrir', \OWA\Core\CoreAPI::t('Open'));
        $this->assertSame('Ouvert', \OWA\Core\CoreAPI::t('Open', 'goal status'));
    }

    public function testTheExtractorReadsLiteralsJoinsAndEscapes(): void
    {
        $source = <<<'PHP'
<?php
$a = \OWA\Core\CoreAPI::t( 'Name is required.' );
$b = CoreAPI::t( 'A funnel has at most %d steps; ' . 'this one has %d.' );
$c = \OWA\Core\CoreAPI::t( 'That report belongs to another user\'s team.' );
$d = Strings::t( "Double \"quoted\"." );
$e = \OWA\Core\CoreAPI::t( 'Open', 'goal status' );
$f = \OWA\Core\CoreAPI::t( $variable );
$g = $other->t( 'not ours' );
PHP;

        $calls = Strings::callsIn($source);

        $this->assertSame([
            'Name is required.',
            'A funnel has at most %d steps; this one has %d.',
            "That report belongs to another user's team.",
            'Double "quoted".',
            'Open',
            null,
        ], array_column($calls, 'text'));

        $this->assertSame('goal status', $calls[4]['context']);
    }

    public function testANonLiteralCallIsReportedNotCatalogued(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'owa-strings') . '.php';
        file_put_contents($file, "<?php\n\\OWA\\Core\\CoreAPI::t( \$x );\n\\OWA\\Core\\CoreAPI::t( 'Kept.' );\n");

        try {
            $map = Strings::extract([$file], $problems);

            $this->assertSame(['Kept.' => 'Kept.'], $map);
            $this->assertCount(1, $problems);
            $this->assertStringEndsWith(':2', array_key_first($problems));
        } finally {
            unlink($file);
        }
    }

    public function testTheCatalogueFileReadsBackAsTheMapItWasWrittenFrom(): void
    {
        $map = [
            'Name is required.' => 'Name is required.',
            "It's \$5 \\ more." => "It's \$5 \\ more.",
            'goal status' . Strings::CONTEXT_SEPARATOR . 'Open' => 'Open',
        ];

        $file = tempnam(sys_get_temp_dir(), 'owa-catalogue') . '.php';
        file_put_contents($file, Strings::catalogueSource($map));

        try {
            $this->assertSame($map, include $file);
        } finally {
            unlink($file);
        }
    }

    public function testACountedMessageIsTheEnglishSingularForOneAndThePluralOtherwise(): void
    {
        Strings::useTranslations([]);

        $this->assertSame('%d report', \OWA\Core\CoreAPI::tn('%d report', '%d reports', 1));
        $this->assertSame('%d reports', \OWA\Core\CoreAPI::tn('%d report', '%d reports', 0));
        $this->assertSame('%d reports', \OWA\Core\CoreAPI::tn('%d report', '%d reports', 2));
        $this->assertSame('3 reports', sprintf(\OWA\Core\CoreAPI::tn('%d report', '%d reports', 3), 3));
    }

    public function testACountedMessageTakesTheFormTheLocalesRulePicks(): void
    {
        Strings::useTranslations(['%d file' => ['%d plik', '%d pliki', '%d plików']], 'polish');

        $forms = array_map(fn($n) => \OWA\Core\CoreAPI::tn('%d file', '%d files', $n), [1, 2, 5, 22, 25]);

        $this->assertSame(['%d plik', '%d pliki', '%d plików', '%d pliki', '%d plików'], $forms);
    }

    public function testACountedMessageMissingAFormOrHeldAsOneStringIsTheEnglish(): void
    {
        Strings::useTranslations(['%d gap' => ['%d trou'], 'Next page' => 'Page suivante', '%d row' => ['%d ligne', '%d lignes']], 'english');

        $this->assertSame('%d trou', \OWA\Core\CoreAPI::tn('%d gap', '%d gaps', 1));
        $this->assertSame('%d gaps', \OWA\Core\CoreAPI::tn('%d gap', '%d gaps', 2));
        $this->assertSame('Next pages', \OWA\Core\CoreAPI::tn('Next page', 'Next pages', 2));
        $this->assertSame('%d row', \OWA\Core\CoreAPI::t('%d row'), 't() of a key holding forms is the English');
    }

    public function testACountedMessageTakesAContextLikeAnyOther(): void
    {
        Strings::useTranslations(['unit' . Strings::CONTEXT_SEPARATOR . '%d day' => ['%d jour', '%d jours']], 'french');

        $this->assertSame('%d jours', \OWA\Core\CoreAPI::tn('%d day', '%d days', 3, 'unit'));
        $this->assertSame('%d days', \OWA\Core\CoreAPI::tn('%d day', '%d days', 3));
    }

    /** The same table Strings.test.js holds OWA.pluralIndex() to. */
    public function testEachPluralRulePicksTheFormTheBrowserPicks(): void
    {
        $table = json_decode((string) file_get_contents(__DIR__ . '/fixtures/plural-rules.json'), true);

        foreach ($table as $rule => $counts) {
            if ($rule[0] === '_') {
                continue;
            }
            foreach ($counts as $n => $form) {
                $this->assertSame($form, Strings::pluralIndex((float) $n, $rule), "$rule, $n");
            }
        }
    }

    public function testALocaleIsGivenItsRuleWholeThenByItsLanguage(): void
    {
        $this->assertSame('french', Strings::pluralRule('pt_BR'));
        $this->assertSame('french', Strings::pluralRule('pt-br'));
        $this->assertSame('english', Strings::pluralRule('pt'));
        $this->assertSame('french', Strings::pluralRule('fr_CA'));
        $this->assertSame('russian', Strings::pluralRule('ru_RU'));
        $this->assertSame('none', Strings::pluralRule('ja'));
        $this->assertSame('english', Strings::pluralRule('en'));
        $this->assertSame('english', Strings::pluralRule('xx'));
    }

    public function testTheExtractorReadsCountedMessages(): void
    {
        $source = <<<'PHP'
<?php
$a = \OWA\Core\CoreAPI::tn( '%d report', '%d reports', $n );
$b = CoreAPI::tn( '%d day', '%d ' . 'days', max( 1, intdiv( $s, 86400 ) ), 'unit' );
$c = \OWA\Core\CoreAPI::tn( '%d row', $plural, $n );
$d = \OWA\Core\CoreAPI::tn( '%d row', '%d rows' );
$e = Strings::tn( "{$x} thing", '%d things', $n );
PHP;

        $calls = Strings::callsIn($source);

        $this->assertSame(['%d report', '%d day', null, null, null], array_column($calls, 'text'));
        $this->assertSame(['%d reports', '%d days', null, null, null], array_column($calls, 'plural'));
        $this->assertSame('unit', $calls[1]['context']);
        $this->assertSame(['tn', 'tn', 'tn', 'tn', 'tn'], array_column($calls, 'function'));
    }

    public function testTheExtractorReadsTheReportingJavaScript(): void
    {
        $source = <<<'JS'
// OWA.l( 'a comment' )
/* OWA.l( 'a block comment' ) */
var a = OWA.l( 'Next page' );
var b = OWA.l( "It's " + 'joined' );
var c = OWA.l( 'Open', 'goal status' );
var d = OWA.ln( '%d row', '%d rows', rows.filter( function ( r ) { return r.ok; } ).length );
var e = OWA.l( `No substitution` );
var f = OWA.l( `${ n } rows` );
var g = /OWA.l\( 'in a regex' \)/.test( s ) ? OWA.l( 'After \'a\' regexé' ) : x / 2;
var h = OWA.l( label );
var i = OWA.translate( 'forwarded' );
var j = other.l( 'not ours' );
var k = `${ OWA.l( 'Inside a template' ) } and ${ { a: 1 }.a }`;
JS;

        $calls = Strings::scriptCallsIn($source);

        $this->assertSame([
            'Next page', "It's joined", 'Open', '%d row', 'No substitution', null,
            "After 'a' regexé", null, 'Inside a template',
        ], array_column($calls, 'text'));

        $this->assertSame('goal status', $calls[2]['context']);
        $this->assertSame('%d rows', $calls[3]['plural']);
        $this->assertSame('OWA.ln', $calls[3]['function']);
        $this->assertSame(6, $calls[3]['line']);
        $this->assertSame(13, $calls[8]['line']);
    }

    /** A lexing slip -- a regular expression read as a division, say -- shows as brackets that do not pair. */
    public function testEveryModuleScriptLexesToPairedBrackets(): void
    {
        $files = glob(\OWA\Core\Env::root() . '/modules/*/src/{,*/,*/*/,*/*/*/}*.js', GLOB_BRACE);

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $count = ['(' => 0, ')' => 0, '[' => 0, ']' => 0, '{' => 0, '}' => 0];

            foreach (Strings::scriptTokens((string) file_get_contents($file)) as $t) {
                if ($t[0] === 'x' && isset($count[$t[1]])) {
                    $count[$t[1]]++;
                }
            }

            $this->assertSame([$count['('], $count['['], $count['{']], [$count[')'], $count[']'], $count['}']], $file);
        }
    }

    public function testExtractCataloguesPhpAndModuleScriptsAndListsTheScriptsKeys(): void
    {
        $root = sys_get_temp_dir() . '/owa-strings-' . bin2hex(random_bytes(4));
        mkdir($root . '/modules/Demo/src', 0700, true);
        mkdir($root . '/modules/Demo/dist', 0700, true);

        file_put_contents($root . '/modules/Demo/Thing.php', "<?php\n\\OWA\\Core\\CoreAPI::t( 'Saved.' );\n\\OWA\\Core\\CoreAPI::tn( '%d day', '%d days', \$n );\n");
        file_put_contents($root . '/modules/Demo/src/grid.js', "OWA.l( 'Saved.' );\nOWA.ln( '%d row', '%d rows', n );\n");
        file_put_contents($root . '/modules/Demo/dist/grid.js', "OWA.l( 'Built copy.' );\n");

        try {
            $map = Strings::extract([$root . '/modules'], $problems, $keys);

            $this->assertSame([], $problems);
            $this->assertSame([
                '%d day' => ['%d day', '%d days'],
                '%d row' => ['%d row', '%d rows'],
                'Saved.' => 'Saved.',
            ], $map);
            $this->assertSame(['%d row', 'Saved.'], $keys);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testOneKeyAsAStringAndACountedMessageIsReported(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'owa-strings') . '.php';
        file_put_contents($file, "<?php\n\\OWA\\Core\\CoreAPI::t( '%d day' );\n\\OWA\\Core\\CoreAPI::tn( '%d day', '%d days', 2 );\n"
            . "\\OWA\\Core\\CoreAPI::tn( '%d row', '%d rows', 2 );\n\\OWA\\Core\\CoreAPI::tn( '%d row', '%d rowz', 2 );\n");

        try {
            $map = Strings::extract([$file], $problems);

            $this->assertSame(['%d day' => '%d day', '%d row' => ['%d row', '%d rows']], $map);
            $this->assertSame([$file . ':3', $file . ':5'], array_keys($problems));
        } finally {
            unlink($file);
        }
    }

    public function testTheCatalogueReadsBackWithCountedMessages(): void
    {
        $map = [
            '%d report' => ['%d report', '%d reports'],
            'unit' . Strings::CONTEXT_SEPARATOR . '%d day' => ['%d day', "%d day's"],
            'Name is required.' => 'Name is required.',
        ];
        $keys = ['%d report', 'unit' . Strings::CONTEXT_SEPARATOR . '%d day'];

        $file = tempnam(sys_get_temp_dir(), 'owa-catalogue') . '.php';

        try {
            file_put_contents($file, Strings::catalogueSource($map));
            $this->assertSame($map, include $file);

            file_put_contents($file, Strings::scriptKeysSource($keys));
            $this->assertSame($keys, include $file);
        } finally {
            unlink($file);
        }
    }

    public function testAPageIsSentOnlyTheScriptsTranslationsAndOnlyWhenThereAreAny(): void
    {
        Strings::useTranslations([]);
        $this->assertNull(Strings::forScript(['Next page']), 'English sends nothing');

        Strings::useTranslations(['Name is required.' => 'Le nom est obligatoire.'], 'french');
        $this->assertNull(Strings::forScript(['Next page']), 'nothing the script uses is translated');

        Strings::useTranslations([
            'Name is required.' => 'Le nom est obligatoire.',
            'Next page'         => 'Page suivante',
            '%d row'            => ['%d ligne', '%d lignes'],
        ], 'french');

        $this->assertSame(
            ['strings' => ['Next page' => 'Page suivante', '%d row' => ['%d ligne', '%d lignes']], 'plural' => 'french'],
            Strings::forScript(['Next page', '%d row', 'Previous page'])
        );
    }

    public function testTheExtractorReadsTheLabelsOfANamedPhpTable(): void
    {
        $source = <<<'PHP'
<?php
namespace Demo;
use Other\Thing;
class Report {
    const ACTIONS = array(
        'a' => array( 'label' => 'Open it', 'description' => 'Not a label.' ),
        'b' => array( 'label' => 'Joined ' . 'not read', 'other' => array( 'label' => 'Nested' ) ),
        'c' => [ 'label' => $notALiteral ],
    );
    const OTHER = array( 'x' => array( 'label' => 'Not named' ) );
    public function f() { return Thing::class; }
}
PHP;

        $labels = Strings::tableLabelsIn($source, ['Report::ACTIONS']);

        $this->assertSame(['Open it', 'Nested'], array_column($labels, 'text'));
        $this->assertSame(6, $labels[0]['line']);
        $this->assertSame([], Strings::tableLabelsIn($source, ['Other::ACTIONS']));
    }

    public function testTheExtractorReadsTheLabelsOfANamedScriptTable(): void
    {
        $source = <<<'JS'
OWA.grid.PHRASES = {
    '==': 'is',
    '!=': "is not"
};
OWA.grid.CHOICES = {
    dimension: [ [ '=@', 'Contains' ], [ '==', 'Exactly matches' ] ],
    metric: [ [ '>', 'Greater than' ] ]
};
OWA.grid.SIZES = [ 10, 25 ];
OWA.grid.UNNAMED = { 'a': 'Not named' };
var x = OWA.grid.PHRASES[ '==' ];
JS;

        $labels = Strings::scriptTableLabelsIn($source, ['OWA.grid.PHRASES', 'OWA.grid.CHOICES', 'OWA.grid.SIZES']);

        $this->assertSame(['is', 'is not', 'Contains', 'Exactly matches', 'Greater than'], array_column($labels, 'text'));
        $this->assertSame([2, 3, 6, 6, 7], array_column($labels, 'line'));
    }

    public function testExtractCataloguesTableLabelsAsKeysTheScriptsUse(): void
    {
        $root = sys_get_temp_dir() . '/owa-strings-' . bin2hex(random_bytes(4));
        mkdir($root . '/modules/Demo/src', 0700, true);

        file_put_contents($root . '/modules/Demo/src/grid.js', "OWA.dataGrid.OPERATOR_PHRASES = { '==': 'is' };
OWA.l( 'Saved.' );
");
        file_put_contents($root . '/modules/Demo/ConfiguredReport.php', "<?php
class ConfiguredReport {
    const ROW_ACTIONS = array( 'h' => array( 'label' => 'Heatmap' ) );
}
");

        try {
            $map = Strings::extract([$root . '/modules'], $problems, $keys);

            $this->assertSame([], $problems);
            $this->assertSame(['Heatmap' => 'Heatmap', 'Saved.' => 'Saved.', 'is' => 'is'], $map);
            $this->assertSame(['Heatmap', 'Saved.', 'is'], $keys);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** config_dom.php puts forScript() on OWA.config for OWA.l() to read; in English, nothing. */
    public function testThePageCarriesTheScriptsTranslationsAndRule(): void
    {
        Strings::useTranslations([]);
        $this->assertStringNotContainsString('OWA.config.strings', $this->configDom());

        Strings::useTranslations(['Next page' => 'Page </script> suivante', 'Name is required.' => 'Le nom est obligatoire.'], 'french');
        $js = $this->configDom();

        $this->assertSame(1, preg_match('/^OWA\.config\.strings = (.*);$/m', $js, $strings));
        $this->assertStringNotContainsString('</script>', $strings[1]);
        $this->assertSame(['Next page' => 'Page </script> suivante'], json_decode($strings[1], true));
        $this->assertStringContainsString('OWA.config.plural_rule = "french";', $js);
    }

    private function configDom(): string
    {
        ob_start();

        try {
            include \OWA\Core\Env::root() . '/modules/Base/templates/config_dom.php';
        } finally {
            $js = (string) ob_get_clean();
        }

        return $js;
    }
}
