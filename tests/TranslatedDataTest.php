<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What the server translates as data -- metric and dimension labels, report
 * definitions, the label tables -- and the locale-aware dates and numbers it
 * shows. English is unchanged; another locale gets its translations and its
 * own date and number forms.
 */
final class TranslatedDataTest extends TestCase
{
    protected function tearDown(): void
    {
        Strings::useTranslations(null);
        Strings::useLocale(null);
    }

    public function testAVocabularysLabelsAndDescriptionsAreTranslatedAndItsNamesAreNot(): void
    {
        Strings::useTranslations(['Page Views' => 'Seitenaufrufe', 'The total number of pages viewed.' => 'Alle Seitenaufrufe.']);

        $out = \OWA\Core\Module::translatedVocabulary([
            'pageViews' => ['label' => 'Page Views', 'description' => 'The total number of pages viewed.', 'column' => 'id', 'group' => 'Site Usage'],
            'exits'     => ['label' => 'Exits', 'column' => 'id'],
        ]);

        $this->assertSame(['label' => 'Seitenaufrufe', 'description' => 'Alle Seitenaufrufe.', 'column' => 'id', 'group' => 'Site Usage'], $out['pageViews']);
        $this->assertSame(['label' => 'Exits', 'column' => 'id'], $out['exits']);
    }

    /** The registry Base registered at boot holds the English in English. */
    public function testTheRegisteredVocabularyIsTheConfigsEnglish(): void
    {
        $metrics = \OWA\Core\CoreAPI::getAllMetrics();

        $this->assertSame('Page Views', reset($metrics['pageViews'])['label']);
    }

    public function testAReportDefinitionIsTranslatedBeforeItsPlaceholdersAreFilled(): void
    {
        Strings::useTranslations(['Browser Detail: {browserType}' => 'Browser: {browserType}', 'Top pages' => 'Meistgesehen']);

        $out = \OWA\Core\ConfiguredReport::translated([
            'title'   => 'Browser Detail: {browserType}',
            'widgets' => [['type' => 'grid', 'title' => 'Top pages', 'query' => ['dimensions' => 'pagePath']], ['type' => 'trend']],
        ]);

        $this->assertSame('Browser: {browserType}', $out['title']);
        $this->assertSame('Meistgesehen', $out['widgets'][0]['title']);
        $this->assertSame(['dimensions' => 'pagePath'], $out['widgets'][0]['query']);
        $this->assertSame(['type' => 'trend'], $out['widgets'][1]);
    }

    public function testTheDefaultMoreLabelIsTranslatedAndAnAuthorsIsNot(): void
    {
        $cr = \OWA\Module\Base\Classes\CustomReports::class;

        Strings::useTranslations([$cr::MORE_LABEL => 'Ganzer Bericht »', 'Half' => 'Halb']);

        $this->assertSame('Ganzer Bericht »', $cr::shownMoreLabel($cr::MORE_LABEL));
        $this->assertSame('All the browsers', $cr::shownMoreLabel('All the browsers'));
        $this->assertSame([4 => 'Third', 6 => 'Halb', 8 => 'Two thirds', 12 => 'Full'], $cr::translatedTable($cr::WIDTHS));
    }

    public function testASettingsLevelIsNamedInTheLocale(): void
    {
        $form = \OWA\Module\Base\Classes\SettingsForm::class;

        $this->assertSame('install', $form::levelName('install'));
        $this->assertSame('Observation Profile', $form::levelName('profile'));
        $this->assertSame('elsewhere', $form::levelName('elsewhere'));

        Strings::useTranslations([Strings::key('Property', 'settings level') => 'Propriété']);

        $this->assertSame('Propriété', $form::levelName('property'));
    }

    public function testOutMarkupEscapesTheSentenceAndNotItsMarkup(): void
    {
        $t = new \OWA\Core\Template('base');

        ob_start();
        $t->outMarkup('Send <it> in the %s header & "%s".', '<code>Authorization</code>', '<b>x</b>');
        $html = (string) ob_get_clean();

        $this->assertSame('Send &lt;it&gt; in the <code>Authorization</code> header &amp; &quot;<b>x</b>&quot;.', $html);
    }

    public function testEnglishDatesAndNumbersAreAsBefore(): void
    {
        Strings::useLocale('en');

        $ts = gmmktime(14, 5, 0, 11, 3, 2026);

        $this->assertSame('3 Nov 2026', Strings::formatDate($ts, 'dMMMy', 'j M Y', 'UTC'));
        $this->assertSame('Tue 3 Nov, 14:05', Strings::formatDate($ts, 'EEEdMMMHHmm', 'D j M, H:i', 'UTC'));
        $this->assertSame('1,234,567', Strings::formatNumber(1234567));
        $this->assertSame('1,234.50', Strings::formatNumber(1234.5, 2));
        $this->assertSame('12.35%', Strings::formatPercent(0.12345));
    }

    public function testAnotherLocaleWritesDatesAndNumbersItsOwnWay(): void
    {
        if (!class_exists('\IntlDateFormatter')) {
            $this->markTestSkipped('needs the intl extension');
        }

        Strings::useLocale('de_DE');

        $ts = gmmktime(14, 5, 0, 11, 3, 2026);

        $this->assertSame('3. Nov. 2026', Strings::formatDate($ts, 'dMMMy', 'j M Y', 'UTC'));
        $this->assertSame('1.234.567', Strings::formatNumber(1234567));
        $this->assertSame('1.234,50', Strings::formatNumber(1234.5, 2));
        $this->assertSame("12,35\u{a0}%", Strings::formatPercent(0.12345));
    }

    /** Displayed numbers follow the locale; the value beside them stays a number. */
    public function testAResultSetsFormattedValuesFollowTheLocale(): void
    {
        if (!class_exists('\NumberFormatter')) {
            $this->markTestSkipped('needs the intl extension');
        }

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager();

        $this->assertSame('1,234', $rsm->numberFormatter(1234));
        $this->assertSame('Yes', $rsm->booleanFormatter(1));

        Strings::useLocale('de_DE');
        Strings::useTranslations(['Yes' => 'Ja']);

        $this->assertSame('1.234', $rsm->numberFormatter(1234));
        $this->assertSame("50,00\u{a0}%", $rsm->formatPercentage(0.5));
        $this->assertSame('Ja', $rsm->booleanFormatter(1));
        $this->assertNull($rsm->numberFormatter(null));
    }
}
