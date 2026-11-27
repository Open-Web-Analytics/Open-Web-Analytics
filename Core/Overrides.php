<?php
namespace OWA\Core;

/**
 * Files an admin puts in the data directory's conf/ (owa-data/conf/) to
 * change what OWA ships in conf/: the channel rules, which an override
 * replaces, the site lists and the other lists CoreAPI::loadConf() reads,
 * which an override is merged into, and the user-agent patterns
 * (ua-parser/regexes.php), which an override replaces.
 *
 * They are PHP, and the cron user runs them, so one is run only when nobody
 * but the owner of OWA's files could have written it (SafeFile::
 * trustProblem(), up to the data directory). The web server writes only
 * owa-data/logs/; one it could write is skipped, and System Health says why.
 *
 * Before 2.0 they sat in the data directory itself, which the web server can
 * write. Those are no longer read (misplaced()).
 */
class Overrides {

    /** conf/ files an override can change. */
    const FILES = [
        'channels.php',
        'searchengines.php',
        'socialnetworks.php',
        'aiassistants.php',
        'videosites.php',
        'shoppingsites.php',
        'beacon_compat.php',
        'cube_columns.php',
    ];

    /** The refreshed user-agent patterns, under dir(). */
    const UA_PATTERNS = 'ua-parser/regexes.php';

    /**
     * @param  string|null $data_dir a test's; else the installation's
     * @return string owa-data/conf/, with its slash
     */
    public static function dir( $data_dir = null ) {

        return Env::withSlash( $data_dir ?? Env::dataDir() ) . 'conf/';
    }

    /**
     * The override of $name to run, or null: there is none, or it is one
     * OWA may not run (problem()).
     *
     * @param  string      $name     a FILES entry or UA_PATTERNS
     * @param  string|null $data_dir
     * @return string|null
     */
    public static function file( $name, $data_dir = null ) {

        $file = self::dir( $data_dir ) . $name;

        if ( ! file_exists( $file ) && ! is_link( $file ) ) {

            return null;
        }

        return self::problem( $file, $data_dir ) === null ? $file : null;
    }

    /**
     * @param  string      $file
     * @param  string|null $data_dir
     * @return string|null why OWA does not run it, or null
     */
    public static function problem( $file, $data_dir = null ) {

        return SafeFile::trustProblem( $file, Env::withSlash( $data_dir ?? Env::dataDir() ) );
    }

    /**
     * Overrides there that are not run, as file => why.
     *
     * @param  string|null $data_dir
     * @return array<string, string>
     */
    public static function skipped( $data_dir = null ) {

        $out = [];

        foreach ( array_merge( self::FILES, [ self::UA_PATTERNS ] ) as $name ) {

            $file = self::dir( $data_dir ) . $name;

            if ( file_exists( $file ) || is_link( $file ) ) {

                $problem = self::problem( $file, $data_dir );

                if ( $problem !== null ) {

                    $out[ $file ] = $problem;
                }
            }
        }

        return $out;
    }

    /**
     * Overrides where OWA read them before 2.0, the data directory itself,
     * which it no longer reads.
     *
     * @param  string|null $data_dir
     * @return list<string> their paths
     */
    public static function misplaced( $data_dir = null ) {

        $dir = Env::withSlash( $data_dir ?? Env::dataDir() );
        $out = [];

        foreach ( array_merge( self::FILES, [ self::UA_PATTERNS ] ) as $name ) {

            if ( file_exists( $dir . $name ) ) {

                $out[] = $dir . $name;
            }
        }

        return $out;
    }
}
