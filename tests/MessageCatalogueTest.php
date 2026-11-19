<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every status code in the catalogue means one thing.
 *
 * WHAT THIS EXISTS FOR
 *
 * conf/messages.php is one big array literal, and a repeated key in an array
 * literal is not an error in PHP -- the later one silently wins. A shadowed
 * message is unreachable, and a controller setting its code shows the other
 * message instead.
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

        $counts = [];

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
        $_owa_messages = [];

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
        $duplicates = [];

        foreach ($this->declaredCounts() as $code => $count) {

            if ($count > 1) {
                $duplicates[] = $code;
            }
        }

        $this->assertSame([], $duplicates,
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

        $bad = [];

        foreach ($messages as $code => $message) {

            if (!is_array($message) || empty($message['message'])) {
                $bad[] = $code;
            }
        }

        $this->assertSame([], $bad,
            'these codes have no message text: ' . implode(', ', $bad));
    }

    /**
     * A message is text: msgs.php escapes it, so markup in one is shown as
     * its tags.
     */
    public function testNoMessageCarriesMarkup(): void
    {
        $bad = [];

        foreach ($this->catalogue() as $code => $message) {

            foreach ((array) $message as $part => $text) {

                if (preg_match('/<[a-zA-Z\/!]/', (string) $text)) {
                    $bad[] = "$code $part";
                }
            }
        }

        $this->assertSame([], $bad, 'markup in: ' . implode(', ', $bad));
    }

    /**
     * Every code a controller sets actually exists.
     *
     * A code with no entry renders as a blank message -- the screen says an
     * error happened and then says nothing about it. Cheap to check and
     * impossible to notice by reading, since the two live in different files.
     */
    public function testEveryCodeAControllerSetsIsDefined(): void
    {
        $messages = $this->catalogue();
        $missing  = [];

        foreach (glob(__DIR__ . '/../modules/*/Controller/*.php') as $file) {

            $source = (string) file_get_contents($file);

            if (!preg_match_all(
                "/(?:setStatusCode\(\s*|set\(\s*'(?:error_code|status_code)'\s*,\s*)(\d{3,5})/",
                $source, $matches)) {
                continue;
            }

            foreach ($matches[1] as $code) {

                if (!isset($messages[(int) $code])) {
                    $missing[] = basename($file) . ' sets ' . $code;
                }
            }
        }

        $this->assertSame([], $missing,
            "These controllers set a status code the catalogue does not define, so the\n"
            . "screen reports an error and then shows no message:\n  "
            . implode("\n  ", $missing));
    }

    /**
     * The form screens report a FORM error, not the CLI-updates message.
     *
     * Controller::doAction() sets validation_errors before calling
     * errorAction(), and msgs.php shows error_msg only when there are none, so a
     * wrong code is suppressed on the ordinary path and would appear the moment
     * that branch changed.
     *
     * Pinned by MEANING rather than by number: the message these screens show
     * has to be about the form, and must not be the updates one.
     */
    public function testFormScreensDoNotReportTheUpdatesMessage(): void
    {
        $messages = $this->catalogue();

        if (!owa_test_db_available()) {
            $this->markTestSkipped('errorAction() resolves the current site');
        }

        foreach (['SitesAdd', 'SitesEditSettings', 'UsersEdit'] as $name) {

            $class      = '\\OWA\\Module\\Base\\Controller\\' . $name;
            $controller = new $class([]);
            $controller->errorAction();

            $code = $controller->data['error_code'] ?? null;

            $this->assertNotNull($code, "$name sets no error code in errorAction()");

            $message = $messages[(int) $code]['message'] ?? '';

            $this->assertStringNotContainsStringIgnoringCase('command line', $message,
                "$name reports the CLI-updates message on a form error");

            $this->assertStringContainsStringIgnoringCase('form', $message,
                "$name should tell the reader their form had a problem");
        }
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

        foreach ([2510 => 'created', 2511 => 'saved', 2512 => 'deleted'] as $code => $word) {

            $this->assertArrayHasKey($code, $messages, "status code $code is not defined");

            $this->assertStringContainsStringIgnoringCase('custom report', $messages[$code]['message'],
                "status code $code should be about a custom report");

            $this->assertStringContainsStringIgnoringCase($word, $messages[$code]['message'],
                "status code $code should say that the report was $word");
        }
    }
}
