<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\AutoUpdate\Releases;
use OWA\Module\Base\Classes\AutoUpdate\Signature;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Release signatures (Classes\AutoUpdate\Signature): Ed25519 over a
 * statement naming the release and its tarball's SHA-256, checked against
 * the keys the installation trusts.
 */
final class ReleaseSignatureTest extends TestCase
{
    private string $secret = '';
    private string $public = '';
    private string $sha    = '';

    protected function setUp(): void
    {
        $pair         = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->sha    = hash('sha256', 'a release');
    }

    protected function tearDown(): void
    {
        Signature::$trusted = null;
    }

    public function testASignatureVerifiesUnderItsKey(): void
    {
        $sig = Signature::sign('2.0.3', $this->sha, $this->secret);

        $this->assertSame(88, strlen($sig), '64 bytes, base64');
        Signature::verify('2.0.3', $this->sha, $sig . "\n", [$this->public]);
        Signature::verify('2.0.3', strtoupper($this->sha), $sig, ['not a key', $this->public]);
        $this->addToAssertionCount(2);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> what is refused, and why */
    public static function refused(): iterable
    {
        yield 'another version'  => ['2.0.4', 'same', 'is not signed by a key this installation trusts'];
        yield 'another tarball'  => ['2.0.3', 'other', 'is not signed by a key this installation trusts'];
        yield 'another key'      => ['2.0.3', 'key', 'is not signed by a key this installation trusts'];
        yield 'not a signature'  => ['2.0.3', 'garbage', 'is not a release signature'];
        yield 'no trusted key'   => ['2.0.3', 'nokeys', 'trusts no release signing key'];
    }

    /** @dataProvider refused */
    public function testWhatIsRefused(string $version, string $change, string $why): void
    {
        $sig  = Signature::sign('2.0.3', $this->sha, $this->secret);
        $sha  = $change === 'other' ? hash('sha256', 'another release') : $this->sha;
        $keys = [$this->public];

        if ($change === 'key') {
            $keys = [base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))];
        } elseif ($change === 'garbage') {
            $sig = base64_encode('too short');
        } elseif ($change === 'nokeys') {
            $keys = [];
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($why);
        Signature::verify($version, $sha, $sig, $keys);
    }

    /** The statement names the release and its tarball, so no signature can be taken for a bare hash's. */
    public function testWhatIsSigned(): void
    {
        $this->assertSame('OWA release 2.0.3, owa_2.0.3_packaged.tar, SHA-256 ' . $this->sha, Signature::message('2.0.3', strtoupper($this->sha)));
        $this->assertSame(Releases::ASSET, Signature::TARBALL, 'the packager script loads Signature alone, so it has its own copy');
        $this->assertSame(Releases::ASSET . '.sig', Signature::ASSET);
    }

    public function testTheTrustedKeysAreEd25519PublicKeys(): void
    {
        foreach (Signature::TRUSTED as $key) {
            $raw = base64_decode($key, true);
            $this->assertNotFalse($raw, "$key is base64");
            $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($raw), "$key is an Ed25519 public key");
        }

        $this->assertCount(2, array_unique(Signature::TRUSTED), 'the key in use and one spare');
        $this->assertSame(Signature::TRUSTED, Signature::keys(), 'with no test override');
    }

    public function testABadSigningKeyIsRefused(): void
    {
        $this->expectExceptionMessage('The signing key is not a base64 Ed25519 secret key.');
        Signature::sign('2.0.3', $this->sha, $this->public);
    }
}
