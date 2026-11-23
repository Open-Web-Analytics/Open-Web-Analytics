<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * Release signatures: what proves a release tarball is OWA's, wherever it
 * was downloaded from.
 *
 * The Release Packager signs each release with an Ed25519 key held as a
 * secret of a GitHub environment that needs a maintainer's approval
 * (.github/scripts/release-sign.php), and publishes the signature beside the
 * tarball as owa_<version>_packaged.tar.sig: the 64-byte detached signature,
 * base64, on one line. Step 1 checks it against the public keys below after
 * the download matches its SHA-256 (Staging::fetch()). A release that is not
 * signed, or not signed by one of these keys, is not staged: there is no
 * soft-fail. GitHub's digest only says the download is the file the listing
 * names; this says OWA's maintainers released it, so the listing and the
 * tarball can come from any host.
 *
 * What is signed is a statement naming the version and the tarball's
 * SHA-256 (message()), never a bare hash: a signature over one release
 * cannot be offered as another's.
 *
 * Plain PHP with no other OWA class: the packager's script requires this file
 * on its own.
 */
class Signature {

    /** The tarball, as Releases::ASSET names it (a test holds them equal). */
    const TARBALL = 'owa_%s_packaged.tar';

    /** The signature asset beside a release's tarball. */
    const ASSET = 'owa_%s_packaged.tar.sig';

    /**
     * The public keys, base64, a release must be signed by one of. Two: the
     * one releases are signed with, and a spare held offline, so the first
     * can be retired by a release that both trust. Keys never expire; one is
     * retired by a release that no longer lists it.
     */
    const TRUSTED = [
        'n4fhW6m/lbuLxK2LedFdbbD0/0VpY+sK6MyyLWF9zP4=', // key 1: signs releases (the release-signing environment)
        'mikd6F9brxsZEEBWKaXkcGaQRZOB//kJvsbCoE1zlsM=', // key 2: the spare, held offline
    ];

    /**
     * Tests only: the keys trusted in place of TRUSTED.
     *
     * @var list<string>|null
     */
    public static $trusted = null;

    /** @return list<string> */
    public static function keys() {

        return self::$trusted ?? self::TRUSTED;
    }

    /**
     * What a release's signature signs.
     *
     * @param  string $version
     * @param  string $sha256 the tarball's, hex
     * @return string
     */
    public static function message( $version, $sha256 ) {

        return sprintf( 'OWA release %s, %s, SHA-256 %s', $version, sprintf( self::TARBALL, $version ), strtolower( (string) $sha256 ) );
    }

    /**
     * The signature file's contents for a release, signed with $secret.
     *
     * @param  string $version
     * @param  string $sha256
     * @param  string $secret the 64-byte Ed25519 secret key, base64
     * @return string base64, no newline
     * @throws \RuntimeException
     */
    public static function sign( $version, $sha256, $secret ) {

        self::needSodium();
        $key = base64_decode( trim( (string) $secret ), true );

        if ( $key === false || strlen( $key ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {

            throw new \RuntimeException( 'The signing key is not a base64 Ed25519 secret key.' );
        }

        return base64_encode( sodium_crypto_sign_detached( self::message( $version, $sha256 ), $key ) );
    }

    /**
     * Throws unless $signature is a valid signature of this release by one of
     * $keys (default keys()).
     *
     * @param  string            $version
     * @param  string            $sha256
     * @param  string            $signature the signature file's contents
     * @param  list<string>|null $keys
     * @return void
     * @throws \RuntimeException
     */
    public static function verify( $version, $sha256, $signature, ?array $keys = null ) {

        self::needSodium();
        $keys = $keys ?? self::keys();

        if ( ! $keys ) {

            throw new \RuntimeException( 'This installation trusts no release signing key, so it cannot install a release itself.' );
        }

        $sig = base64_decode( trim( (string) $signature ), true );

        if ( $sig === false || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {

            throw new \RuntimeException( sprintf( '%s is not a release signature.', sprintf( self::ASSET, $version ) ) );
        }

        foreach ( $keys as $key ) {

            $public = base64_decode( (string) $key, true );

            if ( $public !== false && strlen( $public ) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                    && sodium_crypto_sign_verify_detached( $sig, self::message( $version, $sha256 ), $public ) ) {

                return;
            }
        }

        throw new \RuntimeException( sprintf( '%s is not signed by a key this installation trusts.', sprintf( self::TARBALL, $version ) ) );
    }

    /** @return void */
    private static function needSodium() {

        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {

            throw new \RuntimeException( 'Release signatures need PHP\'s sodium extension in the CLI.' );
        }
    }
}
