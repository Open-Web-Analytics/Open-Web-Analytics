<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Core\Strings: user-facing strings keyed by their English, the extractor that
 * builds the catalogue from CoreAPI::t() calls, and the catalogue file.
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
}
