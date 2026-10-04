<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Retention;

/**
 * The retention windows: how a setting becomes a window, how a cube's is
 * capped by raw's, and what the event-data confirmation says.
 */
final class RetentionTest extends TestCase
{
    const PROPERTY = '7781000000000009';

    private $raw;
    private $cube;

    protected function setUp(): void
    {
        $this->raw  = \OWA\Core\CoreAPI::getSetting('base', Retention::RAW);
        $this->cube = \OWA\Core\CoreAPI::getSetting('base', Retention::CUBE);
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', Retention::RAW, $this->raw);
        \OWA\Core\CoreAPI::setSetting('base', Retention::CUBE, $this->cube);

        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::clearScopedSetting('property', self::PROPERTY, 'base', Retention::CUBE);
        }
    }

    public function testZeroAndNonsenseKeepEverything(): void
    {
        foreach ([0, '0', '', null, 'lots', -3, false] as $value) {
            $this->assertSame(0, Retention::months($value), var_export($value, true));
        }

        $this->assertSame(24, Retention::months('24'));
        $this->assertNull(Retention::cutoff(0), 'no window, no cutoff');
        $this->assertSame((int) date('Ymd', strtotime('-12 months')), Retention::cutoff(12));
    }

    /** A cube keeps no more than raw, and 0 means "the same as raw". */
    public function testACubesWindowIsCappedByRaws(): void
    {
        $this->assertSame(24, Retention::effectiveCube(0, 24), '0 takes raw\'s');
        $this->assertSame(6, Retention::effectiveCube(6, 24));
        $this->assertSame(24, Retention::effectiveCube(36, 24), 'raw cannot rebuild what it no longer holds');
        $this->assertSame(36, Retention::effectiveCube(36, 0), 'raw keeps everything');
        $this->assertSame(0, Retention::effectiveCube(0, 0));
    }

    /** Shorter means keeps less; 0 keeps the most of all. */
    public function testShorterTreatsZeroAsForever(): void
    {
        $this->assertTrue(Retention::shorter(12, 24));
        $this->assertTrue(Retention::shorter(12, 0), 'any window is shorter than forever');
        $this->assertFalse(Retention::shorter(0, 12), 'forever is never shorter');
        $this->assertFalse(Retention::shorter(24, 24));
        $this->assertFalse(Retention::shorter(36, 24));
    }

    /** The settings, read: the install's raw window, and a Property's own cube window over the install's. */
    public function testWindowsAreReadFromTheSettings(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('scoped settings are stored');
        }

        \OWA\Core\CoreAPI::setSetting('base', Retention::RAW, 24);
        \OWA\Core\CoreAPI::setSetting('base', Retention::CUBE, 12);

        $this->assertSame(24, Retention::rawMonths());
        $this->assertSame(12, Retention::cubeMonths(self::PROPERTY), 'the install\'s, with no override');

        \OWA\Core\CoreAPI::setScopedSetting('property', self::PROPERTY, 'base', Retention::CUBE, 6);
        $this->assertSame(6, Retention::cubeMonths(self::PROPERTY), 'the Property\'s own');

        \OWA\Core\CoreAPI::setScopedSetting('property', self::PROPERTY, 'base', Retention::CUBE, 48);
        $this->assertSame(24, Retention::cubeMonths(self::PROPERTY), 'capped at raw\'s');

        $this->assertSame(24, Retention::monthsForTable(Retention::rawTable()));
        $this->assertSame(24, Retention::monthsForTable(\OWA\Module\Base\Classes\Cube\Cubes::tableFor(self::PROPERTY)));
    }

    /**
     * Shortening raw is the one change that deletes, so it asks with the
     * danger tone and says it cannot be undone, when, and how to go sooner.
     */
    public function testShorteningRawAsksAsAWarning(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', Retention::RAW, 0);

        $ask = Retention::preview(['raw' => 1200]);
        $text = implode(' ', $ask['paragraphs']);

        $this->assertTrue($ask['needed']);
        $this->assertSame('danger', $ask['tone']);
        $this->assertSame('Delete event data older than 1200 months?', $ask['title']);
        $this->assertSame('Save and delete', $ask['proceed']);
        $this->assertStringContainsString('cannot be recovered', $text);
        $this->assertStringContainsString('changing this setting back in the meantime keeps everything', $text);
        $this->assertStringContainsString('cmd=partition-drop', $text);
        $this->assertStringNotContainsString('visitor', $text, 'the visitor store is not raw\'s to expire');
        $this->assertMatchesRegularExpression('/^(At the next|Nothing is old enough to delete yet\. From the next)/',
            $ask['paragraphs'][0], 'when it happens, said as a sentence');
    }

    /** Lengthening raw, or leaving it, asks nothing: nothing is deleted. */
    public function testLengtheningRawAsksNothing(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', Retention::RAW, 24);
        \OWA\Core\CoreAPI::setSetting('base', Retention::CUBE, 0);

        $this->assertFalse(Retention::preview(['raw' => 24])['needed']);
        $this->assertFalse(Retention::preview(['raw' => 36])['needed']);
        $this->assertFalse(Retention::preview(['raw' => 0])['needed'], 'keeping everything deletes nothing');
    }

    /** The preview route reads only whole months; anything else is no change. */
    public function testThePreviewRouteReadsOnlyWholeMonths(): void
    {
        $proposed = \OWA\Module\Base\Controller\RetentionPreviewRest::proposed([
            'raw' => '12', 'cube_default' => 'lots', 'cube' => '-1', 'property_id' => 'x',
        ]);

        $this->assertSame(['raw' => 12], $proposed, 'a malformed value asks about no change, not "keep everything"');

        \OWA\Core\CoreAPI::setSetting('base', Retention::CUBE, 9);

        $this->assertSame(['property_id' => '5', 'cube' => 9],
            \OWA\Module\Base\Controller\RetentionPreviewRest::proposed(['property_id' => '5', 'cube_inherit' => '1']),
            'taking the install\'s window previews as that window');
    }

    /**
     * Blank is "keep everything" on the screen: valid, stored as no value, and
     * named in the field's placeholder. 0 is no longer a window.
     */
    public function testABlankWindowKeepsEverything(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach ([Retention::RAW, Retention::CUBE] as $key) {
            $this->assertNull($c->valueProblem('base', $key, ''), "$key may be left blank");
            $this->assertNull($c->normalizedValue('base', $key, ' '), 'blank is stored as no value, not 0');
            $this->assertNotNull($c->valueProblem('base', $key, '0'), '0 is not a window any more');
            $this->assertSame(12, $c->normalizedValue('base', $key, '12'));
        }

        $this->assertSame(['raw' => 0, 'cube_default' => 6],
            \OWA\Module\Base\Controller\RetentionPreviewRest::proposed(['raw' => '', 'cube_default' => '6']),
            'a field cleared is "keep everything", and is still asked about');

        $html = \OWA\Module\Base\Classes\SettingsForm::field('base', Retention::RAW);
        $this->assertStringContainsString('placeholder="Keep everything"', $html);
        $this->assertStringContainsString('value=""', $html, 'nothing set shows as blank, not 0');
    }

    /** Rebuild estimates are a range, never zero. */
    public function testRebuildMinutesAreARange(): void
    {
        $this->assertSame([1, 1], Retention::rebuildMinutes(10));
        $this->assertSame([15, 30], Retention::rebuildMinutes(1000000));
    }
}
