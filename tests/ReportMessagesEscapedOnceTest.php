<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A report's refusal quotes the value it was sent as text. Escaping is the
 * output's: the banner escapes once, and JSON carries the text.
 */
final class ReportMessagesEscapedOnceTest extends TestCase
{
    private const VALUE = 'a"b<c>&d';

    public function testARangeErrorQuotesTheStartDateAsSent(): void
    {
        $error = \OWA\Module\Base\Classes\TimePeriod::getRangeError(
            [ 'startDate' => self::VALUE, 'endDate' => '20260810' ] );

        $this->assertStringContainsString( '"' . self::VALUE . '"', $error );
    }

    public function testARangeErrorQuotesTheEndDateAsSent(): void
    {
        $error = \OWA\Module\Base\Classes\TimePeriod::getRangeError(
            [ 'startDate' => '20260801', 'endDate' => self::VALUE ] );

        $this->assertStringContainsString( '"' . self::VALUE . '"', $error );
    }

    public function testACompareErrorQuotesTheComparisonAsSent(): void
    {
        $error = \OWA\Module\Base\Classes\TimePeriod::getCompareError( [ 'compare' => self::VALUE ] );

        $this->assertStringContainsString( '"' . self::VALUE . '"', $error );
    }

    /** The banner shows the value as sent, escaped once in the markup. */
    public function testTheBannerShowsTheValueAsSent(): void
    {
        $error = \OWA\Module\Base\Classes\TimePeriod::getRangeError(
            [ 'startDate' => self::VALUE, 'endDate' => '20260810' ] );

        $t = new \OWA\Core\Template( 'base' );
        $t->set( 'error_msg', \OWA\Module\Base\View\Error::bannerMessage( $error ) );
        $this->assertTrue( $t->set_template( 'msgs.php' ) );

        $html = $t->fetch();

        $this->assertStringContainsString( '&quot;a&quot;b&lt;c&gt;&amp;d&quot;', $html );
        $this->assertStringNotContainsString( '&amp;quot;', $html );
    }
}
