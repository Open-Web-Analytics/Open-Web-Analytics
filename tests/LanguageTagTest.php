<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The language stored for a request: the first language of its
 * Accept-Language header as ll-RR, not its first five characters.
 */
final class LanguageTagTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: string|null}> header => stored */
    public static function headers(): array
    {
        return [
            'Firefox, de'              => ['de,en-US;q=0.7,en;q=0.3', 'de'],
            'Chrome, en-US'            => ['en-US,en;q=0.9', 'en-US'],
            'Safari, a script'         => ['zh-Hant-TW,zh-Hant;q=0.9', 'zh-TW'],
            'a script and no region'   => ['zh-Hans', 'zh'],
            'case and an underscore'   => ['PT_br', 'pt-BR'],
            'a numeric region'         => ['es-419,es;q=0.9', 'es-419'],
            'a quality on the first'   => ['fr-CA;q=0.8, en;q=0.5', 'fr-CA'],
            'three letters'            => ['fil-PH', 'fil-PH'],
            'spaces'                   => ['  nl-be , en ', 'nl-BE'],
            'any language'             => ['*', null],
            'none'                     => ['', null],
            'not a tag'                => ['x-klingon', null],
            'not text'                 => [null, null],
        ];
    }

    /** @dataProvider headers */
    public function testTheFirstLanguageAsLanguageAndRegion($header, ?string $stored): void
    {
        $this->assertSame($stored, Helpers::languageTag($header));
    }
}
