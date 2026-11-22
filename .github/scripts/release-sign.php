<?php
/**
 * The Release Packager's signature: owa_<version>_packaged.tar.sig beside
 * the tarball, as Classes\AutoUpdate\Signature defines it.
 *
 *   OWA_RELEASE_SIGNING_KEY=<base64 secret key> php .github/scripts/release-sign.php <version>
 *
 * Run from the directory holding the tarball. The key is a secret of the
 * release-signing environment, which needs a maintainer's approval. The new
 * signature is checked against the keys this checkout trusts before it is
 * kept: a release signed by a key the installations do not hold could not
 * install itself, and would have to be installed by hand.
 */

require dirname( __DIR__, 2 ) . '/modules/Base/Classes/AutoUpdate/Signature.php';

use OWA\Module\Base\Classes\AutoUpdate\Signature;

$version = (string) ( $argv[1] ?? '' );
$tarball = sprintf( Signature::TARBALL, $version );
$secret  = (string) getenv( 'OWA_RELEASE_SIGNING_KEY' );

if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ! is_file( $tarball ) ) {

    fwrite( STDERR, "Usage: php .github/scripts/release-sign.php <version>, beside owa_<version>_packaged.tar.\n" );
    exit( 2 );
}

if ( $secret === '' ) {

    fwrite( STDERR, "OWA_RELEASE_SIGNING_KEY is not set: the release is not signed.\n" );
    exit( 1 );
}

try {

    $sha       = (string) hash_file( 'sha256', $tarball );
    $signature = Signature::sign( $version, $sha, $secret );

    Signature::verify( $version, $sha, $signature );

} catch ( \RuntimeException $e ) {

    fwrite( STDERR, $e->getMessage() . "\n" );
    exit( 1 );
}

file_put_contents( sprintf( Signature::ASSET, $version ), $signature . "\n" );
fwrite( STDOUT, sprintf( "Signed %s (SHA-256 %s).\n", $tarball, $sha ) );
