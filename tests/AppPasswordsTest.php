<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\AppPasswords;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Application passwords: minted, checked, listed and revoked.
 *
 * Two users, so a test can tell "this user's password" from "a password".
 */
final class AppPasswordsTest extends TestCase
{
    const USER  = 'owa-app-password-test-a@example.test';
    const OTHER = 'owa-app-password-test-b@example.test';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('writes owa_app_password');
        }

        $this->clean();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        AppPasswords::revokeAllForUser(self::USER);
        AppPasswords::revokeAllForUser(self::OTHER);
    }

    private function storedRow(string $lookup): array
    {
        return (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf('SELECT * FROM %s WHERE lookup_id = ?', AppPasswords::table()), array($lookup));
    }

    public function testATokenIsOpaqueAndNeverShapedLikeALegacyKey(): void
    {
        $made = AppPasswords::create(self::USER, 'Reporting script');

        $this->assertIsArray($made);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{44}$/', $made['token']);
        $this->assertSame(substr($made['token'], 0, 12), $made['lookup_id']);
        // A legacy apiKey is 32 hex characters.
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{32}$/', $made['token']);
        $this->assertFalse(AppPasswords::looksLikeToken(md5('a legacy key')));
    }

    public function testTheSecretIsNotStored(): void
    {
        $made = AppPasswords::create(self::USER, 'Reporting script');
        $row  = $this->storedRow($made['lookup_id']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['secret_hash']);
        $this->assertStringNotContainsString(substr($made['token'], 12), implode('|', $row));
    }

    public function testATokenVerifiesAsItsUserAndScope(): void
    {
        $made = AppPasswords::create(self::USER, 'Dashboard export', AppPasswords::SCOPE_READ);
        $row  = AppPasswords::verify($made['token']);

        $this->assertSame(self::USER, $row['user_id'] ?? null);
        $this->assertSame('read', $row['scope'] ?? null);
        $this->assertSame('Dashboard export', $row['name'] ?? null);
        $this->assertArrayNotHasKey('secret_hash', $row);
    }

    public function testAnAlteredTokenDoesNotVerify(): void
    {
        $made  = AppPasswords::create(self::USER, 'Reporting script');
        $token = $made['token'];
        $last  = $token[43] === 'a' ? 'b' : 'a';

        // Right lookup id, wrong secret.
        $this->assertNull(AppPasswords::verify(substr($token, 0, 43) . $last));
        // Unknown lookup id.
        $this->assertNull(AppPasswords::verify(str_repeat('Z', 12) . substr($token, 12)));
        // Wrong shape.
        $this->assertNull(AppPasswords::verify(substr($token, 0, 43)));
        $this->assertNull(AppPasswords::verify($token . "\n"));
        $this->assertNull(AppPasswords::verify(''));
    }

    /** A new token crosses its redirect sealed: opened only by its user, unaltered, and soon. */
    public function testASealedTokenOpensOnlyForItsUserAndOnlyBriefly(): void
    {
        $made   = AppPasswords::create(self::USER, 'Reporting script');
        $sealed = AppPasswords::sealReveal($made, self::USER);

        $this->assertStringNotContainsString($made['token'], $sealed);
        $this->assertSame($made, AppPasswords::openReveal($sealed, self::USER));

        $this->assertNull(AppPasswords::openReveal($sealed, self::OTHER));
        $this->assertNull(AppPasswords::openReveal($sealed, ''));
        $this->assertNull(AppPasswords::openReveal('', self::USER));
        $this->assertNull(AppPasswords::openReveal('not*base64', self::USER));

        $flipped = $sealed;
        $flipped[30] = $flipped[30] === 'A' ? 'B' : 'A';
        $this->assertNull(AppPasswords::openReveal($flipped, self::USER));

        // Sealed longer ago than REVEAL_TTL.
        $key = new \ReflectionMethod(AppPasswords::class, 'revealKey');
        $key->setAccessible(true);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $old   = json_encode(array('made' => $made, 'sealed_at' => time() - AppPasswords::REVEAL_TTL - 1));
        $stale = sodium_bin2base64($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $old, self::USER, $nonce, $key->invoke(null)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->assertNull(AppPasswords::openReveal($stale, self::USER));
    }

    public function testEachPasswordIsIndependent(): void
    {
        $one = AppPasswords::create(self::USER, 'First');
        $two = AppPasswords::create(self::USER, 'Second');

        $this->assertNotSame($one['token'], $two['token']);
        $this->assertTrue(AppPasswords::revoke($one['lookup_id'], self::USER));

        $this->assertNull(AppPasswords::verify($one['token']), 'A revoked password still works.');
        $this->assertNotNull(AppPasswords::verify($two['token']), 'Revoking one revoked another.');
    }

    public function testAUserCannotRevokeAnotherUsersPassword(): void
    {
        $theirs = AppPasswords::create(self::OTHER, 'Theirs');

        $this->assertFalse(AppPasswords::revoke($theirs['lookup_id'], self::USER));
        $this->assertNotNull(AppPasswords::verify($theirs['token']));
    }

    public function testAUsersListHoldsOnlyTheirPasswordsNewestFirst(): void
    {
        AppPasswords::create(self::USER, 'Older');
        \OWA\Core\CoreAPI::dbSingleton()->query(
            sprintf('UPDATE %s SET created_at = created_at - 100 WHERE user_id = ?', AppPasswords::table()),
            array(self::USER));
        AppPasswords::create(self::USER, 'Newer');
        AppPasswords::create(self::OTHER, 'Not theirs');

        $list = AppPasswords::forUser(self::USER);

        $this->assertSame(array('Newer', 'Older'), array_column($list, 'name'));
        $this->assertArrayNotHasKey('secret_hash', $list[0]);
        $this->assertSame(array(), AppPasswords::forUser(''));
    }

    public function testRevokingAllOfAUsersPasswordsLeavesOthers(): void
    {
        $mine   = AppPasswords::create(self::USER, 'Mine');
        $theirs = AppPasswords::create(self::OTHER, 'Theirs');

        $this->assertTrue(AppPasswords::revokeAllForUser(self::USER));

        $this->assertNull(AppPasswords::verify($mine['token']));
        $this->assertNotNull(AppPasswords::verify($theirs['token']));
        $this->assertFalse(AppPasswords::revokeAllForUser(''), 'An empty user would match nothing, or everything.');
    }

    public function testAPasswordNeedsAUserANameAndAKnownScope(): void
    {
        $this->assertFalse(AppPasswords::create('', 'Name'));
        $this->assertFalse(AppPasswords::create(self::USER, '   '));
        $this->assertFalse(AppPasswords::create(self::USER, 'Name', 'admin'));
        $this->assertSame(array(), AppPasswords::forUser(self::USER));

        $long = AppPasswords::create(self::USER, str_repeat('n', 300));
        $this->assertSame(255, strlen($long['name']));
    }

    public function testUseIsRecordedAtMostOnceAMinute(): void
    {
        $made = AppPasswords::create(self::USER, 'Reporting script');
        $id   = $made['lookup_id'];

        AppPasswords::recordUse($id, '203.0.113.7', 1000000);
        $this->assertSame(1000000, (int) $this->storedRow($id)['last_used_at']);
        $this->assertSame('203.0.113.7', $this->storedRow($id)['last_used_ip']);

        AppPasswords::recordUse($id, '203.0.113.8', 1000059);
        $this->assertSame(1000000, (int) $this->storedRow($id)['last_used_at'], 'Written again within the minute.');

        AppPasswords::recordUse($id, '203.0.113.8', 1000060);
        $this->assertSame(1000060, (int) $this->storedRow($id)['last_used_at']);
        $this->assertSame('203.0.113.8', $this->storedRow($id)['last_used_ip']);
    }
}
