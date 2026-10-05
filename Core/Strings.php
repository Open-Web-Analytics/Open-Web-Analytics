<?php
namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * User-facing strings, keyed by their English text.
 *
 * A message is written where it is used, wrapped in CoreAPI::t():
 *
 *   'errorMsg' => \OWA\Core\CoreAPI::t( 'Name is required.' )
 *   sprintf( \OWA\Core\CoreAPI::t( 'A funnel has at most %d steps; this one has %d.' ), $max, $n )
 *
 * THE ENGLISH IS THE KEY, as gettext's msgid is. The call site reads as the
 * message, and the catalogue -- conf/strings/en.php, every string t() is called
 * with -- is GENERATED from the code by `cli.php cmd=strings-extract`, so it
 * cannot drift from what the code says. A translation is a file beside it,
 * conf/strings/<locale>.php, mapping the English to the translated text; t()
 * answers the English for anything it does not hold.
 *
 * The same English meaning two things takes a context, the second argument, as
 * gettext's msgctxt does. A context is part of the key, joined with "\x04".
 *
 * No dependencies: settings.php and conf/messages.php call t() while OWA is
 * still booting.
 */
class Strings {

    /** gettext's context separator. */
    const CONTEXT_SEPARATOR = "\x04";

    /** The catalogue every other locale translates from. */
    const SOURCE_LOCALE = 'en';

    /** @var array|null English (or context + English) => translation, for the locale in use */
    private static $translations = null;

    /**
     * The text in the locale in use; the English when there is no translation.
     *
     * @param  string $text     the English
     * @param  string $context  optional, to tell two meanings of the same English apart
     * @return string
     */
    public static function t( $text, $context = '' ) {

        return self::translate( $text, $context );
    }

    /**
     * t() under a name the extractor does not read, for code that forwards a
     * string it was given (CoreAPI::t()) rather than writing one.
     */
    public static function translate( $text, $context = '' ) {

        $text = (string) $text;

        if ( self::$translations === null ) {

            self::$translations = self::load( self::locale() );
        }

        return self::$translations[ self::key( $text, $context ) ] ?? $text;
    }

    /** The catalogue key for a string and its context. */
    public static function key( $text, $context = '' ) {

        return $context === '' || $context === null
            ? (string) $text
            : $context . self::CONTEXT_SEPARATOR . $text;
    }

    /**
     * The locale in use: OWA_LOCALE when the config file sets it, else English.
     *
     * @return string
     */
    public static function locale() {

        return defined( 'OWA_LOCALE' ) && is_string( OWA_LOCALE ) && OWA_LOCALE !== ''
            ? OWA_LOCALE : self::SOURCE_LOCALE;
    }

    /** Use these translations instead of the locale's file (tests). Null reloads. */
    public static function useTranslations( $translations ) {

        self::$translations = $translations === null ? null : (array) $translations;
    }

    /** Where a locale's catalogue lives. */
    public static function path( $locale ) {

        return OWA_DIR . 'conf/strings/' . $locale . '.php';
    }

    /**
     * A locale's translations. English is the source, so it translates nothing:
     * its catalogue lists the strings and maps each to itself.
     *
     * @param  string $locale
     * @return array
     */
    private static function load( $locale ) {

        if ( $locale === self::SOURCE_LOCALE || ! preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale ) ) {

            return array();
        }

        $file = self::path( $locale );

        if ( ! is_readable( $file ) ) {

            return array();
        }

        $map = include $file;

        return is_array( $map ) ? $map : array();
    }

    /* ------------------------------------------------------------------
       Extraction
       ------------------------------------------------------------------ */

    /**
     * Every string the code passes to t(), as catalogue key => English.
     *
     * Read with the tokenizer, not a pattern: a call is CoreAPI::t( or
     * Strings::t( whose first argument is a string literal (or literals joined
     * with .), optionally followed by a literal context. A call whose text is
     * not a literal cannot be catalogued, and is reported in $problems.
     *
     * @param  string[] $roots     directories or files
     * @param  array    $problems  file:line => why, filled in
     * @return array    key => English, sorted
     */
    public static function extract( array $roots, &$problems = array() ) {

        $out      = array();
        $problems = array();

        foreach ( self::phpFiles( $roots ) as $file ) {

            foreach ( self::callsIn( (string) file_get_contents( $file ) ) as $call ) {

                if ( $call['text'] === null ) {

                    $problems[ $file . ':' . $call['line'] ] = 't() is given something other than a string literal';
                    continue;
                }

                $out[ self::key( $call['text'], (string) $call['context'] ) ] = $call['text'];
            }
        }

        ksort( $out, SORT_STRING );

        return $out;
    }

    /**
     * The t() calls in one file's source.
     *
     * @return array list of { line, text|null, context|null }
     */
    public static function callsIn( $source ) {

        $tokens = array_values( array_filter( token_get_all( $source ), function ( $t ) {

            return ! ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) );
        } ) );

        $calls = array();
        $n     = count( $tokens );

        for ( $i = 2; $i < $n - 1; $i++ ) {

            if ( ! self::isCallee( $tokens, $i ) ) {

                continue;
            }

            $line = $tokens[ $i ][2];
            $j    = $i + 2;

            list( $text, $j ) = self::literal( $tokens, $j );

            $context = '';

            if ( $text !== null && ( $tokens[ $j ] ?? null ) === ',' ) {

                list( $context, $j ) = self::literal( $tokens, $j + 1 );
            }

            $closes = ( $tokens[ $j ] ?? null ) === ')';

            $calls[] = array(
                'line'    => $line,
                'text'    => $closes ? $text : null,
                'context' => $closes ? $context : null,
            );
        }

        return $calls;
    }

    /** Is token $i the `t` of CoreAPI::t( or Strings::t( ? */
    private static function isCallee( array $tokens, $i ) {

        $t = $tokens[ $i ];

        if ( ! is_array( $t ) || $t[0] !== T_STRING || $t[1] !== 't' || ( $tokens[ $i + 1 ] ?? null ) !== '(' ) {

            return false;
        }

        if ( ! is_array( $tokens[ $i - 1 ] ) || $tokens[ $i - 1 ][0] !== T_DOUBLE_COLON ) {

            return false;
        }

        $class = $tokens[ $i - 2 ];

        if ( ! is_array( $class ) ) {

            return false;
        }

        $name = ltrim( $class[1], '\\' );

        return in_array( $name, array( 'CoreAPI', 'Strings', 'OWA\\Core\\CoreAPI', 'OWA\\Core\\Strings' ), true );
    }

    /**
     * A string literal, or literals joined with '.', starting at token $j.
     *
     * @return array [ string|null, index after it ]
     */
    private static function literal( array $tokens, $j ) {

        $text = null;

        while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_CONSTANT_ENCAPSED_STRING ) {

            $text = ( $text ?? '' ) . self::unquote( $tokens[ $j ][1] );
            $j++;

            if ( ( $tokens[ $j ] ?? null ) !== '.' ) {

                break;
            }

            $j++;
        }

        return array( $text, $j );
    }

    /** A PHP string literal's value. */
    private static function unquote( $literal ) {

        $body = substr( $literal, 1, -1 );

        if ( $literal[0] === "'" ) {

            return strtr( $body, array( "\\\\" => "\\", "\\'" => "'" ) );
        }

        return stripcslashes( $body );
    }

    /** @return string[] */
    private static function phpFiles( array $roots ) {

        $files = array();

        foreach ( $roots as $root ) {

            if ( is_file( $root ) ) {

                $files[] = $root;
                continue;
            }

            if ( ! is_dir( $root ) ) {

                continue;
            }

            $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

            foreach ( $it as $f ) {

                $path = (string) $f;

                if ( substr( $path, -4 ) === '.php' && ! preg_match( '#/(vendor|node_modules|tests|owa-data)/#', $path ) ) {

                    $files[] = $path;
                }
            }
        }

        sort( $files );

        return $files;
    }

    /** Where the code that calls t() lives. */
    public static function sourceRoots() {

        return array( OWA_DIR . 'Core', OWA_DIR . 'modules', OWA_DIR . 'conf/messages.php' );
    }

    /**
     * The English catalogue's file contents, for a map from extract().
     *
     * @param  array $map  key => English
     * @return string
     */
    public static function catalogueSource( array $map ) {

        $lines = array(
            '<?php',
            '//',
            '// GENERATED by `php cli.php cmd=strings-extract` -- do not edit by hand.',
            '//',
            '// Every user-facing string OWA passes to CoreAPI::t(), keyed by its English',
            '// (a context, where one is given, prefixes the key with "\\x04"). A',
            '// translation is conf/strings/<locale>.php: the same keys, translated values.',
            '//',
            'return array(',
        );

        foreach ( $map as $key => $english ) {

            $lines[] = '    ' . self::export( $key ) . ' => ' . self::export( $english ) . ',';
        }

        $lines[] = ');';

        return implode( "\n", $lines ) . "\n";
    }

    /** A string as a PHP expression; a context key as 'context' . "\x04" . 'text'. */
    private static function export( $value ) {

        $at = strpos( $value, self::CONTEXT_SEPARATOR );

        if ( $at !== false ) {

            return var_export( substr( $value, 0, $at ), true ) . ' . "\x04" . '
                   . var_export( substr( $value, $at + 1 ), true );
        }

        return var_export( $value, true );
    }
}
