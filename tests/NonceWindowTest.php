<?php

use PHPUnit\Framework\TestCase;

/**
 * A nonce verifies in the window it was minted in and the one after.
 *
 * getNonceTimeInterval() is ceil(time() / nonce_expiration_period): a fixed
 * bucket of the epoch. Checking only the current bucket refused a form drawn at
 * 07:59:59 and posted at 08:00:00 with the default 7200s period -- the e2e
 * profile spec failed that way when its run crossed the hour.
 */
final class NonceWindowTest extends TestCase
{
    private const ACTION = 'base.myProfileSave';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function verifies($nonce, string $action = self::ACTION): bool
    {
        $controller = new \OWA\Module\Base\Controller\ReportingHome([]);

        return (bool) $controller->verifyNonce($nonce, $action);
    }

    private function mintedAt(int $offset): string
    {
        return \OWA\Core\CoreAPI::createNonce(self::ACTION, \OWA\Core\CoreAPI::getNonceTimeInterval() + $offset);
    }

    public function testTheCurrentWindowVerifies(): void
    {
        $this->assertTrue($this->verifies(\OWA\Core\CoreAPI::createNonce(self::ACTION)));
    }

    public function testThePreviousWindowVerifies(): void
    {
        $this->assertTrue($this->verifies($this->mintedAt(-1)));
    }

    public function testTwoWindowsBackIsRefused(): void
    {
        $this->assertFalse($this->verifies($this->mintedAt(-2)));
    }

    /** A window that has not started yet is not one a form could have been drawn in. */
    public function testTheNextWindowIsRefused(): void
    {
        $this->assertFalse($this->verifies($this->mintedAt(1)));
    }

    public function testAnotherActionsNonceIsRefused(): void
    {
        $this->assertFalse($this->verifies($this->mintedAt(0), 'base.sitesDelete'));
        $this->assertFalse($this->verifies($this->mintedAt(-1), 'base.sitesDelete'));
    }

    /** The nonce arrives as a request param, which can be an array or absent. */
    public function testANonStringIsRefused(): void
    {
        $this->assertFalse($this->verifies(null));
        $this->assertFalse($this->verifies([$this->mintedAt(0)]));
    }

    /** The window for a period, read on either side of the call so a boundary crossed between them still passes. */
    private static function windowWith($period): array
    {
        $saved = \OWA\Core\CoreAPI::getSetting('base', 'nonce_expiration_period');
        \OWA\Core\CoreAPI::setSetting('base', 'nonce_expiration_period', $period);

        try {
            $before   = time();
            $interval = \OWA\Core\CoreAPI::getNonceTimeInterval();
            $after    = time();
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'nonce_expiration_period', $saved);
        }

        return [$interval, $before, $after];
    }

    /** A period of 0 or less, or not a number, is 7200 seconds: no division by zero, no negative window. */
    public function testAPeriodOfZeroOrLessIsTheDefault(): void
    {
        $this->assertSame(7200, \OWA\Core\CoreAPI::NONCE_EXPIRATION_PERIOD);

        foreach ([0, '0', -60, 'not a number', null] as $period) {
            [$interval, $before, $after] = self::windowWith($period);

            $this->assertContains($interval, [ceil($before / 7200), ceil($after / 7200)], var_export($period, true));
        }
    }

    public function testAPositivePeriodIsUsed(): void
    {
        [$interval, $before, $after] = self::windowWith(60);

        $this->assertContains($interval, [ceil($before / 60), ceil($after / 60)]);
    }

    /** The declaration takes a whole number of at least 1, at every write. */
    public function testTheSettingIsAPositiveWholeNumber(): void
    {
        $settings = \OWA\Core\CoreAPI::configSingleton();

        $this->assertNull($settings->valueProblem('base', 'nonce_expiration_period', 1));
        $this->assertNull($settings->valueProblem('base', 'nonce_expiration_period', '7200'));

        foreach ([0, -1, 'abc', '1.5'] as $value) {
            $this->assertSame('nonce_expiration_period is a whole number of at least 1.',
                $settings->valueProblem('base', 'nonce_expiration_period', $value), var_export($value, true));
        }
    }

    /** $tick defaults to the current window, so existing callers mint what they did before. */
    public function testTheDefaultTickIsTheCurrentWindow(): void
    {
        $this->assertSame(\OWA\Core\CoreAPI::createNonce(self::ACTION), $this->mintedAt(0));
    }
}
