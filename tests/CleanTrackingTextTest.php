<?php

use PHPUnit\Framework\TestCase;

/**
 * Sanitize::cleanTrackingText(): a tracking value is kept as the text that was
 * sent -- valid UTF-8, no control characters -- with nothing escaped or
 * stripped.
 */
final class CleanTrackingTextTest extends TestCase
{
    private function clean($v)
    {
        return \OWA\Module\Base\Classes\Sanitize::cleanTrackingText($v);
    }

    public function testTextIsKeptAsSent(): void
    {
        foreach (array('a<b and c>d', '5<10', 'Using <div> tags', 'Tom & Jerry', '"quoted"',
                       "it's", 'Café 日本', 'Caf&eacute; stays as typed') as $s) {
            $this->assertSame($s, $this->clean($s));
        }
    }

    public function testControlCharactersGoAndLineBreaksStay(): void
    {
        $this->assertSame("ab\tc\nd", $this->clean("a\x00b\tc\nd\x07"));
    }

    public function testTextThatIsNotUtf8IsConverted(): void
    {
        $this->assertSame('Café', $this->clean("Caf\xe9"));
    }

    public function testAMultibyteCharacterIsNotCut(): void
    {
        // Byte 0xCA leads many UTF-8 characters; the old hidden-space step removed it alone.
        $this->assertSame('ʀ', $this->clean('ʀ'));
    }

    public function testArraysAreCleanedValueByValueAndOtherTypesAreLeft(): void
    {
        $this->assertSame(array('k' => 'a<b', 'n' => 3, 'f' => false), $this->clean(array('k' => "a<b\x00", 'n' => 3, 'f' => false)));
        $this->assertNull($this->clean(null));
    }
}
