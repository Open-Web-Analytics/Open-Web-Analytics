<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * OWA's releases on GitHub, and the one an installation updates to.
 *
 * ONE API CALL lists the releases with their assets (auto_update_releases_url:
 * by default OWA's update host, updates.openwebanalytics.com/releases, which
 * redirects to GitHub's API).
 * The asset a release ships is owa_<version>_packaged.tar (ASSET), with its
 * SHA-256 in the asset's digest; a SHA256SUMS asset (SUMS_ASSET) is the
 * fallback for a release without one. Tag, asset name and digest are a frozen
 * contract: an installed release finds the next one by them.
 *
 * @phpstan-type Release array{version: string, step: string, url: string, asset_url: string, size: int, sha256: string|null, sums_url: string|null, sig_url: string|null}
 */
class Releases {

    /** The tarball's name; %s is the version. */
    const ASSET = 'owa_%s_packaged.tar';

    /** Lines of "<sha256>  <asset name>", for a release whose asset has no digest. */
    const SUMS_ASSET = 'SHA256SUMS';

    /** Seconds the listing may take. */
    const TIMEOUT = 20;

    /**
     * A release the listing gave, its downloads moved to <base>/<version>/<file>
     * (the update host, auto_update_download_url): the tarball, and the
     * signature and SHA256SUMS where the listing has them. The listing's
     * SHA-256 stays, to check the download against.
     *
     * @param  Release $release
     * @param  string  $base
     * @return Release
     */
    public static function via( array $release, $base ) {

        $at = rtrim( (string) $base, '/' ) . '/' . rawurlencode( $release['version'] ) . '/';

        return [
            'asset_url' => $at . sprintf( self::ASSET, $release['version'] ),
            'sig_url'   => $release['sig_url'] === null ? null : $at . sprintf( self::ASSET, $release['version'] ) . '.sig',
            'sums_url'  => $release['sums_url'] === null ? null : $at . self::SUMS_ASSET,
        ] + $release;
    }

    /**
     * A release by its version alone, without the listing: its tarball and
     * signature at <base>/<version>/<file>, as GitHub serves a release's
     * assets. No digest: the signature, which names the version and the
     * tarball's SHA-256, is what proves the download (Downloads::download()).
     *
     * @param  string $version
     * @param  string $installed
     * @param  string $base auto_update_download_url
     * @return Release
     */
    public static function byTag( $version, $installed, $base ) {

        $at = rtrim( (string) $base, '/' ) . '/' . rawurlencode( (string) $version ) . '/' . sprintf( self::ASSET, $version );

        return [
            'version'   => (string) $version,
            'step'      => (string) ( Version::step( (string) $installed, (string) $version ) ?? Version::PATCH ),
            'url'       => sprintf( 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/releases/tag/%s', rawurlencode( (string) $version ) ),
            'asset_url' => $at,
            'size'      => 0,
            'sha256'    => null,
            'sums_url'  => null,
            'sig_url'   => $at . '.sig',
        ];
    }

    /**
     * Tests only: answers get() in place of the network, given its arguments.
     *
     * @var (callable(string, int, resource|null): string)|null
     */
    public static $fetcher = null;

    /**
     * The release to update to: not a draft or a pre-release, in the same
     * major version (a major release is never automatic), higher than
     * $installed, a step $level allows (PATCH: patches only; MINOR: patches and
     * minors), and shipping the tarball. The highest version wins, whatever
     * order or date GitHub lists them in.
     *
     * @param  array<int, mixed> $releases decoded from the listing
     * @param  string            $installed
     * @param  string            $level     Version::PATCH or Version::MINOR
     * @return Release|null
     */
    public static function choose( array $releases, $installed, $level ) {

        $best = null;

        foreach ( $releases as $r ) {

            $r = is_array( $r ) ? $r : [];

            if ( ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) ) {

                continue;
            }

            $version = (string) ( $r['tag_name'] ?? '' );

            if ( Version::parse( $version ) === null ) {

                continue;
            }

            $step = Version::step( $installed, $version );

            if ( $step === null || $step === Version::MAJOR || ( $step === Version::MINOR && $level !== Version::MINOR ) ) {

                continue;
            }

            $release = self::describe( $r, $version, $step );

            if ( $release && ( $best === null || Version::compare( $version, $best['version'] ) > 0 ) ) {

                $best = $release;
            }
        }

        return $best;
    }

    /**
     * One named release, for an operator who chose it: not a draft, and
     * shipping the tarball; a pre-release is theirs to choose.
     *
     * @param  array<int, mixed> $releases decoded from the listing
     * @param  string            $version
     * @param  string            $installed
     * @return Release|null
     */
    public static function find( array $releases, $version, $installed ) {

        foreach ( $releases as $r ) {

            $r = is_array( $r ) ? $r : [];

            if ( empty( $r['draft'] ) && (string) ( $r['tag_name'] ?? '' ) === (string) $version ) {

                return self::describe( $r, (string) $version, (string) ( Version::step( $installed, $version ) ?? Version::PATCH ) );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $r
     * @param  string               $version
     * @param  string               $step
     * @return Release|null null when it does not ship the tarball
     */
    private static function describe( array $r, $version, $step ) {

        $tarball = null;
        $sums    = null;
        $sig     = null;

        foreach ( (array) ( $r['assets'] ?? [] ) as $asset ) {

            $asset = is_array( $asset ) ? $asset : [];
            $name  = (string) ( $asset['name'] ?? '' );

            if ( $name === sprintf( self::ASSET, $version ) ) {

                $tarball = $asset;

            } elseif ( $name === self::SUMS_ASSET ) {

                $sums = (string) ( $asset['browser_download_url'] ?? '' );

            } elseif ( $name === sprintf( Signature::ASSET, $version ) ) {

                $sig = (string) ( $asset['browser_download_url'] ?? '' );
            }
        }

        $url = (string) ( $tarball['browser_download_url'] ?? '' );

        if ( ! $tarball || ! str_starts_with( $url, 'https://' ) ) {

            return null;
        }

        $digest = (string) ( $tarball['digest'] ?? '' );

        return [
            'version'   => $version,
            'step'      => $step,
            'url'       => (string) ( $r['html_url'] ?? '' ),
            'asset_url' => $url,
            'size'      => (int) ( $tarball['size'] ?? 0 ),
            'sha256'    => preg_match( '/^sha256:([0-9a-f]{64})$/', $digest, $m ) ? $m[1] : null,
            'sums_url'  => $sums !== '' && str_starts_with( (string) $sums, 'https://' ) ? $sums : null,
            'sig_url'   => $sig !== null && str_starts_with( $sig, 'https://' ) ? $sig : null,
        ];
    }

    /**
     * The SHA-256 SHA256SUMS gives for $name, or null.
     *
     * @param  string $sums
     * @param  string $name
     * @return string|null
     */
    public static function sumFor( $sums, $name ) {

        foreach ( preg_split( '/\r?\n/', (string) $sums ) ?: [] as $line ) {

            if ( preg_match( '/^([0-9a-f]{64}) [ *](\S+)$/', trim( $line ), $m ) && ( $m[2] === $name || $m[2] === './' . $name ) ) {

                return $m[1];
            }
        }

        return null;
    }

    /**
     * The listing as requested: $url with from=<the installed version>, which
     * the update host's access logs count installations by. Nothing else about
     * the installation is sent.
     *
     * @param  string $url
     * @param  string $installed
     * @return string
     */
    public static function listingUrl( $url, $installed ) {

        return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . 'from=' . rawurlencode( (string) $installed );
    }

    /**
     * The decoded listing.
     *
     * @param  string $url
     * @return array<int, mixed>
     * @throws \RuntimeException when it cannot be fetched or read
     */
    public static function fetch( $url ) {

        $body = self::get( $url, self::TIMEOUT );
        $list = json_decode( $body, true );

        if ( ! is_array( $list ) || ( $list !== [] && ! array_is_list( $list ) ) ) {

            throw new \RuntimeException( sprintf( '%s did not answer with a list of releases.', $url ) );
        }

        return $list;
    }

    /**
     * A GET's body, or a RuntimeException saying why there is none.
     *
     * @param  string        $url
     * @param  int           $timeout seconds
     * @param  resource|null $sink    an open file to write the body into instead, left open
     * @return string the body; '' when sunk to a file
     */
    public static function get( $url, $timeout, $sink = null ) {

        if ( self::$fetcher !== null ) {

            return ( self::$fetcher )( $url, $timeout, $sink );
        }

        $client = new \GuzzleHttp\Client( [ 'timeout' => $timeout, 'connect_timeout' => 10 ] );

        // One stream for every response, a redirect's too, emptied as each
        // one's headers arrive, so what is left is the last one's body; let
        // go after, so the handle stays the caller's to close.
        $body = $sink === null ? null : new \GuzzleHttp\Psr7\Stream( $sink );

        try {

            $response = $client->request( 'GET', $url, array_filter( [
                'headers'         => [
                    'User-Agent' => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'owa_user_agent' ),
                    'Accept'     => 'application/vnd.github+json, application/octet-stream;q=0.9, */*;q=0.1',
                ],
                'allow_redirects' => [ 'max' => 5, 'protocols' => [ 'https' ] ],
                'http_errors'     => true,
                'sink'            => $body,
                'on_headers'      => $sink === null ? null : static function () use ( $sink ) {

                    ftruncate( $sink, 0 );
                    rewind( $sink );
                },
            ], static fn ( $v ) => $v !== null ) );

        } catch ( \GuzzleHttp\Exception\GuzzleException $e ) {

            throw new \RuntimeException( sprintf( 'Fetching %s failed: %s', $url, $e->getMessage() ), 0, $e );

        } finally {

            if ( $body !== null ) {

                $body->detach();
            }
        }

        return $sink === null ? (string) $response->getBody() : '';
    }
}
