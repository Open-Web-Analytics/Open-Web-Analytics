<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A sign-in with a password is decided by the stored hash, compared whole.
 *
 * The typed password is checked with password_verify() against a
 * password_hash() hash, or compared in full with a 1.x unsalted md5 hash. It
 * never falls back to the cookie credential, which is derived from four
 * characters of the stored hash.
 */
final class SignInPasswordTest extends TestCase
{
    /** An Auth whose user is the given row, not a database lookup. */
    private function auth(string $user_id, string $stored): \OWA\Core\Auth
    {
        $auth = new class extends \OWA\Core\Auth {
            public $row = array();
            function getUser() {
                $this->u = \OWA\Core\CoreAPI::entityFactory('base.user');
                $this->u->set('user_id', $this->row['user_id']);
                $this->u->set('password', $this->row['password']);
            }
            function reconstructAuthCredential( $user_id ) {
                return $this->generateAuthCredential( $user_id, $this->row['password'] );
            }
            function saveCredentials() {}
        };
        $auth->row = array('user_id' => $user_id, 'password' => $stored);

        return $auth;
    }

    protected function tearDown(): void
    {
        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $p  = (new ReflectionObject($cu))->getProperty('is_authenticated');
        $p->setAccessible(true);
        $p->setValue($cu, false);
    }

    public function testAStoredBcryptHashIsMatchedOnlyByItsPassword(): void
    {
        $stored = password_hash('right password', PASSWORD_DEFAULT);

        $this->assertTrue($this->auth('u', $stored)->passwordMatches('right password', $stored));
        $this->assertFalse($this->auth('u', $stored)->passwordMatches('wrong password', $stored));
        $this->assertFalse($this->auth('u', $stored)->passwordMatches('', $stored));
    }

    public function testAStoredMd5HashIsComparedWhole(): void
    {
        $stored = \OWA\Core\Lib::encryptOldPassword('old password');

        $this->assertTrue($this->auth('u', $stored)->passwordMatches('old password', $stored));
        $this->assertFalse($this->auth('u', $stored)->passwordMatches('other', $stored));
    }

    /**
     * A wrong password whose md5 shares characters 8-11 with the stored hash
     * does not sign in: those four characters are all the cookie credential
     * is derived from.
     */
    public function testAWrongPasswordSharingTheHashFragmentDoesNotSignIn(): void
    {
        do {
            $stored = password_hash('right password ' . mt_rand(), PASSWORD_DEFAULT);
        } while (!preg_match('/^[0-9a-f]{4}$/', substr($stored, 8, 4)));

        $fragment = substr($stored, 8, 4);

        for ($i = 0; ; $i++) {
            $guess = 'guess' . $i;
            if (substr(\OWA\Core\Lib::encryptOldPassword($guess), 8, 4) === $fragment) {
                break;
            }
        }

        $auth = $this->auth('someone', $stored);

        $this->assertNotTrue($auth->authByInput('someone', $guess));
        $this->assertFalse(\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated());
    }
}
