<?php

use PHPUnit\Framework\TestCase;

/**
 * Lib::utf8Encode(): UTF-8 and ASCII pass through, anything else is read as
 * Windows-1252. It asked mb_detect_encoding() for the source encoding, which
 * answers false for Latin-1, and passed that false to iconv().
 */
final class Utf8EncodeTest extends TestCase
{
    public function testUtf8AndAsciiAreUnchanged(): void
    {
        $this->assertSame('plain', \OWA\Core\Lib::utf8Encode('plain'));
        $this->assertSame('Café 日本', \OWA\Core\Lib::utf8Encode('Café 日本'));
    }

    public function testLatin1IsConverted(): void
    {
        $this->assertSame('Café', \OWA\Core\Lib::utf8Encode("Caf\xe9"));
    }

    public function testWindows1252PunctuationIsConverted(): void
    {
        // 0x93 0x94 are curly quotes in Windows-1252 and control codes in ISO-8859-1.
        $this->assertSame('“hi”', \OWA\Core\Lib::utf8Encode("\x93hi\x94"));
    }

    public function testTheResultIsAlwaysValidUtf8(): void
    {
        $this->assertTrue(mb_check_encoding(\OWA\Core\Lib::utf8Encode("\xff\xfe\x80 x"), 'UTF-8'));
    }
}
