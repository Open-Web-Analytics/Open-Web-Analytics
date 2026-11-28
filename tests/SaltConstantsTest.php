<?php

use OWA\Core\CoreAPI;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * CoreAPI::getSalt() reads each scheme's key and salt from fixed constant
 * names, the ones owa-config.php defines and the boot check requires.
 *
 * No database: this runs in CI.
 */
final class SaltConstantsTest extends TestCase
{
    /** @var mixed */
    private $saved;

    protected function setUp(): void
    {
        $this->saved = CoreAPI::getSetting('base', 'ns');
        self::forgetSalts();
    }

    protected function tearDown(): void
    {
        CoreAPI::setSetting('base', 'ns', $this->saved);
        self::forgetSalts();
    }

    private static function forgetSalts(): void
    {
        $ref = new ReflectionProperty(CoreAPI::class, 'salts');
        $ref->setValue(null, null);
    }

    /** The scheme's constants that are defined, key then salt. */
    private static function expected(string $key, string $salt): string
    {
        return (defined($key) ? (string) constant($key) : '') . (defined($salt) ? (string) constant($salt) : '');
    }

    /** The names are the ones the boot check requires, and SECRET is not a scheme. */
    public function testTheNamesAreTheRequiredSecrets(): void
    {
        $this->assertSame(['NONCE', 'AUTH'], array_keys(CoreAPI::SALT_CONSTANTS));
        $this->assertSame(\OWA\Core\Requirements::SECRET_CONSTANTS,
            array_merge(...array_values(CoreAPI::SALT_CONSTANTS)));
        $this->assertSame('', CoreAPI::getSalt('secret'));
    }

    public function testEachSchemeReadsItsConstants(): void
    {
        $this->assertTrue(defined('OWA_AUTH_KEY'), 'tests/bootstrap.php defines it when there is no config file');

        $this->assertSame(self::expected('OWA_AUTH_KEY', 'OWA_AUTH_SALT'), CoreAPI::getSalt('auth'));
        $this->assertSame(self::expected('OWA_AUTH_KEY', 'OWA_AUTH_SALT'), CoreAPI::getSalt('AUTH'));
        $this->assertSame(self::expected('OWA_NONCE_KEY', 'OWA_NONCE_SALT'), CoreAPI::getSalt('nonce'));
        $this->assertStringStartsWith((string) constant('OWA_AUTH_KEY'), CoreAPI::getSalt('auth'));
    }

    /**
     * A setting named ns does not name the constants. Before its removal,
     * with ns at zz_, getSalt() read ZZ_AUTH_KEY.
     */
    public function testNoSettingRenamesTheConstants(): void
    {
        if (!defined('ZZ_AUTH_KEY')) {
            define('ZZ_AUTH_KEY', 'owatest-not-this-key');
            define('ZZ_NONCE_KEY', 'owatest-not-this-nonce');
        }

        CoreAPI::setSetting('base', 'ns', 'zz_');

        $this->assertSame(self::expected('OWA_AUTH_KEY', 'OWA_AUTH_SALT'), CoreAPI::getSalt('auth'));
        $this->assertSame(self::expected('OWA_NONCE_KEY', 'OWA_NONCE_SALT'), CoreAPI::getSalt('nonce'));
        $this->assertStringNotContainsString('owatest-not-this', CoreAPI::getSalt('auth') . CoreAPI::getSalt('nonce'));
    }
}
