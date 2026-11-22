<?php
namespace OWA\Module\Base\Controller;

/**
 * cmd=version-info: what this code is, as JSON (Core\VersionInfo). Printed,
 * or written to --file, which is how `npm run build` writes release.json.
 *
 * Runs without OWA booted (needsBoot()), so it answers in a checkout with no
 * owa-config.php and no database, and on any server role.
 */
class VersionInfoCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Prints what this code is as JSON: its version, what it needs of a server, each module\'s schema and every schema update, with whether its rollback restores what it changed. Runs without OWA booted.',
            'arguments'   => [
                '--file=<path>' => 'Write the JSON to this file instead of printing it. The build writes release.json.',
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

        $json = json_encode( \OWA\Core\VersionInfo::toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

        return self::emit( $json, $params['file'] ?? null );
    }

    /**
     * Print some output, or write it to $file under a temporary name renamed
     * over it, so a reader never sees half of one.
     *
     * @param  string $output
     * @param  mixed  $file null to print; else --file's value
     * @return int exit code
     */
    public static function emit( $output, $file ) {

        if ( $file === null ) {

            fwrite( STDOUT, $output );

            return 0;
        }

        if ( ! is_string( $file ) || $file === '' ) {

            fwrite( STDERR, "--file needs a path: --file=<path>\n" );

            return 2;
        }

        $tmp = $file . '.tmp' . getmypid();

        if ( file_put_contents( $tmp, $output ) === false || ! rename( $tmp, $file ) ) {

            @unlink( $tmp );
            fwrite( STDERR, sprintf( "Could not write %s.\n", $file ) );

            return 1;
        }

        fwrite( STDOUT, sprintf( "Wrote %s.\n", $file ) );

        return 0;
    }

    /**
     * Booted, as when called through the scheduler or a test: the same output.
     *
     * @return void
     */
    public function action() {

        $code = self::runWithoutBoot( $this->params );

        if ( $code !== 0 ) {

            $this->fail( 'version-info failed: see above.' );
        }
    }
}
