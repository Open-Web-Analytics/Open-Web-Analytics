<?php
namespace OWA\Module\Base\Classes;

/**
 * The four secrets in owa-config.php, found and given new values in place
 * (cmd=reset-secrets); and OWA_SECRET, which they never include, added where
 * the file has none (addInstanceSecret()).
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

    /** The constants rotated, in the order they are reported. OWA_SECRET is never one of them. */
    const NAMES = [ 'OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT' ];

    /** The line addInstanceSecret() writes above the define() it adds. */
    const INSTANCE_SECRET_COMMENT = '// Names this server\'s private files (its error log, its file queue). Generated once; cmd=reset-secrets never changes it.';

    /** Characters in a new value. */
    const LENGTH = 64;

    /** @var callable|null fn ( string $path ), called once the temp file exists and before it is written. TESTS ONLY. */
    public static $created = null;

    /**
     * Each secret's define() calls outside comments.
     *
     * @param  string        $source a PHP file's contents
     * @param  list<string>  $names  the constants looked for; NAMES when null
     * @return array<string, list<array{offset: int, literal: string|null}>>
     *         name => one entry per define(): the byte offset and text of its
     *         value's string literal, or null literal when the value is not a
     *         single quoted string
     */
    public static function locate( $source, $names = null ) {

        $names = $names ?? self::NAMES;
        $found = array_fill_keys( $names, [] );

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

            if ( ! in_array( $name_text, $names, true ) || ( $tokens[ $i + 3 ]['text'] ?? '' ) !== ',' ) {

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

        $file = self::read( $path );

        if ( $file['error'] !== null ) {

            return self::result( $file );
        }

        $found = self::locate( $file['source'] );
        $problems = self::problems( $found );

        if ( $problems ) {

            $file['error'] = sprintf( '%s was not changed. %s', $file['path'], implode( ' ', $problems ) );

            return self::result( $file );
        }

        $random = self::generator( $random );
        $values = [];

        foreach ( self::NAMES as $name ) {

            $values[ $name ] = (string) $random();
        }

        return self::write( $file, self::replace( $file['source'], $found, $values ) );
    }

    /**
     * What addInstanceSecret() adds to a file for this value: a blank line,
     * INSTANCE_SECRET_COMMENT and the define(), each ending in a newline.
     *
     * @param  string $value
     * @return string
     */
    public static function instanceSecretLines( $value ) {

        return "\n" . self::INSTANCE_SECRET_COMMENT . "\n"
            . sprintf( "define('%s', '%s');\n", \OWA\Core\Requirements::INSTANCE_SECRET, addcslashes( (string) $value, "\\'" ) );
    }

    /**
     * Give a config file an OWA_SECRET when it has none:
     *
     *  - no define() of it: instanceSecretLines() are added at the end of the
     *    file's PHP, before a closing ?> where it has one;
     *  - one whose value is owa-config-dist.php's placeholder: the value is replaced;
     *  - one with any other value: nothing is changed, and written is null.
     *
     * Written as rotate() writes. Nothing is changed when OWA_SECRET is
     * defined more than once, or with a value that is not a quoted string.
     *
     * @param  string        $path
     * @param  callable|null $random fn () => string, the new value; CoreAPI::secureRandomString() when null
     * @return array{error: string|null, warnings: string[], path: string, written: string|null}
     *         written: 'added', 'replaced', or null when nothing was written
     */
    public static function addInstanceSecret( $path, $random = null ) {

        $name = \OWA\Core\Requirements::INSTANCE_SECRET;
        $file = self::read( $path );

        if ( $file['error'] !== null ) {

            return self::result( $file ) + [ 'written' => null ];
        }

        $found = self::locate( $file['source'], [ $name ] )[ $name ];

        if ( count( $found ) > 1 ) {

            $file['error'] = sprintf( '%s was not changed. Defined more than once: %s (%d times).', $file['path'], $name, count( $found ) );

            return self::result( $file ) + [ 'written' => null ];
        }

        if ( $found && $found[0]['literal'] === null ) {

            $file['error'] = sprintf( '%s was not changed. Value is not a quoted string: %s.', $file['path'], $name );

            return self::result( $file ) + [ 'written' => null ];
        }

        $value = (string) self::generator( $random )();

        if ( $found ) {

            // The placeholders hold nothing a quote escapes, so the literal's text is the value.
            if ( ! in_array( substr( $found[0]['literal'], 1, -1 ), \OWA\Core\Requirements::SECRET_PLACEHOLDERS, true ) ) {

                return self::result( $file ) + [ 'written' => null ];
            }

            $quote = $found[0]['literal'][0];
            $literal = $quote . ( $quote === '"' ? addcslashes( $value, '\\"$' ) : addcslashes( $value, "\\'" ) ) . $quote;

            $result = self::write( $file, substr_replace( $file['source'], $literal, $found[0]['offset'], strlen( $found[0]['literal'] ) ) );

            return $result + [ 'written' => $result['error'] === null ? 'replaced' : null ];
        }

        $at  = self::endOfCode( $file['source'] );
        $new = substr( $file['source'], 0, $at ) . self::instanceSecretLines( $value ) . substr( $file['source'], $at );

        // The define() added is one PHP reads: the only one, outside a comment.
        if ( count( self::locate( $new, [ $name ] )[ $name ] ) !== 1 ) {

            $file['error'] = sprintf( '%s was not changed. Where its code ends could not be found.', $file['path'] );

            return self::result( $file ) + [ 'written' => null ];
        }

        $result = self::write( $file, $new );

        if ( $result['error'] !== null ) {

            $result['error'] .= ' ' . self::byHand( $file['path'] );

            return $result + [ 'written' => null ];
        }

        return $result + [ 'written' => 'added' ];
    }

    /**
     * What to do when OWA cannot add OWA_SECRET itself. The line is given
     * without a value: one is never printed or logged.
     *
     * @param  string $path
     * @return string
     */
    public static function byHand( $path ) {

        return sprintf( \OWA\Core\CoreAPI::t( 'Add this line to %1$s, above any closing ?>, with a random string of at least %2$d characters in place of RANDOM: define(\'OWA_SECRET\', \'RANDOM\'); This prints one: %3$s' ),
            $path, \OWA\Core\Requirements::SECRET_MIN_LENGTH, \OWA\Core\Requirements::INSTANCE_SECRET_COMMAND );
    }

    /**
     * Take out lines addInstanceSecret() added, when the file still holds
     * them exactly: the lines whose SHA-256 is $sha256. Written as rotate()
     * writes.
     *
     * @param  string $path
     * @param  string $sha256 hash( 'sha256', instanceSecretLines( $value ) ) of the lines added
     * @return array{error: string|null, warnings: string[], path: string, removed: bool}
     *         removed is false, with no error, when the file does not hold them
     */
    public static function removeInstanceSecret( $path, $sha256 ) {

        $file = self::read( $path );

        if ( $file['error'] !== null ) {

            return self::result( $file ) + [ 'removed' => false ];
        }

        $pattern = '/' . preg_quote( "\n" . self::INSTANCE_SECRET_COMMENT . "\n", '/' )
            . preg_quote( sprintf( "define('%s', '", \OWA\Core\Requirements::INSTANCE_SECRET ), '/' )
            . "(?:[^'\\\\]|\\\\.)*'\\);\n/";

        preg_match_all( $pattern, $file['source'], $matches, PREG_OFFSET_CAPTURE );

        foreach ( $matches[0] as [ $lines, $offset ] ) {

            if ( hash_equals( (string) $sha256, hash( 'sha256', $lines ) ) ) {

                return self::write( $file, substr_replace( $file['source'], '', $offset, strlen( $lines ) ) ) + [ 'removed' => true ];
            }
        }

        return self::result( $file ) + [ 'removed' => false ];
    }

    /**
     * The byte offset at which code added to the end of a file still runs:
     * before its last closing ?> when nothing but output follows it, else the
     * end of the file.
     *
     * @param  string $source
     * @return int
     */
    private static function endOfCode( $source ) {

        $offset = 0;
        $close  = null;

        foreach ( token_get_all( (string) $source ) as $t ) {

            $id   = is_array( $t ) ? $t[0] : null;
            $text = is_array( $t ) ? $t[1] : $t;

            if ( $id === T_CLOSE_TAG ) {

                $close = $offset;

            } elseif ( $id !== T_INLINE_HTML ) {

                $close = null;
            }

            $offset += strlen( $text );
        }

        return $close ?? strlen( (string) $source );
    }

    /**
     * @param  callable|null $random
     * @return callable fn () => string
     */
    private static function generator( $random ) {

        return $random ?: static fn () => \OWA\Core\CoreAPI::secureRandomString( self::LENGTH );
    }

    /**
     * A config file read for a rewrite: the file a symlink points to.
     *
     * @param  string $path
     * @return array{error: string|null, warnings: string[], path: string, stat: array<mixed>|null, source: string}
     */
    private static function read( $path ) {

        $file = [ 'error' => null, 'warnings' => [], 'path' => (string) $path, 'stat' => null, 'source' => '' ];

        // Write the file a symlink points to, not over the link.
        $real = realpath( (string) $path );

        if ( $real === false || ! is_file( $real ) ) {

            $file['error'] = sprintf( '%s does not exist. Nothing was changed.', $path );

            return $file;
        }

        $file['path'] = $real;
        clearstatcache( true, $real );
        $stat   = @stat( $real );
        $source = @file_get_contents( $real );

        if ( $stat === false || $source === false ) {

            $file['error'] = sprintf( 'Could not read %s. Nothing was changed.', $real );

            return $file;
        }

        $file['stat']   = $stat;
        $file['source'] = $source;

        return $file;
    }

    /**
     * @param  array{error: string|null, warnings: string[], path: string} $file read()'s answer
     * @return array{error: string|null, warnings: string[], path: string}
     */
    private static function result( array $file ) {

        return [ 'error' => $file['error'], 'warnings' => $file['warnings'], 'path' => $file['path'] ];
    }

    /**
     * Replace a file read() read with $new: written beside it, readable by
     * its owner only until complete, then given the old file's mode (and
     * group, where it may be set) and renamed over it.
     *
     * @param  array{error: string|null, warnings: string[], path: string, stat: array<mixed>, source: string} $file read()'s answer, with no error
     * @param  string $new
     * @return array{error: string|null, warnings: string[], path: string}
     *         error is null when the file was replaced; otherwise nothing was changed
     */
    private static function write( array $file, $new ) {

        $result = self::result( $file );
        $real   = $file['path'];
        $stat   = $file['stat'];

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
