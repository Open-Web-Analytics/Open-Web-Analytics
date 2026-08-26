<?php

use PHPUnit\Framework\TestCase;

/**
 * Every status code in the catalogue means one thing.
 *
 * WHAT THIS EXISTS FOR
 *
 * conf/messages.php is one big array literal, and a repeated key in an array
 * literal is not an error in PHP -- the later one silently wins. Three codes
 * were declared twice:
 *
 *   2504  'Entity %s Schema Created.'  shadowed by  'Goal Saved.'
 *   3208  'That site does not exist.'  shadowed by  'Please remove the http://'
 *   3310  'E-mail Address is required.' shadowed by 'Password is required.'
 *
 * So three messages were unreachable, and any controller setting one of those
 * codes for the shadowed reason showed the other message instead. That is how
 * saving a custom report came to report "Goal Saved.": it borrowed 2504.
 *
 * Reading the file cannot find this -- by the time PHP has parsed it, the
 * duplicate is gone and the array simply has one entry. So this counts the
 * declarations in the SOURCE and compares them against the parsed array.
 */
final class MessageCatalogueTest extends TestCase
{
    private const PATH = __DIR__ . '/../conf/messages.php';

    /** @return array<int,int> code => how many times it is declared */
    private function declaredCounts(): array
    {
        $source = (string) file_get_contents(self::PATH);

        $counts = array();

        // Top-level entries only: `    NNNN => [`, at the indentation the file
        // uses for its own keys.
        if (preg_match_all('/^\s*(\d{3,5})\s*=>/m', $source, $matches)) {

            foreach ($matches[1] as $code) {
                $code = (int) $code;
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The catalogue as the application reads it.
     *
     * The file ASSIGNS to $_owa_messages rather than returning, so `require`
     * evaluates to 1 and a test that used its return value would be checking an
     * integer.
     */
    private function catalogue(): array
    {
        $_owa_messages = array();

        require self::PATH;

        return $_owa_messages;
    }

    public function testTheCatalogueParses(): void
    {
        $this->assertFileExists(self::PATH);

        $messages = $this->catalogue();

        $this->assertIsArray($messages);
        $this->assertGreaterThan(50, count($messages),
            'the catalogue looks truncated, so nothing below is checking much');
    }

    /**
     * The check the file needed.
     */
    public function testNoStatusCodeIsDeclaredTwice(): void
    {
        $duplicates = array();

        foreach ($this->declaredCounts() as $code => $count) {

            if ($count > 1) {
                $duplicates[] = $code;
            }
        }

        $this->assertSame(array(), $duplicates,
            "These status codes are declared more than once in conf/messages.php.\n"
            . "PHP keeps the LAST one silently, so every earlier message is\n"
            . "unreachable and anything setting the code shows the wrong text:\n  "
            . implode("\n  ", $duplicates));
    }

    /**
     * ...and the guard is not vacuous: the source really does declare as many
     * codes as the parsed array holds.
     *
     * If the regex above stopped matching -- a reformat, a different
     * indentation -- the duplicate check would pass by finding nothing at all.
     */
    public function testTheScanSeesEveryMessage(): void
    {
        $messages = $this->catalogue();
        $declared = $this->declaredCounts();

        $this->assertSame(count($messages), count($declared),
            'the source scan and the parsed catalogue disagree about how many '
            . 'codes exist, so the duplicate check is not reading the file properly');
    }

    /** Each message is usable: a headline and something to say. */
    public function testEveryMessageHasTextToShow(): void
    {
        $messages = $this->catalogue();

        $bad = array();

        foreach ($messages as $code => $message) {

            if (!is_array($message) || empty($message['message'])) {
                $bad[] = $code;
            }
        }

        $this->assertSame(array(), $bad,
            'these codes have no message text: ' . implode(', ', $bad));
    }

    /**
     * The codes the custom report screens set exist and say what they mean.
     *
     * Named individually rather than checked as a range: the point is that
     * creating, saving and deleting are three different sentences, which is
     * what borrowing one shared code cost.
     */
    public function testCustomReportCodesSayWhatHappened(): void
    {
        $messages = $this->catalogue();

        foreach (array(2510 => 'created', 2511 => 'saved', 2512 => 'deleted') as $code => $word) {

            $this->assertArrayHasKey($code, $messages, "status code $code is not defined");

            $this->assertStringContainsStringIgnoringCase('custom report', $messages[$code]['message'],
                "status code $code should be about a custom report");

            $this->assertStringContainsStringIgnoringCase($word, $messages[$code]['message'],
                "status code $code should say that the report was $word");
        }
    }
}
