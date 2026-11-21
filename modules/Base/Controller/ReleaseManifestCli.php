<?php
namespace OWA\Module\Base\Controller;

/**
 * cmd=release-manifest: every file under this OWA directory with its
 * SHA-256, size and mode, as JSON (Core\ReleaseManifest). The Release
 * Packager runs it in the unpacked tarball and adds the result as
 * manifest.json.
 *
 * Runs without OWA booted (needsBoot()).
 */
class ReleaseManifestCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Prints every file under the OWA directory with its SHA-256, size and mode, as JSON. The Release Packager runs it in an unpacked release to write manifest.json. Runs without OWA booted.',
            'arguments'   => [
                '--file=<path>' => 'Write the JSON to this file instead of printing it.',
            ],
        ];
    }

    /** See \OWA\Core\Controller\Cli::needsBoot(). */
    public static function needsBoot() {

        return false;
    }

    /**
     * @param  array<string, mixed> $params
     * @return int
     */
    public static function runWithoutBoot( array $params ) {

        $json = json_encode( \OWA\Core\ReleaseManifest::build( \OWA\Core\Env::root() ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

        return VersionInfoCli::emit( $json, $params['file'] ?? null );
    }

    /**
     * Booted: the same output.
     *
     * @return void
     */
    public function action() {

        if ( self::runWithoutBoot( $this->params ) !== 0 ) {

            $this->fail( 'release-manifest failed: see above.' );
        }
    }
}
