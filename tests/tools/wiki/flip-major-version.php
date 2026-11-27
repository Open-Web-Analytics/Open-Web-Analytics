<?php
/**
 * Flip the wiki to a new major version on release day: the staged pages become
 * the current ones and the current ones are archived.
 *
 *   php tests/tools/wiki/flip-major-version.php --wiki <dir> --from v2 --to v1 [--apply] [--fold Old=New ...]
 *
 * For --from v2 --to v1:
 *
 *   before                        after
 *   <Name>.md (1.x, Home too)     v1/v1-<Name>.md
 *   _Sidebar.md, _Footer.md       v1/_Sidebar.md, v1/_Footer.md
 *   v2/v2-<Name>.md               <Name>.md (v2-Home becomes Home)
 *   v2/<Name>.md (no prefix)      <Name>.md
 *   v2/_Sidebar.md, _Footer.md    _Sidebar.md, _Footer.md
 *
 * and v2/ is removed. --from v3 --to v2 flips 3.0 the same way. Other folders
 * (v1/ then) are not moved or rewritten; their links are checked.
 *
 * LINKS. Page names are flat across folders, so every page link in a moved
 * file ([[Page]], [[Text|Page]], [[Text\|Page]], [text](Page#anchor) and full
 * .../wiki/Page URLs) is rewritten to its page's new name: a 1.x link to
 * Configuration becomes v1-Configuration, a 2.0 link to v2-Configuration
 * becomes Configuration, the 2.0 sidebar's (Home) becomes (v1-Home). Anchors
 * are kept. Code spans and fenced code are not links. Generated sections are
 * rewritten like the rest of the page.
 *
 * BANNERS. The "not released yet" banner (the page's opening blockquote) is
 * removed from each staged page. Each archived page opens with a banner naming
 * its counterpart, chosen by, in order: a fold (--fold, plus OWA_WIKI_FLIP_FOLDS
 * for --from; a fold for a page the wiki does not have is ignored); the staged
 * page with the same name; the one staged page whose banner links to it; Home.
 * An archived page's existing banner is replaced.
 *
 * PROBLEMS, each refusing --apply and exiting 1: a name or path collision, a
 * link to a page that will not exist after the flip, a page named by two
 * staged banners with no same-name page or fold to settle it, a fold naming no
 * staged page, a root page already carrying a version prefix, and anything in
 * <from>/ that is not a page.
 *
 * Without --apply nothing is written. Files only: git is not called. --apply
 * writes every new file to a staging folder before removing any old one; a run
 * that fails after that leaves a checkout to restore with git.
 */

/** 1.x pages whose 2.0 counterpart is a page with another name. Values are post-flip names. */
const OWA_WIKI_FLIP_FOLDS = [
    'v2' => [
        'Creating-A-New-Module' => 'Writing-a-Module',
        'Deployment'            => 'Server-Roles',
        'Event-Queueing'        => 'Server-Roles',
        'Database-Schema'       => 'Data-Model',
        'Event-Types'           => 'Event-Properties',
        'Action-Tracking'       => 'Custom-Events',
        'Invocation'            => 'Sending-Events',
        'Data-Access-API'       => 'Reporting-API',
    ],
];

/** Files that belong to a folder rather than being pages. */
const OWA_WIKI_FLIP_SPECIAL = [ '_Sidebar.md', '_Footer.md' ];

const OWA_WIKI_FLIP_STAGED_BANNER  = '/^>\s*\*\*This is the documentation for OWA [0-9.x]+, which has not been released yet/';
const OWA_WIKI_FLIP_ARCHIVE_BANNER = '/^>\s*\*\*This page documents OWA [0-9]+\.x\./';

const OWA_WIKI_FLIP_URL = 'https?://(?:www\.)?github\.com/Open-Web-Analytics/Open-Web-Analytics/wiki/';

/**
 * '2.0' for the release being made current, '1.x' for the one archived.
 */
function owa_wiki_flip_version( string $prefix, bool $current ): string {

    return substr( $prefix, 1 ) . ( $current ? '.0' : '.x' );
}

/**
 * Every file under $wiki except .git, relative paths, sorted.
 *
 * @return string[]
 */
function owa_wiki_flip_files( string $wiki ): array {

    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator( $wiki, FilesystemIterator::SKIP_DOTS ),
            fn ( $f ) => $f->getFilename() !== '.git' ) );

    foreach ( $it as $file ) {

        $out[] = substr( (string) $file, strlen( $wiki ) + 1 );
    }

    sort( $out );

    return $out;
}

/**
 * A link target as a page: ['page', 'anchor', 'url' => prefix or null, 'encoded'],
 * or null when it is not a link to a wiki page.
 */
function owa_wiki_flip_parse_target( string $target, bool $wikiLink ): ?array {

    $target = trim( $target );
    $url    = null;

    if ( preg_match( '~^(' . OWA_WIKI_FLIP_URL . ')(.*)$~i', $target, $m ) ) {

        $url    = $m[1];
        $target = $m[2];

    } elseif ( preg_match( '~^[a-z][a-z0-9+.\-]*:~i', $target ) || preg_match( '~^[#/.]~', $target )
            || strpos( $target, '/' ) !== false ) {

        return null;
    }

    $anchor = '';

    if ( preg_match( '/^([^#?]*)([#?].*)$/', $target, $m ) ) {

        [ , $target, $anchor ] = $m;
    }

    if ( $target === '' || $target[0] === '_' || strpos( $target, '/' ) !== false
            || preg_match( '/\.(png|jpe?g|gif|svg|webp|pdf|zip|gz|txt|php|js|css|html?)$/i', $target ) ) {

        return null;
    }

    $encoded = strpos( $target, '%' ) !== false;
    $page    = rawurldecode( $target );

    if ( $wikiLink || $encoded ) {

        $page = str_replace( ' ', '-', $page );
    }

    return [ 'page' => $page, 'anchor' => $anchor, 'url' => $url, 'encoded' => $encoded ];
}

function owa_wiki_flip_build_target( array $t, string $page ): string {

    if ( $t['encoded'] ) {

        $page = rawurlencode( $page );
    }

    return ( $t['url'] ?? '' ) . $page . $t['anchor'];
}

/**
 * Rewrite (or, with $rewrite false, only resolve) every page link in $md.
 *
 * $resolve takes a page name and returns its post-flip name, or null when
 * there will be no such page.
 *
 * @return array [ string $md, array $changes [[old, new]], array $dangling [[page, link]] ]
 */
function owa_wiki_flip_links( string $md, callable $resolve, bool $rewrite = true ): array {

    $changes  = [];
    $dangling = [];
    $lines    = explode( "\n", $md );
    $fence    = null;

    $pattern = '~\[\[(?<wiki>[^\[\]\n]+?)\]\]'
             . '|(?<bang>!?)\[(?<text>(?:[^\[\]\n]|\[[^\[\]\n]*\])*)\]\((?<target>(?:[^()\s]|\([^()\s]*\))+)(?<title>\s+"[^"\n]*")?\)'
             . '|(?<url>' . OWA_WIKI_FLIP_URL . '(?:[^\s()\[\]<>"\'`]|\([^\s()]*\))+)~i';

    foreach ( $lines as $n => $line ) {

        if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/', $line, $m ) ) {

            if ( $fence === null ) {

                $fence = $m[1];

            } elseif ( $m[1][0] === $fence[0] && strlen( $m[1] ) >= strlen( $fence ) ) {

                $fence = null;
            }

            continue;
        }

        if ( $fence !== null ) {

            continue;
        }

        // Code spans are not links: hidden while matching, put back after.
        $spans  = [];
        $masked = preg_replace_callback( '/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/', function ( $m ) use ( &$spans ) {

            $spans[] = $m[0];

            return "\x01" . ( count( $spans ) - 1 ) . "\x02";
        }, $line );

        $table = (bool) preg_match( '/^\s*\|/', $line );

        $masked = preg_replace_callback( $pattern, function ( $m ) use ( $resolve, $rewrite, $table, &$changes, &$dangling ) {

            $old = $m[0];

            if ( ( $m['wiki'] ?? null ) !== null ) {

                $inner = $m['wiki'];
                $sep   = null;
                $text  = null;
                $tgt   = $inner;

                foreach ( [ '\\|', '|' ] as $s ) {

                    $at = strrpos( $inner, $s );

                    if ( $at !== false ) {

                        $sep  = $s;
                        $text = substr( $inner, 0, $at );
                        $tgt  = substr( $inner, $at + strlen( $s ) );
                        break;
                    }
                }

                $t = owa_wiki_flip_parse_target( $tgt, true );

            } elseif ( ( $m['target'] ?? null ) !== null ) {

                if ( $m['bang'] === '!' ) {

                    return $old;
                }

                $t = owa_wiki_flip_parse_target( $m['target'], false );

            } else {

                $t = owa_wiki_flip_parse_target( $m['url'], false );
            }

            if ( $t === null ) {

                return $old;
            }

            $new = $resolve( $t['page'] );

            if ( $new === null ) {

                $dangling[] = [ $t['page'], $old ];

                return $old;
            }

            if ( ! $rewrite || $new === $t['page'] ) {

                return $old;
            }

            $target = owa_wiki_flip_build_target( $t, $new );

            if ( ( $m['wiki'] ?? null ) !== null ) {

                // [[v2-Settings]] shows its prefix, so becomes [[Settings]];
                // [[Javascript Tracker]] keeps its text: [[Javascript Tracker|v1-Javascript-Tracker]].
                if ( $text !== null ) {

                    $link = '[[' . $text . $sep . $target . ']]';

                } elseif ( preg_match( '/^v[0-9]+[- ]/', $inner ) ) {

                    $link = '[[' . $target . ']]';

                } else {

                    $link = '[[' . $inner . ( $table ? '\\|' : '|' ) . $target . ']]';
                }

            } elseif ( ( $m['target'] ?? null ) !== null ) {

                $link = '[' . $m['text'] . '](' . $target . ( $m['title'] ?? '' ) . ')';

            } else {

                $link = $target;
            }

            $changes[] = [ $old, $link ];

            return $link;
        }, $masked, -1, $count, PREG_UNMATCHED_AS_NULL );

        $lines[ $n ] = preg_replace_callback( "/\x01(\d+)\x02/", fn ( $m ) => $spans[ (int) $m[1] ], $masked );
    }

    return [ implode( "\n", $lines ), $changes, $dangling ];
}

/**
 * Split off a page's opening blockquote when its first line matches $pattern.
 *
 * @return array [ string $rest, ?string $banner ]
 */
function owa_wiki_flip_strip_banner( string $md, string $pattern ): array {

    $lines = explode( "\n", $md );

    if ( ! preg_match( $pattern, $lines[0] ) ) {

        return [ $md, null ];
    }

    $i = 0;

    while ( $i < count( $lines ) && preg_match( '/^>/', $lines[ $i ] ) ) {

        $i++;
    }

    $banner = implode( "\n", array_slice( $lines, 0, $i ) );

    while ( $i < count( $lines ) && trim( $lines[ $i ] ) === '' ) {

        $i++;
    }

    return [ implode( "\n", array_slice( $lines, $i ) ), $banner ];
}

/**
 * Everything the flip would do, without doing it.
 *
 * @param array $folds archived page name => post-flip name of its counterpart
 * @return array moves [old path => new path], contents [new path => text],
 *               renames, banners_removed, banners_added, links [[path, old, new]],
 *               problems, rmdir
 */
function owa_wiki_flip_plan( string $wiki, string $from, string $to, array $folds = [] ): array {

    $wiki = rtrim( $wiki, '/' );
    $plan = [ 'moves' => [], 'contents' => [], 'banners_removed' => [], 'banners_added' => [],
              'links' => [], 'problems' => [], 'rmdir' => $from ];

    if ( ! preg_match( '/^v[0-9]+$/', $from ) || ! preg_match( '/^v[0-9]+$/', $to ) || $from === $to ) {

        $plan['problems'][] = '--from and --to must be two different version prefixes, such as v2 and v1';

        return $plan;
    }

    if ( ! is_dir( "$wiki/$from" ) ) {

        $plan['problems'][] = "$from/ does not exist: nothing to flip";

        return $plan;
    }

    $current = [];   // old path => page name, or null for a special file
    $staged  = [];
    $others  = [];

    foreach ( owa_wiki_flip_files( $wiki ) as $rel ) {

        $base  = basename( $rel );
        $isMd  = substr( $rel, -3 ) === '.md';
        $depth = substr_count( $rel, '/' );
        $name  = in_array( $base, OWA_WIKI_FLIP_SPECIAL, true ) ? null : substr( $base, 0, -3 );

        if ( strpos( $rel, "$from/" ) === 0 ) {

            if ( ! $isMd || $depth > 1 ) {

                $plan['problems'][] = "$rel is in $from/ but is not a page: move or remove it first";
                continue;
            }

            $staged[ $rel ] = $name;

        } elseif ( $isMd && $depth === 0 ) {

            $current[ $rel ] = $name;

        } elseif ( $isMd ) {

            $others[ $rel ] = $name;
        }
    }

    if ( ! $staged ) {

        $plan['problems'][] = "$from/ has no pages: nothing to flip";

        return $plan;
    }

    // New paths and names.
    $rename = [];    // old page name => new page name

    foreach ( $current as $rel => $name ) {

        if ( $name === null ) {

            $plan['moves'][ $rel ] = "$to/$rel";
            continue;
        }

        if ( preg_match( '/^v[0-9]+-/', $name ) ) {

            $plan['problems'][] = "$rel already carries a version prefix: archiving it would make $to-$name";
        }

        $plan['moves'][ $rel ] = "$to/$to-$name.md";
        $rename[ $name ]       = "$to-$name";
    }

    foreach ( $staged as $rel => $name ) {

        if ( $name === null ) {

            $plan['moves'][ $rel ] = basename( $rel );
            continue;
        }

        $new = strpos( $name, "$from-" ) === 0 ? substr( $name, strlen( "$from-" ) ) : $name;

        if ( $new === '' ) {

            $plan['problems'][] = "$rel has no name after the $from- prefix";
            continue;
        }

        $plan['moves'][ $rel ] = "$new.md";
        $rename[ $name ]       = $new;
    }

    // Collisions: two files to one path, a path already taken, two pages with one name.
    $vacated = array_change_key_case( array_flip( array_keys( $plan['moves'] ) ) );
    $taken   = [];

    foreach ( $plan['moves'] as $old => $new ) {

        $k = strtolower( $new );

        if ( isset( $taken[ $k ] ) ) {

            $plan['problems'][] = "name collision: $taken[$k] and $old would both become $new";
        }

        $taken[ $k ] = $old;

        if ( file_exists( "$wiki/$new" ) && ! isset( $vacated[ $k ] ) ) {

            $plan['problems'][] = "name collision: $old would become $new, which already exists";
        }
    }

    $after = [];     // lower-case post-flip page name => [name, path]

    foreach ( $plan['moves'] + array_combine( array_keys( $others ), array_keys( $others ) ) as $old => $new ) {

        $name = $current[ $old ] ?? $staged[ $old ] ?? $others[ $old ] ?? null;

        if ( $name === null ) {

            continue;
        }

        $newName = substr( basename( $new ), 0, -3 );
        $k       = strtolower( $newName );

        if ( isset( $after[ $k ] ) && $after[ $k ][1] !== $new ) {

            $plan['problems'][] = "name collision: page $newName would be both {$after[$k][1]} and $new";
        }

        $after[ $k ] = [ $newName, $new ];
    }

    $before = [];    // lower-case pre-flip page name => post-flip name

    foreach ( $others as $name ) {

        if ( $name !== null ) {

            $before[ strtolower( $name ) ] = $name;
        }
    }

    foreach ( $rename as $old => $new ) {

        $before[ strtolower( $old ) ] = $new;
    }

    // A moved page's link means what it meant before the flip; a link already
    // in post-flip form (v1-Home in a 2.0 sidebar) is left as it is.
    $resolveMoved = function ( string $page ) use ( $before, $after ) {

        $k = strtolower( $page );

        if ( isset( $before[ $k ] ) ) {

            return strcasecmp( $before[ $k ], $page ) === 0 ? $page : $before[ $k ];
        }

        return isset( $after[ $k ] ) ? $page : null;
    };

    // A page that does not move keeps its text, so its links are read after the flip.
    $resolveKept = fn ( string $page ) => isset( $after[ strtolower( $page ) ] ) ? $page : null;

    $promoted = [];  // lower-case post-flip name of a staged page => name

    foreach ( $staged as $name ) {

        if ( $name !== null && isset( $rename[ $name ] ) ) {

            $promoted[ strtolower( $rename[ $name ] ) ] = $rename[ $name ];
        }
    }

    $read         = fn ( string $rel ) => (string) file_get_contents( "$wiki/$rel" );
    $currentLower = array_flip( array_map( 'strtolower', array_filter( $current ) ) );

    // Staged pages: drop the banner, and note which current page it names.
    $claims = [];    // lower-case current page name => [staged path, ...]
    $text   = [];    // old path => text with links not yet rewritten

    foreach ( $staged as $rel => $name ) {

        if ( ! isset( $plan['moves'][ $rel ] ) ) {

            continue;
        }

        $md = $read( $rel );

        if ( $name !== null ) {

            [ $md, $banner ] = owa_wiki_flip_strip_banner( $md, OWA_WIKI_FLIP_STAGED_BANNER );

            if ( $banner !== null ) {

                $plan['banners_removed'][] = [ $plan['moves'][ $rel ], $rel ];

                owa_wiki_flip_links( $banner, function ( $page ) use ( $currentLower, $rel, &$claims ) {

                    if ( isset( $currentLower[ strtolower( $page ) ] ) ) {

                        $claims[ strtolower( $page ) ][ $rel ] = $rel;
                    }

                    return $page;
                }, false );
            }
        }

        $text[ $rel ] = $md;
    }

    // Titles for the archived banners come from the new sidebar's link text.
    $titles = [];

    foreach ( $staged as $rel => $name ) {

        if ( basename( $rel ) !== '_Sidebar.md' ) {

            continue;
        }

        preg_match_all( '/\[\[([^\]|]+?)(?:\\\\)?\|([^\]]+)\]\]|\[([^\[\]]+)\]\(([^()\s]+)\)/', $read( $rel ), $mm, PREG_SET_ORDER );

        foreach ( $mm as $m ) {

            $label = $m[1] !== '' ? $m[1] : $m[3];
            $t     = owa_wiki_flip_parse_target( $m[1] !== '' ? $m[2] : $m[4], $m[1] !== '' );
            $page  = $t === null ? null : $resolveMoved( $t['page'] );

            if ( $page !== null && ! isset( $titles[ $page ] ) ) {

                $titles[ $page ] = trim( $label );
            }
        }
    }

    // Current pages: the counterpart each archived page's banner names.
    $twin = [];      // old path => [post-flip name, why]
    $foldsLower = array_change_key_case( $folds );

    foreach ( $current as $rel => $name ) {

        if ( $name === null ) {

            continue;
        }

        $k = strtolower( $name );

        if ( isset( $foldsLower[ $k ] ) ) {

            $target = $foldsLower[ $k ];

            if ( isset( $promoted[ strtolower( $target ) ] ) ) {

                $twin[ $rel ] = [ $promoted[ strtolower( $target ) ], 'fold' ];

            } else {

                $plan['problems'][] = "fold $name -> $target: no $from page becomes $target";
            }

        } elseif ( isset( $promoted[ $k ] ) ) {

            $alsoBy = array_diff( $claims[ $k ] ?? [], [ array_search( "{$promoted[$k]}.md", $plan['moves'], true ) ] );
            $why    = 'same name';

            if ( $alsoBy ) {

                $why .= '; also named by ' . implode( ', ', $alsoBy );
            }

            $twin[ $rel ] = [ $promoted[ $k ], $why ];

        } elseif ( count( $claims[ $k ] ?? [] ) === 1 ) {

            $by           = reset( $claims[ $k ] );
            $twin[ $rel ] = [ substr( basename( $plan['moves'][ $by ] ), 0, -3 ), 'banner of ' . $by ];

        } elseif ( count( $claims[ $k ] ?? [] ) > 1 ) {

            $plan['problems'][] = "$rel is the $to page named by " . implode( ', ', $claims[ $k ] )
                                . ": settle it with --fold $name=<page>";

        } elseif ( isset( $promoted['home'] ) ) {

            $twin[ $rel ] = [ $promoted['home'], 'no counterpart' ];

        } else {

            $plan['problems'][] = "$rel has no counterpart and there is no $from Home to link instead";
        }
    }

    foreach ( $current as $rel => $name ) {

        $text[ $rel ] = $read( $rel );
    }

    // Rewrite links in every moved file, then add the archived pages' banners.
    foreach ( $plan['moves'] as $old => $new ) {

        if ( ! isset( $text[ $old ] ) ) {

            continue;
        }

        $md = $text[ $old ];

        if ( isset( $current[ $old ] ) ) {

            [ $md ] = owa_wiki_flip_strip_banner( $md, OWA_WIKI_FLIP_ARCHIVE_BANNER );
        }

        [ $md, $changes, $dangling ] = owa_wiki_flip_links( $md, $resolveMoved );

        foreach ( $changes as [ $a, $b ] ) {

            $plan['links'][] = [ $new, $a, $b ];
        }

        foreach ( $dangling as [ $page, $link ] ) {

            $plan['problems'][] = "$old links to $page, which will not exist after the flip: $link";
        }

        if ( isset( $twin[ $old ] ) ) {

            [ $page, $why ] = $twin[ $old ];
            $title  = $titles[ $page ] ?? str_replace( '-', ' ', $page );
            $banner = sprintf( '> **This page documents OWA %s. It has been superseded by the %s documentation: see [[%s|%s]].**',
                owa_wiki_flip_version( $to, false ), owa_wiki_flip_version( $from, true ), $title, $page );
            $md     = $banner . "\n\n" . $md;

            $plan['banners_added'][] = [ $new, "[[$title|$page]]", $why ];
        }

        $plan['contents'][ $new ] = $md;
    }

    foreach ( $others as $rel => $name ) {

        [ , , $dangling ] = owa_wiki_flip_links( $read( $rel ), $resolveKept, false );

        foreach ( $dangling as [ $page, $link ] ) {

            $plan['problems'][] = "$rel links to $page, which will not exist after the flip: $link";
        }
    }

    return $plan;
}

/**
 * Carry out a plan with no problems: write every new file to a staging folder,
 * remove the old files, move the new ones into place, remove <from>/.
 */
function owa_wiki_flip_apply( string $wiki, array $plan ): void {

    if ( $plan['problems'] ) {

        throw new RuntimeException( 'the plan has problems' );
    }

    $wiki  = rtrim( $wiki, '/' );
    $stage = "$wiki/.flip-staging-" . getmypid();

    foreach ( $plan['moves'] as $new ) {

        $path = "$stage/$new";

        if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0755, true ) ) {

            throw new RuntimeException( 'cannot create ' . dirname( $path ) );
        }

        if ( file_put_contents( $path, $plan['contents'][ $new ] ) === false ) {

            throw new RuntimeException( "cannot write $path" );
        }
    }

    foreach ( array_keys( $plan['moves'] ) as $old ) {

        if ( ! unlink( "$wiki/$old" ) ) {

            throw new RuntimeException( "cannot remove $wiki/$old" );
        }
    }

    foreach ( $plan['moves'] as $new ) {

        $path = "$wiki/$new";

        if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0755, true ) ) {

            throw new RuntimeException( 'cannot create ' . dirname( $path ) );
        }

        if ( ! rename( "$stage/$new", $path ) ) {

            throw new RuntimeException( "cannot move $stage/$new to $path" );
        }
    }

    foreach ( array_reverse( owa_wiki_flip_dirs( $stage ) ) as $dir ) {

        rmdir( $dir );
    }

    if ( is_dir( "$wiki/{$plan['rmdir']}" ) && ! rmdir( "$wiki/{$plan['rmdir']}" ) ) {

        throw new RuntimeException( "cannot remove $wiki/{$plan['rmdir']}/" );
    }
}

/** $dir and every folder under it, parents first. */
function owa_wiki_flip_dirs( string $dir ): array {

    $out = [ $dir ];

    foreach ( glob( "$dir/*", GLOB_ONLYDIR ) ?: [] as $sub ) {

        $out = array_merge( $out, owa_wiki_flip_dirs( $sub ) );
    }

    return $out;
}

function owa_wiki_flip_report( array $plan ): string {

    $out   = [];
    $group = function ( string $title, array $lines ) use ( &$out ) {

        $out[] = sprintf( '%s (%d)', $title, count( $lines ) );

        foreach ( $lines as $line ) {

            $out[] = '  ' . $line;
        }

        $out[] = '';
    };

    $moves = $plan['moves'];
    ksort( $moves );

    $group( 'Renames', array_map( fn ( $old, $new ) => "$old -> $new", array_keys( $moves ), $moves ) );
    $group( 'Banners removed', array_map( fn ( $b ) => "$b[0] (was $b[1])", $plan['banners_removed'] ) );
    $group( 'Banners added', array_map( fn ( $b ) => "$b[0]: $b[1] ($b[2])", $plan['banners_added'] ) );
    $group( 'Links changed', array_map( fn ( $l ) => "$l[0]: $l[1] -> $l[2]", $plan['links'] ) );
    $group( 'Problems', $plan['problems'] );

    if ( $moves ) {

        $out[] = "{$plan['rmdir']}/ is removed once empty.";
    }

    return implode( "\n", $out ) . "\n";
}

/**
 * @param string[] $argv
 * @return int 0 done or nothing wrong, 1 problems, 2 usage
 */
function owa_wiki_flip_main( array $argv ): int {

    $opts  = [ 'wiki' => null, 'from' => null, 'to' => null ];
    $apply = false;
    $folds = [];
    $args  = array_slice( $argv, 1 );

    while ( $args ) {

        $arg = array_shift( $args );

        if ( $arg === '--apply' ) {

            $apply = true;

        } elseif ( $arg === '--fold' && $args && preg_match( '/^([^=]+)=(.+)$/', $args[0], $m ) ) {

            array_shift( $args );
            $folds[ $m[1] ] = $m[2];

        } elseif ( in_array( $arg, [ '--wiki', '--from', '--to' ], true ) && $args ) {

            $opts[ substr( $arg, 2 ) ] = array_shift( $args );

        } else {

            $opts['wiki'] = null;
            break;
        }
    }

    if ( in_array( null, $opts, true ) ) {

        fwrite( STDERR, "usage: php flip-major-version.php --wiki <dir> --from v2 --to v1 [--apply] [--fold Old=New ...]\n" );

        return 2;
    }

    $plan = owa_wiki_flip_plan( $opts['wiki'], $opts['from'], $opts['to'],
        array_merge( OWA_WIKI_FLIP_FOLDS[ $opts['from'] ] ?? [], $folds ) );

    echo owa_wiki_flip_report( $plan );

    if ( $plan['problems'] ) {

        echo $apply ? "Refused: nothing was written.\n" : '';

        return 1;
    }

    if ( $apply ) {

        owa_wiki_flip_apply( $opts['wiki'], $plan );
        echo "Applied.\n";

    } else {

        echo "Dry run: nothing was written. Add --apply to write it.\n";
    }

    return 0;
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === realpath( __FILE__ ) ) {

    exit( owa_wiki_flip_main( $argv ) );
}
