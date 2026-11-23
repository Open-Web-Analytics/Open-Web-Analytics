<?php
namespace OWA\Module\Base\Classes\AutoUpdate;

/**
 * Whether a staged release can be installed here: Step 1's preflight.
 *
 * Two halves. requirements() judges the release's release.json requirements
 * (Core\Requirements::toArray() as that release wrote it) against this
 * server, seen from the CLI: PHP, its extensions, the database server over
 * OWA's own connection, and owa-config.php's constants. The rest of the
 * environment -- the web server's PHP among it -- is taken as it was when OWA
 * was installed: requirements change only in major releases, which are never
 * automatic. install() judges what copying the release needs: every
 * directory the release writes in writable, and room for its files.
 *
 * Each returns its problems as sentences, [] when there are none. Pure where
 * it can be: requirements() is given the environment, environment() reads it.
 *
 * @phpstan-type Environment array{php: string, int_size: int, extensions: list<string>, mysqlnd: bool, database: string, constants: array<string, string|null>}
 */
class Preflight {

    /**
     * @param  array<string, mixed> $req release.json's requirements
     * @param  Environment          $env
     * @return list<string>
     */
    public static function requirements( array $req, array $env ) {

        $php      = (array) ( $req['php'] ?? [] );
        $db       = (array) ( $req['database'] ?? [] );
        $config   = (array) ( $req['config'] ?? [] );
        $problems = [];

        if ( ! isset( $php['min'], $db['mysql'], $db['mariadb'] ) ) {

            return [ \OWA\Core\CoreAPI::t( 'The release does not say what it requires.' ) ];
        }

        if ( version_compare( $env['php'], (string) $php['min'], '<' ) ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'It needs PHP %1$s or later; the CLI runs PHP %2$s.' ), $php['min'], $env['php'] );
        }

        if ( isset( $php['int_size'] ) && (int) $php['int_size'] !== $env['int_size'] ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'It needs %d-bit PHP.' ), (int) $php['int_size'] * 8 );
        }

        foreach ( (array) ( $php['extensions'] ?? [] ) as $ext ) {

            if ( ! in_array( strtolower( (string) $ext ), $env['extensions'], true ) ) {

                $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'It needs PHP\'s %s extension, which the CLI does not load.' ), $ext );
            }
        }

        if ( ! self::hasDriver( (array) ( $php['database_drivers'] ?? [] ), $env ) ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'It needs one of these PHP database drivers: %s.' ), implode( ', ', (array) ( $php['database_drivers'] ?? [] ) ) );
        }

        $db_problem = \OWA\Module\Base\Classes\DatabaseRequirement::problemFor( $env['database'], (string) $db['mysql'], (string) $db['mariadb'] );

        if ( $db_problem !== null ) {

            $problems[] = $db_problem;
        }

        foreach ( [ 'database_constants', 'url_constants', 'secret_constants' ] as $list ) {

            foreach ( (array) ( $config[ $list ] ?? [] ) as $name ) {

                $value = $env['constants'][ (string) $name ] ?? null;

                // As boot judges them (ServerRole): the database constants
                // defined, an empty password among them; the URL not empty.
                // An empty secret is too short, below.
                if ( $value === null || ( $value === '' && $list === 'url_constants' ) ) {

                    $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'It needs %s defined in owa-config.php.' ), $name );

                } elseif ( $list === 'secret_constants' && strlen( $value ) < (int) ( $config['secret_min_length'] ?? 0 ) ) {

                    $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'It needs %1$s to be at least %2$d characters long.' ), $name, (int) $config['secret_min_length'] );
                }
            }
        }

        return $problems;
    }

    /**
     * @param  list<mixed> $drivers as Core\Requirements::DATABASE_DRIVERS
     * @param  Environment $env
     * @return bool
     */
    private static function hasDriver( array $drivers, array $env ) {

        foreach ( $drivers as $driver ) {

            $parts = explode( '+', strtolower( (string) $driver ) );
            $ok    = true;

            foreach ( $parts as $part ) {

                $ok = $ok && ( $part === 'mysqlnd' ? $env['mysqlnd'] : in_array( $part, $env['extensions'], true ) );
            }

            if ( $ok ) {

                return true;
            }
        }

        return $drivers === [];
    }

    /**
     * This server as requirements() reads it.
     *
     * @param  array<string, mixed> $req the constants it names are the ones read
     * @return Environment
     */
    public static function environment( array $req ) {

        $constants = [];

        foreach ( [ 'database_constants', 'url_constants', 'secret_constants' ] as $list ) {

            foreach ( (array) ( $req['config'][ $list ] ?? [] ) as $name ) {

                $constants[ (string) $name ] = defined( (string) $name ) && is_scalar( constant( (string) $name ) )
                    ? (string) constant( (string) $name ) : null;
            }
        }

        return [
            'php'        => PHP_VERSION,
            'int_size'   => PHP_INT_SIZE,
            'extensions' => array_map( 'strtolower', get_loaded_extensions() ),
            'mysqlnd'    => extension_loaded( 'mysqlnd' ),
            'database'   => (string) \OWA\Core\CoreAPI::dbSingleton()->serverVersion(),
            'constants'  => $constants,
        ];
    }

    /**
     * What copying the release over $root needs: every directory it writes
     * in writable, and room on the disk for its files beside the old.
     *
     * @param  array<string, int> $files the release's files, relative to its root, with their sizes
     * @param  string             $root  the OWA directory
     * @return list<string>
     */
    public static function install( array $files, $root ) {

        $root     = rtrim( (string) $root, '/' );
        $problems = [];

        // Each file is written beside its target and renamed over it, and
        // what the release does not ship is unlinked: the directories must be
        // writable, whatever the files' own modes.
        $dirs = [];

        foreach ( array_keys( $files ) as $rel ) {

            $dir = dirname( $root . '/' . $rel );

            while ( ! is_dir( $dir ) && $dir !== $root && $dir !== dirname( $dir ) ) {

                $dir = dirname( $dir );
            }

            $dirs[ $dir ] = true;
        }

        $unwritable = array_values( array_filter( array_keys( $dirs ), static fn ( $dir ) => ! is_writable( $dir ) ) );

        if ( $unwritable ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( '%1$d of the directories the release writes in cannot be written by the user the scheduler runs as, such as %2$s.' ),
                count( $unwritable ), self::named( $unwritable[0], $root ) );
        }

        $bytes = array_sum( $files );
        $free  = @disk_free_space( $root );

        if ( $free !== false && $free < $bytes ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'Installing it needs about %1$d MB free on the disk holding %2$s; %3$d MB are.' ),
                (int) ceil( $bytes / 1048576 ), $root, (int) floor( $free / 1048576 ) );
        }

        return $problems;
    }

    /**
     * The directories of OWA's code this process cannot write, relative to
     * $root ('' for the OWA directory itself): what an automatic update
     * writes in, asked where the installer runs (cmd=install). The
     * installation's own directories are left out: owa-data/, the data
     * directory and the tracking bundles.
     *
     * @param  string       $root
     * @param  list<string> $own  more of the installation's own directories
     * @return list<string>
     */
    public static function unwritableCode( $root, array $own = [] ) {

        $root = rtrim( (string) $root, '/' );
        $own  = array_map( static fn ( $d ) => rtrim( (string) $d, '/' ), array_merge( [ $root . '/owa-data', $root . '/public/tracker' ], $own ) );
        $out  = is_writable( $root ) ? [] : [ '' ];

        $dirs = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
                static fn ( \SplFileInfo $f ) => $f->isDir() && ! $f->isLink() && ! in_array( (string) $f->getPathname(), $own, true ) ),
            \RecursiveIteratorIterator::SELF_FIRST );

        foreach ( $dirs as $path => $info ) {

            if ( ! is_writable( (string) $path ) ) {

                $out[] = substr( (string) $path, strlen( $root ) + 1 );
            }
        }

        return $out;
    }

    /**
     * @param  string $dir
     * @param  string $root
     * @return string
     */
    private static function named( $dir, $root ) {

        return $dir === $root ? \OWA\Core\CoreAPI::t( 'the OWA directory' ) : substr( $dir, strlen( $root ) + 1 ) . '/';
    }
}
