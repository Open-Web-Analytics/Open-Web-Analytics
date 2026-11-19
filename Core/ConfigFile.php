<?php

namespace OWA\Core;

/**
 * owa-config.php: read for `$this`, then included.
 *
 * The file is included from a static closure, outside any object, so `$this`
 * means nothing in it. Every setting it may set has a constant. A file that
 * still uses `$this` is not included at all, and every request is refused
 * until it is changed (ServerRole::bootProblem()): including it would stop
 * with "Using $this when not in object context" part way through, after some
 * of its constants were defined and before others.
 *
 * The check reads the file's tokens, before the file runs and before the
 * database or the settings registry is loaded. A `$this` in a comment is no
 * token of its own, so it is not refused.
 */
final class ConfigFile {

    /** A 1.x role grant: OWA_ROLE_CAPABILITIES replaces it. */
    const ROLE_CAPABILITIES_METHOD = 'addCapabilityToRole';

    /**
     * Each use of `$this` in the file's code.
     *
     * @param  string $file
     * @return list<array{line: int, call: string, replacement: string, screen?: bool, grants?: array<string, list<string>>}>
     *         call is the use without its values; replacement is what to
     *         write instead, '' when there is no constant for it; screen when
     *         a settings screen sets it instead
     */
    public static function problems( $file ) {

        $source = @file_get_contents( (string) $file );

        return is_string( $source ) ? self::problemsIn( $source ) : [];
    }

    /**
     * problems(), for the file's source.
     *
     * @param  string $source
     * @return list<array{line: int, call: string, replacement: string, screen?: bool, grants?: array<string, list<string>>}>
     */
    public static function problemsIn( $source ) {

        $tokens = array_values( array_filter( token_get_all( (string) $source ), static function ( $t ) {

            return ! is_array( $t ) || ! in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true );
        } ) );

        $problems = [];

        foreach ( $tokens as $i => $t ) {

            if ( ! is_array( $t ) || $t[0] !== T_VARIABLE || $t[1] !== '$this' ) {

                continue;
            }

            $problems[] = [ 'line' => (int) $t[2] ] + self::describe( $tokens, $i );
        }

        return $problems;
    }

    /**
     * The use of `$this` at $i, and what replaces it.
     *
     * @param  list<mixed> $tokens without whitespace or comments
     * @param  int         $i
     * @return array{call: string, replacement: string, screen?: bool, grants?: array<string, list<string>>}
     */
    private static function describe( array $tokens, $i ) {

        $method = null;
        $args   = [];

        if ( self::is( $tokens[ $i + 1 ] ?? null, T_OBJECT_OPERATOR )
             && self::is( $tokens[ $i + 2 ] ?? null, T_STRING )
             && ( $tokens[ $i + 3 ] ?? null ) === '(' ) {

            $method = $tokens[ $i + 2 ][1];
            $args   = self::arguments( $tokens, $i + 4 );
        }

        if ( $method === 'set' && count( $args ) >= 2
             && is_string( $args[0] ) && is_string( $args[1] ) ) {

            $call     = sprintf( "\$this->set( '%s', '%s', … )", $args[0], $args[1] );
            $constant = \OWA\Module\Base\Classes\Settings::constantForSetting( $args[0], $args[1] );

            if ( $constant !== '' ) {

                return [ 'call' => $call, 'replacement' => sprintf( "define( '%s', … );", $constant ) ];
            }

            return [ 'call' => $call, 'replacement' => '',
                'screen' => \OWA\Module\Base\Classes\Settings::isScreenSetting( $args[0], $args[1] ) ];
        }

        if ( $method === self::ROLE_CAPABILITIES_METHOD ) {

            $role = is_string( $args[0] ?? null ) ? $args[0] : null;
            $caps = $args[1] ?? null;
            $caps = is_string( $caps ) ? [ $caps ] : $caps;

            $call = sprintf( '$this->%s( … )', $method );

            if ( $role === null || ! is_array( $caps ) ) {

                return [ 'call' => $call, 'replacement' => "define( 'OWA_ROLE_CAPABILITIES', [ '<role>' => [ '<capability>', … ] ] );" ];
            }

            $caps = array_values( array_diff( $caps, \OWA\Module\Base\Classes\Settings::RETIRED_CAPABILITIES ) );

            return [
                'call'        => sprintf( "\$this->%s( '%s', … )", $method, $role ),
                'replacement' => self::roleCapabilitiesDefine( [ $role => $caps ] ),
                'grants'      => [ $role => $caps ],
            ];
        }

        return [ 'call' => $method !== null ? sprintf( '$this->%s( … )', $method ) : '$this', 'replacement' => '' ];
    }

    /**
     * define( 'OWA_ROLE_CAPABILITIES', ... ) for these grants, as PHP.
     *
     * @param  array<string, list<string>> $grants role => capabilities
     * @return string
     */
    public static function roleCapabilitiesDefine( array $grants ) {

        $roles = [];

        foreach ( $grants as $role => $caps ) {

            $roles[] = var_export( (string) $role, true ) . ' => [ '
                . implode( ', ', array_map( static function ( $cap ) {

                    return var_export( (string) $cap, true );
                }, $caps ) ) . ' ]';
        }

        return sprintf( "define( 'OWA_ROLE_CAPABILITIES', [ %s ] );", implode( ', ', $roles ) );
    }

    /**
     * The literal arguments of a call whose `(` is just before $start: a
     * string, a list of strings, or null for anything else.
     *
     * @param  list<mixed> $tokens
     * @param  int         $start
     * @return list<string|list<string>|null>
     */
    private static function arguments( array $tokens, $start ) {

        $args  = [];
        $arg   = [];
        $depth = 0;

        for ( $j = $start, $n = count( $tokens ); $j < $n; $j++ ) {

            $t = $tokens[ $j ];

            if ( in_array( $t, [ '(', '[' ], true ) ) {

                $depth++;

            } elseif ( in_array( $t, [ ')', ']' ], true ) ) {

                if ( $depth === 0 ) {

                    break;
                }

                $depth--;

            } elseif ( $t === ',' && $depth === 0 ) {

                $args[] = self::literal( $arg );
                $arg    = [];

                continue;
            }

            $arg[] = $t;
        }

        if ( $arg ) {

            $args[] = self::literal( $arg );
        }

        return $args;
    }

    /**
     * A string literal's value, a list of them in array() or [], or null.
     *
     * @param  list<mixed> $tokens one argument's
     * @return string|list<string>|null
     */
    private static function literal( array $tokens ) {

        if ( count( $tokens ) === 1 && self::is( $tokens[0], T_CONSTANT_ENCAPSED_STRING ) ) {

            return self::stringValue( $tokens[0][1] );
        }

        if ( self::is( $tokens[0] ?? null, T_ARRAY ) ) {

            array_shift( $tokens );
        }

        if ( ( $tokens[0] ?? null ) !== '(' && ( $tokens[0] ?? null ) !== '[' ) {

            return null;
        }

        $list = [];

        foreach ( array_slice( $tokens, 1, -1 ) as $t ) {

            if ( $t === ',' ) {

                continue;
            }

            if ( ! self::is( $t, T_CONSTANT_ENCAPSED_STRING ) ) {

                return null;
            }

            $list[] = self::stringValue( $t[1] );
        }

        return $list;
    }

    /**
     * A quoted literal's value. Only the escapes a name could hold.
     *
     * @param  string $quoted
     * @return string
     */
    private static function stringValue( $quoted ) {

        return stripslashes( substr( (string) $quoted, 1, -1 ) );
    }

    /**
     * @param  mixed $token
     * @param  int   $id
     * @return bool
     */
    private static function is( $token, $id ) {

        return is_array( $token ) && $token[0] === $id;
    }

    /**
     * The problems, one sentence each, for the error log and the command line.
     * Values are left out: the file holds credentials.
     *
     * @param  list<array{line: int, call: string, replacement: string, screen?: bool, grants?: array<string, list<string>>}> $problems
     * @param  string $file
     * @return list<string>
     */
    public static function messages( array $problems, $file ) {

        $out = [];

        $name = basename( (string) $file );

        foreach ( $problems as $p ) {

            if ( $p['replacement'] !== '' ) {

                $out[] = sprintf( CoreAPI::t( '%1$s line %2$d: %3$s. Replace it with %4$s' ),
                    $name, $p['line'], $p['call'], $p['replacement'] );

            } elseif ( ! empty( $p['screen'] ) ) {

                $out[] = sprintf( CoreAPI::t( '%1$s line %2$d: %3$s. Remove it and set the value on its settings screen.' ),
                    $name, $p['line'], $p['call'] );

            } else {

                $out[] = sprintf( CoreAPI::t( '%1$s line %2$d: %3$s. OWA has no constant for this; remove it.' ),
                    $name, $p['line'], $p['call'] );
            }
        }

        /*
         * OWA_ROLE_CAPABILITIES can be defined once, so more than one grant
         * is one constant.
         */
        $grants = [];
        $count  = 0;

        foreach ( $problems as $p ) {

            foreach ( (array) ( $p['grants'] ?? [] ) as $role => $caps ) {

                $grants[ $role ] = array_values( array_unique( array_merge( $grants[ $role ] ?? [], $caps ) ) );
                $count++;
            }
        }

        if ( $count > 1 ) {

            $out[] = sprintf( CoreAPI::t( 'Define OWA_ROLE_CAPABILITIES once, with every grant: %s' ),
                self::roleCapabilitiesDefine( $grants ) );
        }

        if ( $out ) {

            array_unshift( $out, sprintf( CoreAPI::t( '%s is not read: it uses $this, which it cannot. Change these lines, then try again.' ),
                $name ) );
        }

        return $out;
    }

    /**
     * Run the file, outside any object.
     *
     * @param  string $file
     * @return void
     */
    public static function includeFile( $file ) {

        ( static function ( $__owa_config_file ) {

            include_once $__owa_config_file;
        } )( (string) $file );
    }
}
