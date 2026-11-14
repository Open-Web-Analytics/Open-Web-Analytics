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
 * A message with a count takes both English forms, as gettext's ngettext does:
 *
 *   sprintf( \OWA\Core\CoreAPI::tn( '%d report', '%d reports', $n ), $n )
 *
 * Its key is the singular; its catalogue value is the list of forms, as many
 * as the locale's plural rule has (PLURAL_RULES), in that rule's order.
 *
 * The reporting JavaScript has the same pair, OWA.l() and OWA.ln(). The
 * extractor reads those calls too, and lists their keys in
 * conf/strings/script-keys.php; a page in a translated locale is sent the
 * translations of those keys (forScript()).
 *
 * No dependencies: settings.php and conf/messages.php call t() while OWA is
 * still booting.
 */
class Strings {

    /**
     * Tables of labels that are shown translated (OWA.translate()) but kept
     * as plain literals, not OWA.l() calls, because other code reads them as
     * data -- the wiki generator, tests. The extractor catalogues each label
     * as a key the reporting JavaScript uses:
     *
     *   'Class::CONSTANT'  PHP: the value of each 'label' key in the constant
     *   'OWA.name.NAME'    JavaScript: each value of the object assigned to
     *                      it, and the last element of each [ value, label ]
     */
    const LABEL_TABLES = array(
        'ConfiguredReport::ROW_ACTIONS',
        'MetricSets::SETS',
        'OWA.dataGrid.EMPTY_TESTS',
        'OWA.dataGrid.OPERATOR_PHRASES',
        'OWA.dataGrid.CONDITIONS',
        'OWA.filterPills.OPERATORS',
        'OWA.filterPills.EMPTY_TESTS',
    );

    /**
     * The keys of a metric or dimension in a module's config/metrics.php and
     * config/dimensions.php that are shown translated. Core\Module translates
     * them as it reads the file; the extractor catalogues each string literal
     * given to one.
     */
    const VOCABULARY_KEYS = array( 'label', 'description' );

    /**
     * The keys of a report definition (a module's reports/*.json) that are
     * shown translated, and of each of its widgets. ConfiguredReport::
     * translated() translates them; the extractor catalogues them.
     */
    const REPORT_KEYS = array( 'title', 'titleSuffix', 'description' );

    const REPORT_WIDGET_KEYS = array( 'title' );

    /**
     * Constants whose string values are labels the server translates
     * (Strings::translate()) where it shows them, but which stay plain
     * literals because other code reads them as data -- the wiki generator,
     * tests. The extractor catalogues each value: the constant itself when it
     * is a string, else every value of the array (not its keys).
     */
    const VALUE_TABLES = array(
        'CustomReports::WIDGET_TYPES',
        'CustomReports::WIDGET_TYPE_HINTS',
        'CustomReports::WIDTHS',
        'CustomReports::HEIGHTS',
        'CustomReports::MORE_LABEL',
        'CustomReports::VISUALIZATION_TYPES',
        'CustomReports::VISUALIZATION_TYPE_HINTS',
        'ServerRole::RETIRED_CONSTANTS',
    );

    /** gettext's context separator. */
    const CONTEXT_SEPARATOR = "\x04";

    /** The catalogue every other locale translates from. */
    const SOURCE_LOCALE = 'en';

    /**
     * The plural rule of each locale or language that does not use English's.
     *
     * A rule picks which of a message's forms a count takes; see pluralIndex().
     * A locale is looked up whole (pt_BR), then by its language (pt).
     * OWA.pluralIndex() in the reporting JavaScript implements the same rules.
     */
    const PLURAL_RULES = array(
        'ja' => 'none', 'ko' => 'none', 'zh' => 'none', 'th' => 'none', 'vi' => 'none', 'id' => 'none', 'ms' => 'none',
        'fr' => 'french', 'pt_br' => 'french',
        'ru' => 'russian', 'uk' => 'russian', 'be' => 'russian', 'sr' => 'russian', 'hr' => 'russian', 'bs' => 'russian',
        'pl' => 'polish',
        'cs' => 'czech', 'sk' => 'czech',
    );

    /** The rule of a locale PLURAL_RULES does not name: one, and everything else. */
    const DEFAULT_PLURAL_RULE = 'english';

    /** @var array|null English (or context + English) => translation, for the locale in use */
    private static $translations = null;

    /** @var string|null the plural rule set by useTranslations() (tests) */
    private static $plural_rule = null;

    /** @var string|null the locale set by useLocale() (tests) */
    private static $locale = null;

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

        $text        = (string) $text;
        $translation = self::translations()[ self::key( $text, $context ) ] ?? null;

        return is_string( $translation ) ? $translation : $text;
    }

    /**
     * The form of a counted message for $n in the locale in use; the English
     * singular or plural when there is no translation. Fill in the count with
     * sprintf().
     *
     * @param  string    $singular the English for one
     * @param  string    $plural   the English for any other count
     * @param  int|float $n        the count
     * @param  string    $context  optional, as t()'s
     * @return string
     */
    public static function tn( $singular, $plural, $n, $context = '' ) {

        return self::translatePlural( $singular, $plural, $n, $context );
    }

    /** tn() under a name the extractor does not read; see translate(). */
    public static function translatePlural( $singular, $plural, $n, $context = '' ) {

        $forms = self::translations()[ self::key( $singular, $context ) ] ?? null;

        if ( is_array( $forms ) ) {

            $form = $forms[ self::pluralIndex( $n, self::pluralRule() ) ] ?? null;

            if ( is_string( $form ) && $form !== '' ) {

                return $form;
            }
        }

        return self::pluralIndex( $n, self::DEFAULT_PLURAL_RULE ) === 0 ? (string) $singular : (string) $plural;
    }

    /**
     * The plural rule of the locale in use, or of $locale.
     *
     * @param  string|null $locale
     * @return string a rule pluralIndex() knows
     */
    public static function pluralRule( $locale = null ) {

        if ( $locale === null && self::$plural_rule !== null ) {

            return self::$plural_rule;
        }

        $locale = strtolower( str_replace( '-', '_', (string) ( $locale ?? self::locale() ) ) );

        return self::PLURAL_RULES[ $locale ]
            ?? self::PLURAL_RULES[ explode( '_', $locale )[0] ]
            ?? self::DEFAULT_PLURAL_RULE;
    }

    /**
     * Which form a count takes under a plural rule: 0 for the first form.
     *
     * The count is its absolute value, truncated to an integer.
     *
     *   english   1 | other                                  2 forms
     *   french    0, 1 | other                               2 forms
     *   none      every count                                1 form
     *   russian   1, 21, 31.. | 2-4, 22-24.. | other         3 forms (not 11-14)
     *   polish    1 | 2-4, 22-24.. | other                   3 forms (not 12-14)
     *   czech     1 | 2-4 | other                            3 forms
     *
     * @param  int|float $n
     * @param  string    $rule
     * @return int
     */
    public static function pluralIndex( $n, $rule ) {

        $n   = abs( (int) $n );
        $few = $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 10 || $n % 100 >= 20 );

        switch ( $rule ) {

            case 'none':
                return 0;

            case 'french':
                return $n > 1 ? 1 : 0;

            case 'russian':
                return $n % 10 === 1 && $n % 100 !== 11 ? 0 : ( $few ? 1 : 2 );

            case 'polish':
                return $n === 1 ? 0 : ( $few ? 1 : 2 );

            case 'czech':
                return $n === 1 ? 0 : ( $n >= 2 && $n <= 4 ? 1 : 2 );

            default:
                return $n === 1 ? 0 : 1;
        }
    }

    /**
     * What a page sends the reporting JavaScript: the translations of the keys
     * it uses, and the plural rule. Null for English, or when nothing it uses
     * is translated: OWA.l() then answers the English.
     *
     * @param  string[]|null $keys  the keys the JavaScript uses; null reads conf/strings/script-keys.php
     * @return array|null    { strings: key => translation, plural: rule }
     */
    public static function forScript( $keys = null ) {

        $translations = self::translations();

        if ( ! $translations ) {

            return null;
        }

        if ( $keys === null ) {

            $file = self::scriptKeysPath();
            $keys = is_readable( $file ) ? include $file : array();
        }

        $strings = array_intersect_key( $translations, array_flip( (array) $keys ) );

        return $strings ? array( 'strings' => $strings, 'plural' => self::pluralRule() ) : null;
    }

    /** The locale's translations, loaded once. */
    private static function translations() {

        if ( self::$translations === null ) {

            self::$translations = self::load( self::locale() );
        }

        return self::$translations;
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

        if ( self::$locale !== null ) {

            return self::$locale;
        }

        return defined( 'OWA_LOCALE' ) && is_string( OWA_LOCALE ) && OWA_LOCALE !== ''
            ? OWA_LOCALE : self::SOURCE_LOCALE;
    }

    /**
     * The locale as an HTML lang attribute (BCP 47: pt_BR is pt-BR). A locale
     * that is not a language tag is English, as load() treats it.
     *
     * @param  string|null $locale  the locale in use when null
     * @return string
     */
    public static function htmlLang( $locale = null ) {

        $locale = $locale === null ? self::locale() : (string) $locale;

        return preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale )
            ? str_replace( '_', '-', $locale ) : self::SOURCE_LOCALE;
    }

    /**
     * Use these translations, and optionally this plural rule, instead of the
     * locale's (tests). Null reloads both.
     */
    public static function useTranslations( $translations, $plural_rule = null ) {

        self::$translations = $translations === null ? null : (array) $translations;
        self::$plural_rule  = $translations === null ? null : $plural_rule;
    }

    /**
     * Use this locale instead of OWA_LOCALE (tests). Null goes back to it.
     * Translations are not reloaded: see useTranslations().
     */
    public static function useLocale( $locale ) {

        self::$locale = $locale === null ? null : (string) $locale;
    }

    /**
     * A moment as a reader of the locale in use writes it.
     *
     * English is what $english, a date() format, gives -- the same in every
     * build. Any other locale, where the intl extension is loaded, is the
     * locale's own pattern for $skeleton, an ICU skeleton (the fields wanted,
     * in no order: 'dMMMy' is a day, an abbreviated month and a year); without
     * intl it is the English.
     *
     * @param  int    $timestamp
     * @param  string $skeleton  ICU fields, e.g. 'dMMMy', 'EEEdMMMHHmm'
     * @param  string $english   the date() format for English, e.g. 'j M Y'
     * @param  string|null $timezone  date_default_timezone_get() when null
     * @return string
     */
    public static function formatDate( $timestamp, $skeleton, $english, $timezone = null ) {

        $timestamp = (int) $timestamp;
        $timezone  = $timezone ?? date_default_timezone_get();
        $locale    = self::locale();

        if ( self::isSourceLocale( $locale ) || ! class_exists( '\IntlDateFormatter' ) ) {

            return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( $timezone ) )->format( $english );
        }

        $pattern = $skeleton;

        if ( class_exists( '\IntlDatePatternGenerator' ) ) {

            $best    = ( new \IntlDatePatternGenerator( $locale ) )->getBestPattern( $skeleton );
            $pattern = is_string( $best ) && $best !== '' ? $best : $skeleton;
        }

        $formatter = new \IntlDateFormatter( $locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE,
            $timezone, \IntlDateFormatter::GREGORIAN, $pattern );
        $out       = $formatter->format( $timestamp );

        return is_string( $out ) ? $out : date( $english, $timestamp );
    }

    /**
     * A number as a reader of the locale in use writes it: English groups
     * thousands with "," and marks decimals with "."; another locale, where
     * the intl extension is loaded, uses its own separators. For display
     * only -- an API answers the number itself.
     *
     * @param  int|float $number
     * @param  int       $decimals  digits after the decimal mark
     * @return string
     */
    public static function formatNumber( $number, $decimals = 0 ) {

        $locale = self::locale();

        if ( self::isSourceLocale( $locale ) || ! class_exists( '\NumberFormatter' ) ) {

            return number_format( (float) $number, (int) $decimals );
        }

        $formatter = new \NumberFormatter( $locale, \NumberFormatter::DECIMAL );
        $formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, (int) $decimals );
        $formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, (int) $decimals );
        // Halves round up, as number_format() rounds the English.
        $formatter->setAttribute( \NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP );
        $out = $formatter->format( $decimals > 0 ? (float) $number : round( (float) $number ) );

        return is_string( $out ) ? $out : number_format( (float) $number, (int) $decimals );
    }

    /**
     * A fraction as a percentage, as a reader of the locale in use writes it:
     * 0.1234 is "12.34%" in English. For display only, as formatNumber().
     *
     * @param  int|float $fraction
     * @param  int       $decimals  digits after the decimal mark
     * @return string
     */
    public static function formatPercent( $fraction, $decimals = 2 ) {

        $locale = self::locale();

        if ( self::isSourceLocale( $locale ) || ! class_exists( '\NumberFormatter' ) ) {

            return number_format( (float) $fraction * 100, (int) $decimals ) . '%';
        }

        $formatter = new \NumberFormatter( $locale, \NumberFormatter::PERCENT );
        $formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, (int) $decimals );
        $formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, (int) $decimals );
        // Halves round up, as number_format() rounds the English.
        $formatter->setAttribute( \NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP );
        $out = $formatter->format( (float) $fraction );

        return is_string( $out ) ? $out : number_format( (float) $fraction * 100, (int) $decimals ) . '%';
    }

    /** Whether a locale is the source's own: English as the code writes it. */
    private static function isSourceLocale( $locale ) {

        return $locale === self::SOURCE_LOCALE || ! preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', (string) $locale );
    }

    /** Where a locale's catalogue lives. */
    public static function path( $locale ) {

        return \OWA\Core\Env::confDir() . 'strings/' . $locale . '.php';
    }

    /** Where the list of keys the reporting JavaScript uses lives. Not a locale's name. */
    public static function scriptKeysPath() {

        return \OWA\Core\Env::confDir() . 'strings/script-keys.php';
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
     * Every string the code passes to t() or tn(), and the reporting
     * JavaScript to OWA.l() or OWA.ln(), as catalogue key => English: a
     * string, or [ singular, plural ] for a counted message.
     *
     * Read with a tokenizer, not a pattern: a call is CoreAPI::t( or
     * Strings::t( (OWA.l( in JavaScript) whose first argument is a string
     * literal, or literals joined with . (+ in JavaScript), optionally followed
     * by a literal context. tn( and OWA.ln( take two literals, the count, then
     * the optional context. A call whose text is not a literal cannot be
     * catalogued, and is reported in $problems; so is a key catalogued once as
     * a single string and once as a counted one, or with two plurals. The
     * labels of LABEL_TABLES are catalogued too, as keys the JavaScript uses;
     * so are the VOCABULARY_KEYS of the metric and dimension config files and
     * the REPORT_KEYS of the report definitions, which the server translates.
     *
     * @param  string[] $roots       directories or files
     * @param  array    $problems    file:line => why, filled in
     * @param  array    $script_keys the keys the JavaScript uses, sorted, filled in
     * @param-out list<string> $script_keys
     * @return array    key => English, sorted
     */
    public static function extract( array $roots, &$problems = array(), &$script_keys = array() ) {

        $out      = array();
        $problems = array();
        $script   = array();

        foreach ( self::sourceFiles( $roots ) as $file ) {

            $is_js  = substr( $file, -3 ) === '.js';
            $source = (string) file_get_contents( $file );

            if ( substr( $file, -5 ) === '.json' ) {

                $is_js  = false;
                $labels = self::reportLabelsIn( $source );

            } elseif ( preg_match( self::VOCABULARY_FILES, $file ) ) {

                $labels = self::vocabularyLabelsIn( $source );

            } else {

                $labels = $is_js ? self::scriptTableLabelsIn( $source )
                    : array_merge( self::tableLabelsIn( $source ), self::valueTablesIn( $source ) );
            }

            $calls = substr( $file, -5 ) === '.json' ? array() : ( $is_js ? self::scriptCallsIn( $source ) : self::callsIn( $source ) );

            foreach ( array_merge( $calls, $labels ) as $call ) {

                $where = $file . ':' . $call['line'];

                if ( $call['text'] === null ) {

                    $problems[ $where ] = strpos( $call['function'], 'vocabulary ' ) === 0
                        ? sprintf( '%s is given something other than a string literal', substr( $call['function'], 11 ) )
                        : sprintf( '%s() is given something other than a string literal', $call['function'] );
                    continue;
                }

                $key     = self::key( $call['text'], (string) $call['context'] );
                $english = $call['plural'] === null ? $call['text'] : array( $call['text'], $call['plural'] );

                if ( isset( $out[ $key ] ) && $out[ $key ] !== $english ) {

                    $problems[ $where ] = is_array( $out[ $key ] ) && is_array( $english )
                        ? 'the same singular is catalogued with two plurals'
                        : 'the same English is catalogued as a single string and as a counted one';
                    continue;
                }

                $out[ $key ] = $english;

                if ( $is_js || $call['function'] === 'label table' ) {

                    $script[ $key ] = (string) $key;
                }
            }
        }

        ksort( $out, SORT_STRING );
        ksort( $script, SORT_STRING );

        $script_keys = array_values( $script );

        return $out;
    }

    /**
     * The t() and tn() calls in one PHP file's source.
     *
     * @return array list of { line, function, text|null, plural|null, context|null }
     */
    public static function callsIn( $source ) {

        return self::calls( self::phpTokens( $source ), '.', function ( array $tokens, $i ) {

            if ( ( $tokens[ $i - 1 ][1] ?? null ) !== '::' || ! isset( $tokens[ $i - 2 ] ) ) {

                return false;
            }

            return in_array( ltrim( $tokens[ $i - 2 ][1], '\\' ), array( 'CoreAPI', 'Strings', 'OWA\\Core\\CoreAPI', 'OWA\\Core\\Strings' ), true );

        }, 't', 'tn' );
    }

    /**
     * The labels of the LABEL_TABLES constants a PHP file's class declares:
     * the value of each 'label' key, where that value is a string literal.
     *
     * @param  string        $source
     * @param  string[]|null $tables  LABEL_TABLES when null
     * @return array list of { line, function: 'label table', text, plural: null, context: '' }
     */
    public static function tableLabelsIn( $source, $tables = null ) {

        $tokens = self::phpTokens( $source );
        $tables = $tables ?? self::LABEL_TABLES;
        $n      = count( $tokens );
        $class  = null;
        $out    = array();

        for ( $i = 0; $i < $n - 1; $i++ ) {

            $t = $tokens[ $i ];

            if ( $class === null && $t[0] === 'x' && strtolower( $t[1] ) === 'class'
                && ( $tokens[ $i - 1 ][1] ?? null ) !== '::' && $tokens[ $i + 1 ][0] === 'x' ) {

                $class = $tokens[ $i + 1 ][1];
                continue;
            }

            if ( $class === null || $t[0] !== 'x' || strtolower( $t[1] ) !== 'const'
                || ! in_array( $class . '::' . ( $tokens[ $i + 1 ][1] ?? '' ), $tables, true )
                || ( $tokens[ $i + 2 ][1] ?? null ) !== '=' ) {

                continue;
            }

            $open = $i + 3;

            if ( ( $tokens[ $open ][1] ?? null ) === 'array' ) {

                $open++;
            }

            $close = self::closing( $tokens, $open );

            for ( $j = $open + 1; $j < $close - 2; $j++ ) {

                if ( $tokens[ $j ][0] === 's' && $tokens[ $j ][1] === 'label' && $tokens[ $j + 1 ][1] === '=>'
                    && $tokens[ $j + 2 ][0] === 's' && in_array( $tokens[ $j + 3 ][1] ?? null, array( ',', ')', ']' ), true ) ) {

                    $out[] = self::tableLabel( $tokens[ $j + 2 ] );
                }
            }
        }

        return $out;
    }

    /**
     * The labels of the LABEL_TABLES objects a JavaScript file assigns:
     * each value of the object, and the last element of each array of two or
     * more, where that is a string literal.
     *
     * @param  string        $source
     * @param  string[]|null $tables  LABEL_TABLES when null
     * @return array as tableLabelsIn()'s
     */
    public static function scriptTableLabelsIn( $source, $tables = null ) {

        $tokens = self::scriptTokens( $source );
        $tables = $tables ?? self::LABEL_TABLES;
        $n      = count( $tokens );
        $out    = array();

        for ( $i = 0; $i < $n; $i++ ) {

            if ( $tokens[ $i ][0] !== 'x' || $tokens[ $i ][1] !== 'OWA' || ( $tokens[ $i - 1 ][1] ?? null ) === '.' ) {

                continue;
            }

            $name = 'OWA';
            $j    = $i + 1;

            while ( ( $tokens[ $j ][1] ?? null ) === '.' && isset( $tokens[ $j + 1 ] ) && $tokens[ $j + 1 ][0] === 'x' ) {

                $name .= '.' . $tokens[ $j + 1 ][1];
                $j    += 2;
            }

            if ( ! in_array( $name, $tables, true ) || ( $tokens[ $j ][1] ?? null ) !== '='
                || ! in_array( $tokens[ $j + 1 ][1] ?? null, array( '{', '[' ), true ) ) {

                continue;
            }

            $close = self::closing( $tokens, $j + 1 );

            for ( $k = $j + 2; $k < $close; $k++ ) {

                if ( $tokens[ $k ][0] !== 's' ) {

                    continue;
                }

                $before = $tokens[ $k - 1 ][1];
                $after  = $tokens[ $k + 1 ][1] ?? null;

                if ( $before === ':' || ( $before === ',' && $after === ']' ) ) {

                    $out[] = self::tableLabel( $tokens[ $k ] );
                }
            }

            $i = $close;
        }

        return $out;
    }

    /**
     * The values of the VALUE_TABLES constants a PHP file's class declares:
     * a string constant's literal, or each string literal that is an array
     * element's value.
     *
     * @param  string        $source
     * @param  string[]|null $tables  VALUE_TABLES when null
     * @return array list of { line, function: 'value table', text, plural: null, context: '' }
     */
    public static function valueTablesIn( $source, $tables = null ) {

        $tokens = self::phpTokens( $source );
        $tables = $tables ?? self::VALUE_TABLES;
        $n      = count( $tokens );
        $class  = null;
        $out    = array();

        for ( $i = 0; $i < $n - 2; $i++ ) {

            $t = $tokens[ $i ];

            if ( $class === null && $t[0] === 'x' && strtolower( $t[1] ) === 'class'
                && ( $tokens[ $i - 1 ][1] ?? null ) !== '::' && $tokens[ $i + 1 ][0] === 'x' ) {

                $class = $tokens[ $i + 1 ][1];
                continue;
            }

            if ( $class === null || $t[0] !== 'x' || strtolower( $t[1] ) !== 'const'
                || ! in_array( $class . '::' . $tokens[ $i + 1 ][1], $tables, true ) || $tokens[ $i + 2 ][1] !== '=' ) {

                continue;
            }

            $value = $tokens[ $i + 3 ] ?? null;

            if ( $value && $value[0] === 's' ) {

                $out[] = array( 'line' => $value[2], 'function' => 'value table', 'text' => $value[1], 'plural' => null, 'context' => '' );
                continue;
            }

            $open  = $i + 3 + ( ( $value[1] ?? null ) === 'array' ? 1 : 0 );
            $close = self::closing( $tokens, $open );

            for ( $j = $open + 1; $j < $close; $j++ ) {

                $before = $tokens[ $j - 1 ][1];
                $after  = $tokens[ $j + 1 ][1] ?? null;

                if ( $tokens[ $j ][0] === 's' && ( $before === '=>' || $before === ',' || $j === $open + 1 )
                    && $after !== '=>' ) {

                    $out[] = array( 'line' => $tokens[ $j ][2], 'function' => 'value table', 'text' => $tokens[ $j ][1], 'plural' => null, 'context' => '' );
                }
            }
        }

        return $out;
    }

    /** A table's label as a call, for extract(). */
    private static function tableLabel( array $token ) {

        return array( 'line' => $token[2], 'function' => 'label table', 'text' => $token[1], 'plural' => null, 'context' => '' );
    }

    /** The metric and dimension vocabularies Core\Module reads, by path. */
    const VOCABULARY_FILES = '#/modules/[^/]+/config/(metrics|dimensions)\.php$#';

    /**
     * The strings of a metric or dimension vocabulary file that are shown
     * translated: the value of each VOCABULARY_KEYS key that is a string
     * literal, or literals joined with `.`. A value that is not is reported.
     *
     * @return array list of { line, function: 'vocabulary', text|null, plural: null, context: '' }
     */
    public static function vocabularyLabelsIn( $source ) {

        $tokens = self::phpTokens( $source );
        $out    = array();

        foreach ( $tokens as $i => $t ) {

            if ( $t[0] !== 's' || ! in_array( $t[1], self::VOCABULARY_KEYS, true ) || ( $tokens[ $i + 1 ][1] ?? null ) !== '=>' ) {

                continue;
            }

            list( $text, $j ) = self::literal( $tokens, $i + 2, '.' );

            $out[] = array(
                'line'     => $t[2],
                'function' => 'vocabulary ' . $t[1],
                'text'     => in_array( $tokens[ $j ][1] ?? null, array( ',', ')', ']' ), true ) ? $text : null,
                'plural'   => null,
                'context'  => '',
            );
        }

        return $out;
    }

    /**
     * The strings of a report definition (JSON) that are shown translated:
     * REPORT_KEYS of the report and REPORT_WIDGET_KEYS of each widget. A
     * definition that is not JSON gives none.
     *
     * @return array list of { line, function: 'report definition', text, plural: null, context: '' }
     */
    public static function reportLabelsIn( $source ) {

        $definition = json_decode( (string) $source, true );

        if ( ! is_array( $definition ) ) {

            return array();
        }

        $texts = array();

        foreach ( self::REPORT_KEYS as $key ) {

            $texts[] = $definition[ $key ] ?? null;
        }

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

            foreach ( self::REPORT_WIDGET_KEYS as $key ) {

                $texts[] = is_array( $widget ) ? ( $widget[ $key ] ?? null ) : null;
            }
        }

        $out = array();

        foreach ( $texts as $text ) {

            if ( ! is_string( $text ) || trim( $text ) === '' ) {

                continue;
            }

            $at    = strpos( (string) $source, json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $out[] = array(
                'line'     => $at === false ? 1 : substr_count( (string) $source, "\n", 0, $at ) + 1,
                'function' => 'report definition',
                'text'     => $text,
                'plural'   => null,
                'context'  => '',
            );
        }

        return $out;
    }

    /** The index of the bracket that closes the one at $open; the last token when none does. */
    private static function closing( array $tokens, $open ) {

        $depth = 0;
        $n     = count( $tokens );

        for ( $j = $open; $j < $n; $j++ ) {

            $t = $tokens[ $j ][0] === 'x' ? $tokens[ $j ][1] : null;

            if ( $t === '(' || $t === '[' || $t === '{' ) {

                $depth++;

            } elseif ( $t === ')' || $t === ']' || $t === '}' ) {

                if ( --$depth === 0 ) {

                    return $j;
                }
            }
        }

        return $n - 1;
    }

    /**
     * A PHP source as tokens for calls(): [ 's', value, line ] for a string
     * literal, [ 'x', text, line ] for anything else; no whitespace or comments.
     *
     * @return array
     */
    private static function phpTokens( $source ) {

        $tokens = array();

        foreach ( token_get_all( $source ) as $t ) {

            if ( ! is_array( $t ) ) {

                $tokens[] = array( 'x', $t, null );
                continue;
            }

            if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {

                continue;
            }

            if ( $t[0] === T_CONSTANT_ENCAPSED_STRING ) {

                $tokens[] = array( 's', self::unquote( $t[1] ), $t[2] );
                continue;
            }

            // "{$" and "${" in an interpolated string open what '}' closes.
            $open     = in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true );
            $tokens[] = array( 'x', $open ? '{' : $t[1], $t[2] );
        }

        return $tokens;
    }

    /**
     * The OWA.l() and OWA.ln() calls in one JavaScript file's source.
     *
     * @return array as callsIn()'s
     */
    public static function scriptCallsIn( $source ) {

        $calls = self::calls( self::scriptTokens( $source ), '+', function ( array $tokens, $i ) {

            return ( $tokens[ $i - 1 ][1] ?? null ) === '.' && ( $tokens[ $i - 2 ][1] ?? null ) === 'OWA'
                && $tokens[ $i - 2 ][0] === 'x';

        }, 'l', 'ln' );

        foreach ( $calls as &$call ) {

            $call['function'] = 'OWA.' . $call['function'];
        }

        unset( $call );

        return $calls;
    }

    /**
     * The calls of $single( and $counted( in a token list, each token
     * [ 's' (a string literal, its value) | 'x' (anything else, its text), text, line ].
     *
     * @param  array    $tokens
     * @param  string   $join     the operator that joins two literals
     * @param  callable $isCallee ( tokens, i ): is the name at i one of ours, by what precedes it?
     * @return array    list of { line, function, text|null, plural|null, context|null }
     */
    private static function calls( array $tokens, $join, callable $isCallee, $single, $counted ) {

        $calls = array();
        $n     = count( $tokens );
        $at    = function ( $j ) use ( $tokens ) {

            return isset( $tokens[ $j ] ) && $tokens[ $j ][0] === 'x' ? $tokens[ $j ][1] : null;
        };

        for ( $i = 2; $i < $n - 1; $i++ ) {

            $name = $tokens[ $i ][1];

            if ( $tokens[ $i ][0] !== 'x' || ( $name !== $single && $name !== $counted )
                || $at( $i + 1 ) !== '(' || ! $isCallee( $tokens, $i ) ) {

                continue;
            }

            list( $text, $j ) = self::literal( $tokens, $i + 2, $join );

            $plural = null;
            $ok     = $text !== null;

            if ( $ok && $name === $counted ) {

                $ok = $at( $j ) === ',';

                if ( $ok ) {

                    list( $plural, $j ) = self::literal( $tokens, $j + 1, $join );
                    $ok = $plural !== null && $at( $j ) === ',';
                }

                if ( $ok ) {

                    $j  = self::argumentEnd( $tokens, $j + 1 );
                    $ok = $j !== null;
                }
            }

            $context = '';

            if ( $ok && $at( $j ) === ',' ) {

                list( $context, $j ) = self::literal( $tokens, $j + 1, $join );
            }

            $ok = $ok && $context !== null && $at( $j ) === ')';

            $calls[] = array(
                'line'     => $tokens[ $i ][2],
                'function' => $name,
                'text'     => $ok ? $text : null,
                'plural'   => $ok ? $plural : null,
                'context'  => $ok ? $context : null,
            );
        }

        return $calls;
    }

    /**
     * A string literal, or literals joined with $join, starting at token $j.
     *
     * @return array [ string|null, index after it ]
     */
    private static function literal( array $tokens, $j, $join ) {

        $text = null;

        while ( isset( $tokens[ $j ] ) && $tokens[ $j ][0] === 's' ) {

            $text = ( $text ?? '' ) . $tokens[ $j ][1];
            $j++;

            if ( ! isset( $tokens[ $j ] ) || $tokens[ $j ][0] !== 'x' || $tokens[ $j ][1] !== $join ) {

                break;
            }

            $j++;
        }

        return array( $text, $j );
    }

    /**
     * The index of the , or ) that ends an argument starting at $j; null for
     * an empty argument or none.
     */
    private static function argumentEnd( array $tokens, $j ) {

        $depth = 0;
        $start = $j;
        $n     = count( $tokens );

        for ( ; $j < $n; $j++ ) {

            $t = $tokens[ $j ][0] === 'x' ? $tokens[ $j ][1] : null;

            if ( $t === '(' || $t === '[' || $t === '{' ) {

                $depth++;

            } elseif ( $t === ')' || $t === ']' || $t === '}' ) {

                if ( $depth === 0 ) {

                    return $j > $start ? $j : null;
                }

                $depth--;

            } elseif ( $t === ',' && $depth === 0 ) {

                return $j > $start ? $j : null;
            }
        }

        return null;
    }

    /** A PHP string literal's value. */
    private static function unquote( $literal ) {

        $body = substr( $literal, 1, -1 );

        if ( $literal[0] === "'" ) {

            return strtr( $body, array( "\\\\" => "\\", "\\'" => "'" ) );
        }

        return stripcslashes( $body );
    }

    /** Words after which a / starts a regular expression, not a division. */
    const SCRIPT_REGEX_AFTER = array( 'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await' );

    /**
     * A JavaScript source as tokens for calls(): string literals by value,
     * identifiers and numbers whole, every other character on its own.
     *
     * Comments and regular expressions are skipped. A template literal with
     * no substitution is a string literal; one with a substitution is not,
     * and the code in its substitutions is read like any other.
     *
     * @return array
     */
    public static function scriptTokens( $source ) {

        $source = (string) $source;
        $len    = strlen( $source );
        $tokens = array();
        $line   = 1;
        $i      = 0;

        // One entry per open "${": the brace depth inside that substitution.
        $templates = array();

        $push = function ( $kind, $text, $line ) use ( &$tokens ) {

            $tokens[] = array( $kind, $text, $line );
        };

        // Scans template text from $i (after ` or the } closing a substitution).
        $template = function ( $i, $chunk_line ) use ( $source, $len, &$line, &$templates, $push ) {

            $j = $i;

            while ( $j < $len && $source[ $j ] !== '`' ) {

                if ( $source[ $j ] === '\\' ) {

                    $j += 2;
                    continue;
                }

                if ( $source[ $j ] === '$' && ( $source[ $j + 1 ] ?? '' ) === '{' ) {

                    $line += substr_count( $source, "\n", $i, $j - $i );
                    $push( 'x', '`', $chunk_line );
                    $templates[] = 0;

                    return $j + 2;
                }

                $j++;
            }

            $line += substr_count( $source, "\n", $i, min( $j, $len ) - $i );

            return $j + 1;
        };

        while ( $i < $len ) {

            $c = $source[ $i ];

            if ( $c === "\n" ) {

                $line++;
                $i++;
                continue;
            }

            if ( ctype_space( $c ) ) {

                $i++;
                continue;
            }

            $next = $source[ $i + 1 ] ?? '';

            if ( $c === '/' && $next === '/' ) {

                $end = strpos( $source, "\n", $i );
                $i   = $end === false ? $len : $end;
                continue;
            }

            if ( $c === '/' && $next === '*' ) {

                $end   = strpos( $source, '*/', $i + 2 );
                $end   = $end === false ? $len : $end + 2;
                $line += substr_count( $source, "\n", $i, $end - $i );
                $i     = $end;
                continue;
            }

            if ( $c === "'" || $c === '"' ) {

                $j = $i + 1;

                while ( $j < $len && $source[ $j ] !== $c && $source[ $j ] !== "\n" ) {

                    $j += $source[ $j ] === '\\' ? 2 : 1;
                }

                $push( 's', self::scriptUnquote( substr( $source, $i + 1, $j - $i - 1 ) ), $line );
                $line += substr_count( $source, "\n", $i, min( $j, $len ) - $i );
                $i     = $j + 1;
                continue;
            }

            if ( $c === '`' ) {

                $start = $line;
                $depth = count( $templates );
                $after = $template( $i + 1, $start );

                if ( count( $templates ) === $depth ) {

                    $push( 's', self::scriptUnquote( substr( $source, $i + 1, $after - $i - 2 ) ), $start );
                }

                $i = $after;
                continue;
            }

            if ( $templates && ( $c === '{' || $c === '}' ) ) {

                $top = count( $templates ) - 1;

                if ( $c === '}' && $templates[ $top ] === 0 ) {

                    array_pop( $templates );
                    $i = $template( $i + 1, $line );
                    continue;
                }

                $templates[ $top ] += $c === '{' ? 1 : -1;
            }

            if ( $c === '/' && self::scriptRegexMayStart( end( $tokens ) ?: null ) ) {

                $j     = $i + 1;
                $class = false;

                while ( $j < $len && $source[ $j ] !== "\n" && ( $source[ $j ] !== '/' || $class ) ) {

                    if ( $source[ $j ] === '\\' ) {

                        $j++;
                    } elseif ( $source[ $j ] === '[' ) {

                        $class = true;
                    } elseif ( $source[ $j ] === ']' ) {

                        $class = false;
                    }

                    $j++;
                }

                $j++;

                while ( $j < $len && ctype_alpha( $source[ $j ] ) ) {

                    $j++;
                }

                $push( 'x', 'regex', $line );
                $i = $j;
                continue;
            }

            if ( ctype_alnum( $c ) || $c === '_' || $c === '$' || ord( $c ) > 127 ) {

                $j = $i;

                while ( $j < $len && ( ctype_alnum( $source[ $j ] ) || $source[ $j ] === '_' || $source[ $j ] === '$' || ord( $source[ $j ] ) > 127 ) ) {

                    $j++;
                }

                $push( 'x', substr( $source, $i, $j - $i ), $line );
                $i = $j;
                continue;
            }

            $push( 'x', $c, $line );
            $i++;
        }

        return $tokens;
    }

    /** Does a / after this token start a regular expression? */
    private static function scriptRegexMayStart( $previous ) {

        if ( $previous === null ) {

            return true;
        }

        if ( $previous[0] === 's' || $previous[1] === ')' || $previous[1] === ']' || $previous[1] === 'regex' ) {

            return false;
        }

        if ( preg_match( '/^[A-Za-z0-9_$\x80-\xff]/', $previous[1] ) ) {

            return in_array( $previous[1], self::SCRIPT_REGEX_AFTER, true );
        }

        return true;
    }

    /** A JavaScript string literal's body, unescaped. */
    private static function scriptUnquote( $body ) {

        return preg_replace_callback( '/\\\\(u\{[0-9A-Fa-f]+\}|u[0-9A-Fa-f]{4}|x[0-9A-Fa-f]{2}|\r\n|[\s\S])/', function ( $m ) {

            $e = $m[1];

            if ( $e[0] === 'u' || $e[0] === 'x' ) {

                return mb_chr( hexdec( trim( substr( $e, 1 ), '{}' ) ), 'UTF-8' );
            }

            $simple = array( 'n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\f", 'v' => "\v", '0' => "\0", "\n" => '', "\r\n" => '', "\r" => '' );

            return $simple[ $e ] ?? $e;

        }, $body );
    }

    /** @return string[] the .php files under $roots, the .js files under a module's src/ and its report definitions */
    private static function sourceFiles( array $roots ) {

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

                if ( preg_match( '#/(vendor|node_modules|tests|owa-data)/#', $path ) ) {

                    continue;
                }

                if ( substr( $path, -4 ) === '.php' || ( substr( $path, -3 ) === '.js' && preg_match( '#/modules/[^/]+/src/#', $path ) )
                     || ( substr( $path, -5 ) === '.json' && preg_match( '#/modules/[^/]+/reports/[^/]+\.json$#', $path ) ) ) {

                    $files[] = $path;
                }
            }
        }

        sort( $files );

        return $files;
    }

    /** Where the code that calls t() and OWA.l() lives. */
    public static function sourceRoots() {

        return array( \OWA\Core\Env::root() . '/Core', \OWA\Core\Env::root() . '/modules', \OWA\Core\Env::confDir() . 'messages.php' );
    }

    /**
     * The English catalogue's file contents, for a map from extract().
     *
     * @param  array $map  key => English, or key => [ singular, plural ]
     * @return string
     */
    public static function catalogueSource( array $map ) {

        $lines = array(
            '<?php',
            '//',
            '// GENERATED by `php cli.php cmd=strings-extract` -- do not edit by hand.',
            '//',
            '// Every user-facing string OWA passes to CoreAPI::t() and CoreAPI::tn(), and',
            '// the reporting JavaScript to OWA.l() and OWA.ln(), keyed by its English (a',
            '// context, where one is given, prefixes the key with "\\x04"). A counted',
            '// message is keyed by its singular and holds its forms. A translation is',
            '// conf/strings/<locale>.php: the same keys, translated values -- for a counted',
            '// message, as many forms as the locale\'s plural rule has (Strings::pluralIndex()).',
            '//',
            'return array(',
        );

        foreach ( $map as $key => $english ) {

            $value = is_array( $english )
                ? 'array( ' . implode( ', ', array_map( function ( $form ) { return self::export( $form ); }, $english ) ) . ' )'
                : self::export( $english );

            $lines[] = '    ' . self::export( $key ) . ' => ' . $value . ',';
        }

        $lines[] = ');';

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * conf/strings/script-keys.php's contents: the keys from extract()'s $script_keys.
     *
     * @param  string[] $keys
     * @return string
     */
    public static function scriptKeysSource( array $keys ) {

        $lines = array(
            '<?php',
            '//',
            '// GENERATED by `php cli.php cmd=strings-extract` -- do not edit by hand.',
            '//',
            '// The keys of conf/strings/en.php that the reporting JavaScript uses (OWA.l(),',
            '// OWA.ln()). A page in a translated locale is sent the translations of these.',
            '//',
            'return array(',
        );

        foreach ( $keys as $key ) {

            $lines[] = '    ' . self::export( $key ) . ',';
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
