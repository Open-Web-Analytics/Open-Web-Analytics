<?php
namespace OWA\Module\Base\Classes;

/**
 * The four secrets in owa-config.php, found and given new values in place
 * (cmd=reset-secrets).
 *
 * The file is read with PHP's tokenizer, so a define() is found however it is
 * spaced or quoted, and one inside a comment is not a define(). Only the value
 * literal of each is replaced; every other byte of the file is kept.
 *
 * The new file is written beside the old one, readable by its owner only
 * until it is complete, then given the old file's mode (and group, where the
 * user running this may set it) and renamed over it. Nothing is changed
 * unless each secret is defined exactly once, with a quoted string as its
 * value.
 */
class ConfigSecrets {

    /** The constants rotated, in the order they are reported. */
    const NAMES = [ 'OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT' ];

    /** Characters in a new value. */
    const LENGTH = 64;

    /** @var callable|null fn ( string $path ), called once the temp file exists and before it is written. TESTS ONLY. */
    public static $created = null;

    /**
     * Each secret's define() calls outside comments.
     *
     * @param  string $source a PHP file's contents
     * @return array<string, list<array{offset: int, literal: string|null}>>
     *         name => one entry per define(): the byte offset and text of its
     *         value's string literal, or null literal when the value is not a
     *         single quoted string
     */
    public static function locate( $source ) {

        $found = array_fill_keys( self::NAMES, [] );

        // The byte offset of each token: token_get_all() does not give one,
        // but the tokens' texts concatenate to the source.
        $tokens = [];
        $offset = 0;

        foreach ( token_get_all( (string) $source ) as $t ) {

            $text = is_array( $t ) ? $t[1] : $t;
            $id   = is_array( $t ) ? $t[0] : null;
            $offset += strlen( $text );

            // Comments and whitespace are never part of a match.
            if ( in_array( $id, [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {

                continue;
            }

            $tokens[] = [ 'id' => $id, 'text' => $text, 'offset' => $offset - strlen( $text ) ];
        }

        $count = count( $tokens );

        for ( $i = 0; $i < $count; $i++ ) {

            if ( ! self::isDefineCall( $tokens, $i ) ) {

                continue;
            }

            $name = $tokens[ $i + 2 ];

            if ( $name['id'] !== T_CONSTANT_ENCAPSED_STRING ) {

                continue;
            }

            $name_text = substr( $name['text'], 1, -1 );

            if ( ! in_array( $name_text, self::NAMES, true ) || ( $tokens[ $i + 3 ]['text'] ?? '' ) !== ',' ) {

                continue;
            }

            $value = $tokens[ $i + 4 ] ?? null;
            $after = $tokens[ $i + 5 ]['text'] ?? '';
            $plain = $value && $value['id'] === T_CONSTANT_ENCAPSED_STRING && ( $after === ')' || $after === ',' );

            $found[ $name_text ][] = [
                'offset'  => $value['offset'] ?? $name['offset'],
                'literal' => $plain ? $value['text'] : null,
            ];
        }

        return $found;
    }

    /**
     * Why the file cannot be rewritten: a secret not defined, defined more
     * than once, or with a value that is not a quoted string.
     *
     * @param  array<string, list<array{offset: int, literal: string|null}>> $found locate()'s answer
     * @return string[] empty when every secret can be rotated
     */
    public static function problems( array $found ) {

        $missing = [];
        $repeated = [];
        $unquoted = [];

        foreach ( self::NAMES as $name ) {

            $n = count( $found[ $name ] ?? [] );

            if ( $n === 0 ) {

                $missing[] = $name;

            } elseif ( $n > 1 ) {

                $repeated[] = sprintf( '%s (%d times)', $name, $n );

            } elseif ( $found[ $name ][0]['literal'] === null ) {

                $unquoted[] = $name;
            }
        }

        $problems = [];

        if ( $missing ) {

            $problems[] = 'Not defined: ' . implode( ', ', $missing ) . '.';
        }

        if ( $repeated ) {

            $problems[] = 'Defined more than once: ' . implode( ', ', $repeated ) . '.';
        }

        if ( $unquoted ) {

            $problems[] = 'Value is not a quoted string: ' . implode( ', ', $unquoted ) . '.';
        }

        return $problems;
    }

    /**
     * The source with each secret's value replaced. Each literal keeps its
     * quote character; the value is escaped for it.
     *
     * @param  string                                                   $source
     * @param  array<string, list<array{offset: int, literal: string|null}>> $found  locate()'s answer, with no problems()
     * @param  array<string, string>                                    $values name => new value
     * @return string
     */
    public static function replace( $source, array $found, array $values ) {

        $edits = [];

        foreach ( self::NAMES as $name ) {

            $at    = $found[ $name ][0];
            $quote = $at['literal'][0];
            $value = $quote === '"' ? addcslashes( $values[ $name ], '\\"$' ) : addcslashes( $values[ $name ], "\\'" );

            $edits[ $at['offset'] ] = [ strlen( $at['literal'] ), $quote . $value . $quote ];
        }

        // From the end, so an edit does not move the offsets before it.
        krsort( $edits );

        foreach ( $edits as $offset => [ $length, $literal ] ) {

            $source = substr_replace( $source, $literal, $offset, $length );
        }

        return $source;
    }

    /**
     * Give every secret in a config file a new value.
     *
     * @param  string        $path
     * @param  callable|null $random fn () => string, a new value; CoreAPI::secureRandomString() when null
     * @return array{error: string|null, warnings: string[], path: string}
     *         error is null when the file was replaced; otherwise nothing was changed
     */
    public static function rotate( $path, $random = null ) {

        $result = [ 'error' => null, 'warnings' => [], 'path' => (string) $path ];

        // Write the file a symlink points to, not over the link.
        $real = realpath( (string) $path );

        if ( $real === false || ! is_file( $real ) ) {

            $result['error'] = sprintf( '%s does not exist. Nothing was changed.', $path );

            return $result;
        }

        $result['path'] = $real;
        clearstatcache( true, $real );
        $stat   = @stat( $real );
        $source = @file_get_contents( $real );

        if ( $stat === false || $source === false ) {

            $result['error'] = sprintf( 'Could not read %s. Nothing was changed.', $real );

            return $result;
        }

        $found = self::locate( $source );
        $problems = self::problems( $found );

        if ( $problems ) {

            $result['error'] = sprintf( '%s was not changed. %s', $real, implode( ' ', $problems ) );

            return $result;
        }

        $random = $random ?: static fn () => \OWA\Core\CoreAPI::secureRandomString( self::LENGTH );
        $values = [];

        foreach ( self::NAMES as $name ) {

            $values[ $name ] = (string) $random();
        }

        $new = self::replace( $source, $found, $values );

        $temp = sprintf( '%s/.%s.%s.tmp', dirname( $real ), basename( $real ), bin2hex( random_bytes( 6 ) ) );
        $handle = self::createTemp( $temp );

        if ( ! $handle ) {

            $result['error'] = sprintf( 'Could not create %s. Nothing was changed.', $temp );

            return $result;
        }

        if ( self::$created ) {

            call_user_func( self::$created, $temp );
        }

        $written = fwrite( $handle, $new );
        $closed  = fclose( $handle );

        if ( $written !== strlen( $new ) || ! $closed ) {

            @unlink( $temp );
            $result['error'] = sprintf( 'Could not write %s. Nothing was changed.', $temp );

            return $result;
        }

        if ( ! @chmod( $temp, $stat['mode'] & 07777 ) ) {

            @unlink( $temp );
            $result['error'] = sprintf( 'Could not set the mode of %s. Nothing was changed.', $temp );

            return $result;
        }

        // Only an owner in the group (or root) can set it; otherwise say so.
        clearstatcache( true, $temp );

        if ( @filegroup( $temp ) !== $stat['gid'] && ! @chgrp( $temp, $stat['gid'] ) ) {

            $result['warnings'][] = sprintf( 'Could not keep group %d on %s; it has group %d.', $stat['gid'], $real, (int) @filegroup( $temp ) );
        }

        if ( @fileowner( $temp ) !== $stat['uid'] ) {

            $result['warnings'][] = sprintf( '%s was owned by uid %d and is now owned by uid %d.', $real, $stat['uid'], (int) @fileowner( $temp ) );
        }

        if ( ! @rename( $temp, $real ) ) {

            @unlink( $temp );
            $result['warnings'] = [];
            $result['error'] = sprintf( 'Could not replace %s. Nothing was changed.', $real );

            return $result;
        }

        // A pool worker would otherwise keep the compiled old copy until OPcache
        // next checks the timestamp.
        if ( function_exists( 'opcache_invalidate' ) ) {

            opcache_invalidate( $real, true );
        }

        return $result;
    }

    /**
     * Create a new file readable and writable by its owner only, before
     * anything is written to it.
     *
     * @param  string $path must not exist
     * @return resource|false open for writing
     */
    public static function createTemp( $path ) {

        $umask = umask( 0077 );
        $handle = @fopen( $path, 'x' );
        umask( $umask );

        if ( ! $handle ) {

            return false;
        }

        // Whatever the umask did, not readable by anyone else before a byte is in it.
        if ( ! @chmod( $path, 0600 ) ) {

            fclose( $handle );
            @unlink( $path );

            return false;
        }

        return $handle;
    }

    /**
     * Whether the token at $i starts `define ( 'NAME'`: a call to the
     * function, not a method or a declaration of that name.
     *
     * @param  list<array{id: int|null, text: string, offset: int}> $tokens
     * @param  int                                                  $i
     * @return bool
     */
    private static function isDefineCall( array $tokens, $i ) {

        $t = $tokens[ $i ];
        $is_name = ( $t['id'] === T_STRING && strtolower( $t['text'] ) === 'define' )
            || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && $t['id'] === T_NAME_FULLY_QUALIFIED && strtolower( $t['text'] ) === '\\define' );

        if ( ! $is_name || ( $tokens[ $i + 1 ]['text'] ?? '' ) !== '(' || ! isset( $tokens[ $i + 2 ] ) ) {

            return false;
        }

        $before = $i > 0 ? $tokens[ $i - 1 ]['id'] : null;

        return ! in_array( $before, [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR ], true );
    }
}
