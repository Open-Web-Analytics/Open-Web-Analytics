<?php
/**
 * Generate the reference sections of the wiki from the code.
 *
 *   php tests/tools/wiki/generate.php <section>      print one section
 *   php tests/tools/wiki/generate.php --list         list the sections
 *   php tests/tools/wiki/generate.php --wiki <dir>   rewrite every marked section in <dir>/*.md
 *   php tests/tools/wiki/generate.php --check <dir>  exit 1 if --wiki would change anything
 *
 * A page marks what is generated with a named pair:
 *
 *   <!-- BEGIN GENERATED: jobs -->
 *   <!-- END GENERATED: jobs -->
 *
 * and only the text between them is replaced. Everything else on the page is
 * written by hand. A marker naming a section that does not exist is an error,
 * not a skip, so a renamed section cannot leave a page silently stale.
 *
 * SECTIONS. A section is a file in sections/ that returns a callable taking an
 * optional argument and returning markdown. `settings-tracking_tag` resolves to
 * sections/settings-tracking_tag.php if it exists, otherwise to
 * sections/settings.php called with 'tracking_tag' -- the longest
 * matching file wins. A `.js` section is run with
 * node instead, with the argument as argv[2], and its stdout is the section.
 *
 * The unnamed `<!-- BEGIN GENERATED -->` pair on the 1.x Metrics & Dimensions
 * page is not touched: that page documents 1.x.
 */

const SECTIONS_DIR = __DIR__ . '/sections';

/*
 * Modules the wiki does not document, whatever is active. Hello is the sample
 * module a developer copies, not a feature: its example report, settings and
 * command would read as part of OWA. Every section that walks modules or what
 * they register filters on this, because an install's own config or stored
 * is_active rows can activate it.
 */
const OWA_WIKI_EXCLUDED_MODULES = array( 'hello' );

require_once __DIR__ . '/../../../owa_env.php';

// Boots without owa-config.php: the registry needs the DB driver's OWA_DTD_*
// constants but no connection. Same guard install.php and tests/bootstrap.php use.
if ( ! defined( 'OWA_DB_TYPE' ) && ! file_exists( OWA_DIR . 'owa-config.php' ) ) {
    define( 'OWA_DB_TYPE', 'mysql' );
}

/*
 * Every shipped module, active or not: the wiki documents what ships, and a
 * configless boot activates only base. OWA_ACTIVE_MODULES is the config
 * file's own switch, so each module registers through the normal boot
 * rather than being added to the service afterwards, when its metrics,
 * dimensions and entities would already have been collected without it.
 *
 * OWA_WIKI_EXCLUDED_MODULES are left out.
 *
 * Outside the configless guard: with an owa-config.php present the boot would
 * otherwise document that install's active modules. The constant only adds, so
 * stored is_active rows cannot remove one. Only when run as a script: a test
 * that includes this file shares its process with the rest of the suite, which
 * must keep the modules its own config activates.
 *
 * The constant takes runtime names, which do not follow from the directory
 * (FileCache is fileCache, MaxmindGeoip is maxmind_geoip), so each is read
 * from its Module.php. Nothing can be instantiated to ask before boot.
 */
if ( ! defined( 'OWA_ACTIVE_MODULES' )
    && realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {

    define( 'OWA_ACTIVE_MODULES', ( static function () {

        $names = array();

        foreach ( glob( OWA_DIR . 'modules/*/Module.php' ) as $path ) {

            if ( preg_match( "/\\\$this->name\s*=\s*'([^']+)'/", (string) file_get_contents( $path ), $m )
                && ! in_array( $m[1], OWA_WIKI_EXCLUDED_MODULES, true ) ) {
                $names[] = $m[1];
            }
        }

        return $names;
    } )() );
}

require_once __DIR__ . '/../../../owa.php';

function owa_wiki_boot() {

    static $owa;

    if ( ! $owa ) {
        $owa = new owa( array( 'instance_role' => 'cli' ) );
    }

    return $owa;
}

/**
 * @return array<string,string> section file base name => path
 */
function owa_wiki_section_files() {

    $files = array();

    foreach ( glob( SECTIONS_DIR . '/*.{php,js}', GLOB_BRACE ) as $path ) {
        $files[ pathinfo( $path, PATHINFO_FILENAME ) ] = $path;
    }

    ksort( $files );

    return $files;
}

/**
 * @return array{0:string,1:?string}|null [path, argument]
 */
function owa_wiki_resolve( $name ) {

    $files = owa_wiki_section_files();

    if ( isset( $files[ $name ] ) ) {
        return array( $files[ $name ], null );
    }

    // Longest prefix first, so event-properties-click finds event-properties.php
    // before a shorter event.php would.
    $dash = strlen( $name );

    while ( ( $dash = strrpos( substr( $name, 0, $dash ), '-' ) ) !== false ) {

        if ( isset( $files[ substr( $name, 0, $dash ) ] ) ) {
            return array( $files[ substr( $name, 0, $dash ) ], substr( $name, $dash + 1 ) );
        }
    }

    return null;
}

function owa_wiki_generate( $name ) {

    $resolved = owa_wiki_resolve( $name );

    if ( ! $resolved ) {
        throw new RuntimeException( "No section named '$name'. Run --list for the sections." );
    }

    list( $path, $arg ) = $resolved;

    if ( substr( $path, -3 ) === '.js' ) {

        $cmd = 'node ' . escapeshellarg( $path ) . ( $arg !== null ? ' ' . escapeshellarg( $arg ) : '' );
        exec( $cmd . ' 2>&1', $lines, $status );

        if ( $status !== 0 ) {
            throw new RuntimeException( "Section '$name' failed:\n" . implode( "\n", $lines ) );
        }

        $md = implode( "\n", $lines );

    } else {

        owa_wiki_boot();
        $fn = require $path;
        $md = $fn( $arg );
    }

    $md = rtrim( (string) $md );

    if ( $md === '' ) {
        throw new RuntimeException( "Section '$name' produced nothing." );
    }

    return $md;
}

/**
 * Replace every named generated section in $page.
 *
 * @return string the new page
 */
function owa_wiki_splice( $page, callable $generate ) {

    $re = '/(<!-- BEGIN GENERATED: ([a-z0-9_-]+) -->)(.*?)(<!-- END GENERATED: \2 -->)/s';

    // An unmatched BEGIN would otherwise be left as it is, unnoticed.
    preg_match_all( '/<!-- BEGIN GENERATED: ([a-z0-9_-]+) -->/', $page, $begins );
    preg_match_all( $re, $page, $pairs );

    if ( count( $begins[1] ) !== count( $pairs[2] ) ) {

        $missing = array_diff( $begins[1], $pairs[2] );
        throw new RuntimeException( 'No matching END marker for: ' . implode( ', ', $missing ) );
    }

    return preg_replace_callback( $re, function ( $m ) use ( $generate ) {
        return $m[1] . "\n\n" . $generate( $m[2] ) . "\n\n" . $m[4];
    }, $page );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) !== realpath( __FILE__ ) ) {
    return; // included by a test
}

$arg = $argv[1] ?? '';

try {

    if ( $arg === '--list' ) {

        foreach ( array_keys( owa_wiki_section_files() ) as $name ) {
            echo $name, "\n";
        }

        exit( 0 );
    }

    if ( $arg === '--wiki' || $arg === '--check' ) {

        $dir = rtrim( $argv[2] ?? '', '/' );

        if ( ! is_dir( $dir ) ) {
            fwrite( STDERR, "usage: generate.php $arg <wiki checkout>\n" );
            exit( 2 );
        }

        $cache   = array();
        $changed = array();

        foreach ( glob( $dir . '/*.md' ) as $path ) {

            $page = file_get_contents( $path );
            $new  = owa_wiki_splice( $page, function ( $name ) use ( &$cache ) {
                return $cache[ $name ] ??= owa_wiki_generate( $name );
            } );

            if ( $new !== $page ) {

                $changed[] = basename( $path );

                if ( $arg === '--wiki' ) {
                    file_put_contents( $path, $new );
                }
            }
        }

        foreach ( $changed as $page ) {
            echo ( $arg === '--wiki' ? 'updated ' : 'stale ' ), $page, "\n";
        }

        exit( $arg === '--check' && $changed ? 1 : 0 );
    }

    if ( $arg === '' ) {
        fwrite( STDERR, "usage: generate.php <section> | --list | --wiki <dir> | --check <dir>\n" );
        exit( 2 );
    }

    echo owa_wiki_generate( $arg ), "\n";

} catch ( RuntimeException $e ) {

    fwrite( STDERR, $e->getMessage() . "\n" );
    exit( 1 );
}
