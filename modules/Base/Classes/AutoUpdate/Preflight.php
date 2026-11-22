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
 * automatic. install() judges what copying the release needs: the staging
 * owned by this user and writable by no one else, every file the release
 * ships writable where it lands, and room for it.
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

                if ( $value === null || $value === '' ) {

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
     * What copying the release over $root needs.
     *
     * @param  string               $staging  the release's <version>/
     * @param  array<string, mixed> $manifest the release's manifest.json
     * @param  string               $root     the OWA directory
     * @return list<string>
     */
    public static function install( $staging, array $manifest, $root ) {

        $root     = rtrim( (string) $root, '/' );
        $problems = [];

        if ( ! function_exists( 'posix_geteuid' ) ) {

            return [ \OWA\Core\CoreAPI::t( 'Automatic updates need PHP\'s posix extension in the CLI, to check who owns OWA\'s files.' ) ];
        }

        $uid = posix_geteuid();

        // The staging is what the installer runs: no one but the scheduler's
        // user may be able to change it. The OWA directory itself is often
        // group-writable on purpose (a setgid web group), and only has to let
        // that user write the release's files, checked below.
        foreach ( [ dirname( (string) $staging ), (string) $staging ] as $dir ) {

            $owner = @fileowner( $dir );
            $perms = @fileperms( $dir );

            if ( $owner !== $uid ) {

                $problems[] = sprintf( \OWA\Core\CoreAPI::t( '%s is not owned by the user the scheduler runs as.' ), $dir );

            } elseif ( $perms !== false && ( $perms & 0022 ) ) {

                $problems[] = sprintf( \OWA\Core\CoreAPI::t( '%s can be written by users other than its owner.' ), $dir );
            }
        }

        $bytes      = 0;
        $unwritable = [];

        foreach ( (array) ( $manifest['files'] ?? [] ) as $path => $entry ) {

            $bytes += (int) ( $entry['size'] ?? 0 );
            $target = $root . '/' . $path;

            while ( ! file_exists( $target ) && $target !== $root ) {

                $target = dirname( $target );
            }

            if ( ! is_writable( $target ) ) {

                $unwritable[] = (string) $path;
            }
        }

        if ( $unwritable ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( '%1$d of the files the release replaces or adds cannot be written by the user the scheduler runs as, such as %2$s.' ),
                count( $unwritable ), $unwritable[0] );
        }

        // Room for the backup of every file it replaces, at most the release's size, and the new files beside them.
        $free = @disk_free_space( $root );
        $need = (int) ( $bytes * 2 );

        if ( $free !== false && $free < $need ) {

            $problems[] = sprintf( \OWA\Core\CoreAPI::t( 'Installing it needs about %1$d MB free on the disk holding OWA; %2$d MB are.' ),
                (int) ceil( $need / 1048576 ), (int) floor( $free / 1048576 ) );
        }

        return $problems;
    }
}
