<?php
namespace OWA\Module\Base\Controller;

/**
 * cmd=version-info: what this code is, as JSON (Core\VersionInfo). Printed,
 * or written to --file, which is how `npm run build` writes release.php.
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
                '--file' => [
                    'type'        => 'string',
                    'value'       => '<path>',
                    'description' => 'Write it to this file instead of printing it. A .php file, such as the release.php a release ships with, starts with a line that exits when a web server runs it.',
                ],
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

        $info = \OWA\Core\VersionInfo::toArray();
        $file = \OWA\Core\Params::values( static::params(), $params )['file'];

        // A .php file is release.php: guarded, so a web server shows nothing of it.
        return self::emit( is_string( $file ) && str_ends_with( $file, '.php' )
            ? \OWA\Core\VersionInfo::encode( $info )
            : json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n", $file );
    }

    /**
     * Print some output, or write it to $file under a temporary name renamed
     * over it, so a reader never sees half of one.
     *
     * @param  string $output
     * @param  string|null $file null to print; else --file's value
     * @return int exit code
     */
    public static function emit( $output, $file ) {

        if ( $file === null ) {

            fwrite( STDOUT, $output );

            return 0;
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
