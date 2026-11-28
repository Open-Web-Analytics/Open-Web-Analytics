<?php
namespace OWA\Module\Base\Classes;

/**
 * Result Set Manager
 *
 * Responsible for creating a data result set from various metrics and dimensions
 * 
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-import-type Bindings from \OWA\Core\Db
 * @phpstan-import-type DimensionEntry from \OWA\Module\Base\Classes\DimensionRegistry
 * @phpstan-import-type ResultRow from \OWA\Module\Base\Classes\PaginatedResultSet
 * @phpstan-import-type RelatedDimensions from \OWA\Module\Base\Classes\PaginatedResultSet
 * @phpstan-import-type RelatedMetrics from \OWA\Module\Base\Classes\PaginatedResultSet
 * @phpstan-type Constraint array{name: string, value: mixed, operator: string, key?: string}
 * @phpstan-type Segment array{metrics: array<string, Constraint>, dimensions: array<string, Constraint>}
 * @phpstan-type Sort array{0: string, 1: string}
 */

class ResultSetManager extends \OWA\Core\Base {

    /**
     * The page size of a query by anything but date or dateHour that names
     * none: what a Table widget and a REST call without resultsPerPage get,
     * whatever the period.
     */
    const DEFAULT_RESULTS_PER_PAGE = 25;

    /**
     * The constraint a time period bounds the clock with (setTimePeriod()):
     * unix seconds, start and end. Not a dimension; applyConstraint() reads
     * it against the cube's `ts`.
     */
    const TIME_BOUND = 'timestamp';

    /**
     * The params of the caller, either a report or graph: constraints
     * (name => Constraint), orderby (Sorts), offset, period (a TimePeriod)
     *
     * @var array<string, mixed>
     */
    public $params = [];

    /**
     * The lables for calculated measures
     *
     * @var array<string, string|null>
     */
    public $labels = [];

    /**
     * Data Access Object
     *
     * @var \OWA\Core\Db
     */
    public $db;

    /**
     * The dimensions to groupby
     *
     * @var list<string>
     */
    public $dimensions = [];

    /** @var int|string|null */
    public $page = 1;
    /** @var int|string|null rows per page */
    public $limit;
    /**
     * The constraint operators, in the order a clause is tested against them,
     * and what each means. The first found in a clause is the one it means, so
     * a value may contain any operator that comes later.
     */
    const CONSTRAINT_OPERATORS = [
        '==' => 'is',
        '!=' => 'is not',
        '>=' => 'is at least',
        '<=' => 'is at most',
        '>'  => 'is greater than',
        '<'  => 'is less than',
        '=~' => 'matches the regular expression',
        '!~' => 'does not match the regular expression',
        '=@' => 'contains',
        '!@' => 'does not contain',
    ];

    /** @var list<string> The keys of CONSTRAINT_OPERATORS, set by the constructor. */
    public $constraint_operators = [];
    /** @var \OWA\Module\Base\Classes\PaginatedResultSet */
    public $resultSet;
    /** @var list<string> the metrics asked for */
    public $metrics = [];
    /** @var list<string> the children of calculated metrics added to the select, removed from the result */
    public $childMetrics = [];
    /** @var array<string, \OWA\Core\Metric> name => the calculated metric */
    public $calculatedMetrics = [];
    /** @var array<string, mixed> the request as the result set's URLs carry it */
    public $query_params = [];
    /** @var \OWA\Core\Entity|null the cube entity, set by loadBaseEntity() */
    public $baseEntity;
    /** @var array<string, \OWA\Core\Metric> metric name => the metric object, set by loadMetric() */
    public $metricObjects = [];
    /** @var list<string> */
    public $errors = [];

    /**
     * Errors that mean the REQUEST is malformed, as opposed to noise.
     *
     * Kept apart from $errors because entries there can be routine: an
     * unknown sort column, a calculated metric as a constraint.
     *
     * So "any error" cannot gate the query; only these can.
     *
     * @var list<string>
     */
    public $request_errors = [];
    /** @var array<string, callable(mixed): mixed> data type => formatter */
    public $formatters = [];
    /** @var Segment|null */
    public $segment;
    /** @var string day, month or year */
    public $resolution = 'day';
    /** @var \OWA\Module\Base\Classes\TimePeriod|null */
    public $timePeriod;
    /** @var array<string, mixed> */
    public $all_columns = [];
    

    /**
     * @param \OWA\Core\Db|'' $db the data access object; '' for the singleton
     */
    public function __construct($db = '') {

        $this->constraint_operators = array_keys( self::CONSTRAINT_OPERATORS );

        if ($db) {
            $this->db = $db;
        } else {
            $this->db = \OWA\Core\CoreAPI::dbSingleton();
        }

        $this->formatters = [
            //'yyyymmdd' => array($this, 'dateFormatter'),
            'timestamp'        => [$this, 'formatSeconds'],
            'percentage'     => [$this, 'formatPercentage'],
            'integer'         => [$this, 'numberFormatter'],
            'boolean'         => [$this, 'booleanFormatter'],
            'milliseconds'    => [$this, 'formatMilliseconds'],
            'datetime'        => [$this, 'formatDateTime'],
            'currency'        => [$this, 'formatCurrency']
        ];
        
        $this->resultSet = new \OWA\Module\Base\Classes\PaginatedResultSet();

        parent::__construct();
    }


    /**
     * @param  string $name
     * @param  mixed  $value
     * @param  string $operator
     * @return void
     */
    public function setConstraint($name, $value, $operator = '') {

        if (empty($operator)) {
            $operator = '=';
        }

        if ( ! \OWA\Core\Lib::isEmpty( $value ) ) {
            $this->params['constraints'][$name] = ['operator' => $operator, 'value' => $value, 'name' => $name];
        }
    }

    /**
     * Apply a set of constraints a CALLER asked for.
     *
     * Unlike setConstraint(), an empty value here is an error rather than a
     * no-op. setConstraint() drops empty values silently, which is right for the
     * internal calls that pass an optional value -- but wrong for constraints
     * that arrived on a request, because there "no value" means the value went
     * missing, not that the caller wanted everything.
     *
     * Silently dropping them made a lost parameter indistinguishable from an
     * unfiltered request: "source==" produced the full unfiltered total, and the
     * Source Detail report showed the same visit count for every source with no
     * error anywhere. Reported the same way an unknown sort column is.
     *
     * @param  list<array{name: string, value: mixed, operator: string}>|null $array as parseConstraintsString() makes them
     * @return void
     */
    public function setConstraints($array) {

        if (is_array($array)) {

            if ( ! isset($this->params['constraints']) ) {
                $this->params['constraints'] = [];
            }

            // How many times each name has come up in this list.
            $seen = [];

            foreach ($array as $constraint) {

                if ( \OWA\Core\Lib::isEmpty( $constraint['value'] ) ) {

                    $this->addRequestError( sprintf(
                        \OWA\Core\CoreAPI::t( 'The "%s" constraint was given no value. Refusing to run the '
                        . 'query unconstrained -- a missing value is not a request for '
                        . 'everything.' ),
                        $constraint['name']
                    ) );

                    continue;
                }

                /*
                 * A name that resolves to nothing is refused for the same
                 * reason, and reported here rather than where the constraint is
                 * applied: applyConstraint() runs once for the result set and
                 * again for the aggregates, so the same misspelling would be
                 * reported twice.
                 *
                 * Ignored, the query would run WITHOUT that filter and answer
                 * with the unconstrained total -- the failure the check above
                 * exists to stop, from a misspelled name rather than a lost
                 * value.
                 */
                if ( ! $this->isDimension( $constraint['name'] )
                     && ! $this->isMetric( $constraint['name'] ) ) {

                    $this->addRequestError( sprintf(
                        \OWA\Core\CoreAPI::t( '"%s" is not a dimension or a metric, so it cannot be constrained '
                        . 'on. Refusing to run the query unconstrained -- an unknown name '
                        . 'is not a request for everything.' ),
                        $constraint['name']
                    ) );

                    continue;
                }

                /*
                 * A metric is tested on its aggregate (HAVING). "(not set)" is
                 * an empty test, which only a dimension has, and a calculated
                 * metric has no aggregate of its own. Refused here: at query
                 * time the query would run without the filter.
                 */
                if ( $this->isMetric( $constraint['name'] ) ) {

                    if ( self::emptyTestFor( $constraint ) ) {

                        $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t( '%s is a metric; only a dimension can be %s.' ),
                            $constraint['name'], self::NOT_SET_LABEL ) );

                        continue;
                    }

                    if ( $this->isCalculatedMetric( $constraint['name'] ) ) {

                        $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t(
                            '"%s" is calculated from other metrics, so it cannot be constrained on.' ), $constraint['name'] ) );

                        continue;
                    }
                }

                // A Yes/No dimension takes Yes or No, with is or is not (booleanConstraint()).
                $entry = $this->isDimension( $constraint['name'] ) ? ( $this->dimensionRegistry()->all()[ $constraint['name'] ] ?? null ) : null;

                if ( ( $entry['data_type'] ?? null ) === 'boolean' && ! self::emptyTestFor( $constraint ) ) {

                    $flag = self::booleanConstraint( $constraint['operator'] ?? '', $constraint['value'] );

                    if ( ! is_array( $flag ) ) {

                        $this->addRequestError( sprintf( $flag, $entry['label'] ?? $constraint['name'] ) );

                        continue;
                    }
                }

                if ( in_array( $constraint['operator'] ?? '', [ '=~', '!~' ], true ) ) {

                    $problem = self::regexProblem( (string) $constraint['value'] );

                    if ( $problem !== null ) {

                        $this->addRequestError( sprintf(
                            \OWA\Core\CoreAPI::t( 'The "%1$s" pattern was refused: %2$s' ), $constraint['name'], $problem ) );

                        continue;
                    }
                }

                /*
                 * A name given twice in one request is two filters, and both
                 * apply: pagePath=@/blog,pagePath!@/blog/tag is the blog without
                 * its tag pages. Constraints are kept by name, so the second
                 * replaced the first and the numbers came out wider than asked,
                 * with no error -- from filter pills, a saved report, or a
                 * widget's filter on a dimension its report also filters.
                 *
                 * The first keeps the name as its key, so getConstraint() still
                 * finds it, and replaces one set earlier, as it always has.
                 */
                $name = $constraint['name'];
                $seen[ $name ] = isset( $seen[ $name ] ) ? $seen[ $name ] + 1 : 0;

                if ( $seen[ $name ] === 0 ) {

                    $this->setConstraint( $name, $constraint['value'], $constraint['operator'] );

                } else {

                    $key = $name . '#' . $seen[ $name ];

                    // 'key' keeps it apart in the query too (Db::where()).
                    $this->params['constraints'][ $key ] = [
                        'operator' => $constraint['operator'] ?: '=',
                        'value'    => $constraint['value'],
                        'name'     => $name,
                        'key'      => $key,
                    ];
                }
            }
        }
    }

    /**
     * Whether a group that contains a repeat is itself repeated, at any depth:
     * (a+)+, (a*)*, ((ab)+)+. Escapes and character classes are skipped, since
     * a + inside [...] or after \\ is a literal.
     *
     * @param string $pattern
     * @return bool
     */
    private static function repeatsARepeat( $pattern ) {

        $stack = [ false ];   // per open group: does it contain a repeat
        $len   = strlen( $pattern );

        for ( $i = 0; $i < $len; $i++ ) {

            $c = $pattern[ $i ];

            if ( $c === '\\' ) {

                $i++;

                continue;
            }

            if ( $c === '[' ) {

                // To the closing ], allowing a ] first in the class and escapes inside it.
                $i += ( isset( $pattern[ $i + 1 ] ) && $pattern[ $i + 1 ] === '^' ) ? 2 : 1;
                $i += ( isset( $pattern[ $i ] ) && $pattern[ $i ] === ']' ) ? 1 : 0;

                while ( $i < $len && $pattern[ $i ] !== ']' ) {

                    $i += $pattern[ $i ] === '\\' ? 2 : 1;
                }

                continue;
            }

            $repeat = $c === '+' || $c === '*' || $c === '{';

            if ( $c === '(' ) {

                $stack[] = false;

            } elseif ( $c === ')' && count( $stack ) > 1 ) {

                $inner    = array_pop( $stack );
                $next     = $pattern[ $i + 1 ] ?? '';
                $repeated = $next === '+' || $next === '*' || $next === '{';

                if ( $inner && $repeated ) {

                    return true;
                }

                if ( $inner || $repeated ) {

                    $stack[ count( $stack ) - 1 ] = true;
                }

            } elseif ( $repeat ) {

                $stack[ count( $stack ) - 1 ] = true;
            }
        }

        return false;
    }

    /**
     * Inline flags both engines read: (?i), (?m), (?s), (?x), and turning them off.
     */
    const PORTABLE_REGEX_FLAGS = 'imsx-';

    /**
     * Why a pattern that PCRE accepts would not run the same on every
     * supported server, or null.
     *
     * The pattern is validated with PCRE, which is what MariaDB runs REGEXP
     * with; MySQL 8 runs it with ICU. Refused: the constructs ICU rejects or
     * reads differently -- the old [[:<:]] and [[:>:]] word boundaries,
     * branch reset (?|, \K, recursion and subroutine calls, conditionals,
     * backtracking verbs (*...), callouts, Python-style (?P...) and
     * quote-named (?'n') groups, \g and \k references, \C, \N and \o,
     * inline flags other than i, m, s and x, and a [ or && inside a
     * character class, which ICU reads as a nested set or an intersection.
     * Possessive quantifiers, atomic groups, lookaround, named groups
     * (?<n>...), \Q...\E and POSIX classes such as [[:digit:]] run on both.
     *
     * @param string $pattern
     * @return string|null
     */
    private static function unportableConstruct( $pattern ) {

        $len      = strlen( $pattern );
        $mysql    = ', which MySQL\'s regular expressions do not support.';
        $in_class = false;

        for ( $i = 0; $i < $len; $i++ ) {

            $c = $pattern[ $i ];

            if ( $c === '\\' ) {

                $next = $pattern[ $i + 1 ] ?? '';

                if ( $next === 'Q' && ! $in_class ) {

                    // Literal to \E, or to the end.
                    $end = strpos( $pattern, '\\E', $i + 2 );
                    $i   = $end === false ? $len : $end + 1;

                    continue;
                }

                if ( $next === 'g' || $next === 'k' ) {

                    return \OWA\Core\CoreAPI::t( 'it uses a backreference.' );
                }

                if ( in_array( $next, [ 'K', 'C', 'N', 'o' ], true ) ) {

                    return 'it uses \\' . $next . $mysql;
                }

                $i++;

                continue;
            }

            if ( $in_class ) {

                if ( $c === '[' && ( $pattern[ $i + 1 ] ?? '' ) === ':' ) {

                    $end  = strpos( $pattern, ':]', $i + 2 );
                    $name = $end === false ? '' : substr( $pattern, $i + 2, $end - $i - 2 );

                    if ( $name === '<' || $name === '>' ) {

                        return 'it uses [[:<:]] or [[:>:]]' . $mysql . ' Use \\b.';
                    }

                    if ( $end !== false ) {

                        $i = $end + 1;

                        continue;
                    }
                }

                if ( $c === '[' || ( $c === '&' && ( $pattern[ $i + 1 ] ?? '' ) === '&' ) ) {

                    return 'it puts an unescaped [ or && inside a character class, which MySQL reads as a '
                         . 'nested set or an intersection. Escape it as \\[ or \\&.';
                }

                // A ] first in the class was stepped over on the way in.
                if ( $c === ']' ) {

                    $in_class = false;
                }

                continue;
            }

            if ( $c === '[' ) {

                $in_class = true;

                // A ] straight after [ or [^ is a literal member, not the end.
                $i += ( $pattern[ $i + 1 ] ?? '' ) === '^' ? 1 : 0;
                $i += ( $pattern[ $i + 1 ] ?? '' ) === ']' ? 1 : 0;

                continue;
            }

            if ( $c !== '(' ) {

                continue;
            }

            $after = substr( $pattern, $i + 1, 4 );

            if ( $after !== '' && $after[0] === '*' ) {

                return 'it uses a (*...) verb' . $mysql;
            }

            if ( $after === '' || $after[0] !== '?' ) {

                continue;
            }

            $kind = $after[1] ?? '';

            if ( $kind === '|' ) {

                return 'it uses a branch reset group (?|...)' . $mysql;
            }

            if ( $kind === 'R' || $kind === '&' || ctype_digit( $kind )
                 || ( ( $kind === '+' || $kind === '-' ) && ctype_digit( $after[2] ?? '' ) )
                 || substr( $after, 1, 2 ) === 'P>' ) {

                return 'it calls a group or the whole pattern recursively' . $mysql;
            }

            if ( substr( $after, 1, 2 ) === 'P=' ) {

                return \OWA\Core\CoreAPI::t( 'it uses a backreference.' );
            }

            if ( $kind === '(' ) {

                return 'it uses a conditional group (?(...)...)' . $mysql;
            }

            if ( $kind === 'P' || $kind === "'" || $kind === 'C' ) {

                return sprintf( 'it uses (?%s%s', $kind, $mysql );
            }

            if ( in_array( $kind, [ ':', '=', '!', '<', '>', '#' ], true ) ) {

                continue;
            }

            // Inline flags: (?flags) or (?flags:...).
            if ( preg_match( '/\G\(\?\^?([A-Za-z-]*)[:)]/', $pattern, $m, 0, $i ) ) {

                $flags = $m[1];

                if ( strspn( $flags, self::PORTABLE_REGEX_FLAGS ) !== strlen( $flags )
                     || strpos( $m[0], '^' ) !== false ) {

                    return 'it uses inline flags other than i, m, s and x' . $mysql;
                }
            }
        }

        return null;
    }

    /** The longest a report's query may run, in seconds. */
    const QUERY_SECONDS = 60;

    /** The longest pattern a =~ or !~ filter may send. */
    const MAX_REGEX_LENGTH = 256;

    /** The largest repeat bound, as in {n} or {n,m}, a pattern may use. */
    const MAX_REGEX_BOUND = 100;

    /**
     * Why a =~ / !~ pattern is refused, or null when it may run.
     *
     * The pattern runs in the database, against every row of the period, for
     * anyone who can view the report. A nested quantifier such as (a+)+ or a
     * backreference makes the match backtrack exponentially on a row that
     * almost matches, and MariaDB puts no time limit on a REGEXP. These are
     * the constructs that do that; the patterns reports use (anchors,
     * alternation, classes, a bounded repeat) are untouched.
     *
     * So is syntax only one of the two engines runs: see unportableConstruct().
     *
     * @param string $pattern
     * @return string|null
     */
    public static function regexProblem( $pattern ) {

        if ( strlen( $pattern ) > self::MAX_REGEX_LENGTH ) {

            return sprintf( \OWA\Core\CoreAPI::t( 'it is longer than %d characters.' ), self::MAX_REGEX_LENGTH );
        }

        // Before compiling: PCRE's own answer to some of these (\C, for one)
        // depends on the PCRE version, and the reason given should not.
        $unportable = self::unportableConstruct( $pattern );

        if ( $unportable !== null ) {

            return $unportable;
        }

        // \x01 as the delimiter, so a / in a path pattern needs no escaping.
        if ( @preg_match( "\x01" . $pattern . "\x01u", '' ) === false ) {

            return \OWA\Core\CoreAPI::t( 'it is not a valid regular expression.' );
        }

        if ( preg_match( '/\\\\[1-9]/', $pattern ) ) {

            return \OWA\Core\CoreAPI::t( 'it uses a backreference.' );
        }

        if ( self::repeatsARepeat( $pattern ) ) {

            return \OWA\Core\CoreAPI::t( 'it repeats a group that itself repeats, such as (a+)+.' );
        }

        if ( preg_match_all( '/\{\s*(\d+)\s*(?:,\s*(\d*)\s*)?\}/', $pattern, $m, PREG_SET_ORDER ) ) {

            foreach ( $m as $bound ) {

                if ( (int) $bound[1] > self::MAX_REGEX_BOUND
                     || ( isset( $bound[2] ) && $bound[2] !== '' && (int) $bound[2] > self::MAX_REGEX_BOUND ) ) {

                    return sprintf( \OWA\Core\CoreAPI::t( 'it repeats more than %d times.' ), self::MAX_REGEX_BOUND );
                }
            }
        }

        return null;
    }

    /**
     * Narrow the query to one Profile of its Property.
     *
     * A constraint, as any dimension filter is. Without a propertyId the
     * Profile also names the Property whose cube is read, and whose dimensions
     * the query may name (dimensionRegistry()).
     *
     * @param  string $siteId
     * @return void
     */
    public function setSiteId($siteId) {

        //used for urls
        $this->query_params['siteId'] = $siteId;
        $this->setConstraint('siteId', $siteId);
    }

    /**
     * The Property this query is of: its cube holds every Profile in it, and
     * its custom dimensions are dimensions of this query.
     *
     * @param string $propertyId
     * @return void
     */
    public function setPropertyId( $propertyId ) {

        $this->query_params['propertyId'] = (string) $propertyId;
    }

    /** @var string the Property whose dimensions this query may name, if set apart; see setVocabularyProperty() */
    private $vocabulary_property_id = '';

    /**
     * Whose dimensions the names in this query are, before it is told which
     * Property and Profile it reads.
     *
     * For a caller that validates names (setConstraints(), setSegment()) before
     * it sets the Property and the siteId filter, as ReportsRest does. The
     * siteId filter is applied last on purpose: setSiteId() then replaces any
     * siteId constraint the request itself carried. Setting the siteId first
     * to get its Property's vocabulary would let that constraint win instead.
     * So the Property is named here for the vocabulary alone, and filters
     * nothing.
     *
     * @param string $propertyId
     * @return void
     */
    public function setVocabularyProperty( $propertyId ) {

        $this->vocabulary_property_id = (string) $propertyId;
    }

    /**
     * The dimensions this query may name: the modules' and its Property's
     * custom ones (Classes\DimensionRegistry).
     *
     * Of the Property setVocabularyProperty() named, else the one the query
     * reads (propertyIdForRequest()). With neither, the modules' alone.
     *
     * @return \OWA\Module\Base\Classes\DimensionRegistry
     */
    public function dimensionRegistry() {

        return \OWA\Module\Base\Classes\DimensionRegistry::forProperty(
            $this->vocabulary_property_id !== ''
                ? $this->vocabulary_property_id
                : $this->propertyIdForRequest() );
    }

    /**
     * The Profile this query is narrowed to: the siteId constraint, which
     * setSiteId() sets, else $params['siteId'].
     *
     * @return mixed|void nothing when neither is set
     */
    public function getSiteId() {

        $constraint = $this->getConstraint( 'siteId' );

        if ( is_array( $constraint ) && isset( $constraint['value'] ) ) {

            return $constraint['value'];
        }

        if ( isset( $this->params['siteId'] ) ) {

            return $this->params['siteId'];
        }
    }

    /**
     * @param  string|null $string
     * @return list<array{name: string, value: string, operator: string}>|void nothing for an empty string
     */
    public function constraintsStringToArray($string) {

        if ($string) {
            // add string to query params array for use in URLs.
            $this->query_params['constraints'] = $string;

            return $this->parseConstraintsString($string);
        }
    }
    
    /**
     * Transforms a comma separated string of constraints into array
     * The string format is used in REST API calls.
     *
     * @param  string|null $string
     * @return list<array{name: string, value: string, operator: string}>
     */
    public function parseConstraintsString($string) {

        $constraints = explode(',', (string) $string);
        $constraint_array = [];

        foreach($constraints as $constraint) {

            // Nothing between two commas, or after the last, says nothing.
            if ( trim( $constraint ) === '' ) {

                continue;
            }

            $parsed = false;

            foreach ($this->constraint_operators as $operator) {

                if (strpos($constraint, $operator)) {

                    // At the first operator: the value may hold one too.
                    list ($name, $value) = explode($operator, $constraint, 2);
                    // As written, less the escapes constraintValue() adds: stored
                    // values are the text that was sent, so a filter on
                    // "Tom &amp; Jerry" means those characters.
                    $constraint_array[] = ['name' => $name, 'value' => self::readConstraintValue( $value ), 'operator' => $operator];
                    $parsed = true;
                    break;
                }
            }

            // Refused, not dropped: dropped, the query ran without it.
            if ( ! $parsed ) {

                $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t(
                    '"%s" is not a constraint: it needs a name, an operator and a value.' ), $constraint ) );
            }
        }

        return $constraint_array;
    }

    /**
     * A value as a constraints string holds it: each % as %25 and each comma
     * as %2C, so a comma in a value does not end its clause. A link carries
     * the string urlencoded as well: PHP's decoding of the query string
     * removes that, and parseConstraintsString() these.
     *
     * @param  string $value
     * @return string
     */
    public static function constraintValue( $value ) {

        return str_replace( [ '%', ',' ], [ '%25', '%2C' ], (string) $value );
    }

    /**
     * A constraint's value with constraintValue()'s escapes read back. Any
     * other % is the value's own.
     *
     * @param  string $value
     * @return string
     */
    public static function readConstraintValue( $value ) {

        return (string) preg_replace_callback( '/%(25|2C)/i', static function ( $m ) {

            return $m[1] === '25' ? '%' : ',';
        }, (string) $value );
    }

    /**
     * @return array<string, Constraint> key => constraint
     */
    public function getConstraints() {

        return $this->params['constraints'];
    }

    /**
     * @param  string $key
     * @return Constraint|void
     */
    public function getConstraint( $key ) {

        if ( isset( $this->params['constraints'][$key] ) ) {
            return $this->params['constraints'][$key];
        }
    }

    /**
     * An entity, bound to the Property this request names when it needs to be.
     *
     * THE CUBE HAS NO ONE TABLE. There is one per Property (Cube\Cubes), so
     * `Entity\Event` is a shape rather than a table and its getTableName()
     * throws until something says which Property. This is the place that knows:
     * a query names its Property, or a siteId whose Property it is.
     *
     * Every entity in a query goes through here, so the binding cannot be
     * forgotten at one of the seven places an entity is built. An entity that
     * is not the cube is returned exactly as entityFactory() made it.
     *
     * An unresolvable Property leaves the entity UNBOUND on purpose: the next
     * getTableName() throws, which is a loud failure rather than a query
     * against the wrong Property's cube.
     *
     * @param  string $entity_name
     * @return \OWA\Core\Entity
     */
    protected function entityFor( $entity_name ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( $entity_name );

        if ( ! method_exists( $entity, 'bindToProperty' ) ) {

            return $entity;
        }

        $property_id = $this->propertyIdForRequest();

        if ( $property_id !== '' ) {

            $entity->bindToProperty( $property_id );
        }

        return $entity;
    }

    /**
     * The Property this query is of: the one set, or else the siteId's.
     *
     * @return string the Property id, or ''
     */
    protected function propertyIdForRequest() {

        if ( ! empty( $this->query_params['propertyId'] ) ) {

            return (string) $this->query_params['propertyId'];
        }

        return \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $this->siteIdForRequest() );
    }

    /**
     * The Profile this query is narrowed to, if any (getSiteId()).
     *
     * @return string
     */
    protected function siteIdForRequest() {

        return (string) $this->getSiteId();
    }

    /**
     * @param  array<string, Constraint>|'' $constraints '' for the query's own
     * @param  \OWA\Core\Db|''              $db          '' for this manager's
     * @param  \OWA\Core\Entity|''          $entity      '' for the base entity
     * @return void
     */
    public function applyConstraints( $constraints = '', $db = '', $entity = '') {

        if ( !$db ) {
            $db = $this->db;
        }

        if ( ! $constraints ) {
            $constraints = $this->getConstraints();
        }
        foreach ($constraints as $k => $constraint) {

            $this->applyConstraint($constraint, $db, $entity);
        }

        $this->applySharedMetricCondition( $db );
    }

    /**
     * The metrics' shared condition (sharedMetricCondition()) as a WHERE test
     * on the query this manager runs.
     *
     * @param \OWA\Core\Db $db
     * @return void
     */
    public function applySharedMetricCondition( $db ) {

        /*
         * The metrics' own condition, when they all share one, in the WHERE
         * clause too. Page Views by page path counts page_view rows; tested
         * only inside the count, every path with any event came back, the
         * ones nobody viewed at 0. In the WHERE clause they do not, and every
         * count is what it was. Not a constraint: nobody asked for it, so a
         * report does not say it (internal, unlike the request's own).
         */
        $shared = $db === $this->db && ! $this->segment ? $this->sharedMetricCondition() : null;

        if ( $shared ) {

            $db->where( $shared[0], $shared[1], $shared[2], 'owa_metric_condition' );
        }
    }

    /**
     * The condition every metric of the query counts by, as a WHERE test --
     * [qualified column, value, operator] -- or null when any metric counts
     * every row or two count by different conditions. A ratio is its two
     * metrics: pages per session is page views over sessions, and sessions
     * have no condition.
     *
     * @return array{0: string, 1: mixed, 2: string}|null
     */
    public function sharedMetricCondition() {

        if ( ! $this->baseEntity || empty( $this->metrics ) ) {

            return null;
        }

        $objects = $this->metricObjects;
        $names   = [];

        /*
         * Every metric the statement reads: the ones asked for, and any a
         * filter (HAVING) or a sort names. Page views by page path filtered
         * on eventCount>1 counts every event of a path, and keeping only
         * page_view rows would change that count.
         */
        $read = (array) $this->metrics;

        foreach ( (array) $this->getConstraints() as $constraint ) {

            if ( isset( $constraint['name'] ) && $this->isMetric( $constraint['name'] ) ) {

                $read[] = $constraint['name'];
            }
        }

        foreach ( (array) ( $this->params['orderby'] ?? [] ) as $sort ) {

            if ( isset( $sort[0] ) && $this->isMetric( $sort[0] ) ) {

                $read[] = $sort[0];
            }
        }

        foreach ( array_unique( $read ) as $name ) {

            if ( array_key_exists( $name, $this->calculatedMetrics ) ) {

                $names = array_merge( $names, (array) $this->calculatedMetrics[ $name ]->getChildMetrics() );

            } else {

                $names[] = $name;
            }
        }

        $shared = null;

        foreach ( array_unique( $names ) as $name ) {

            $where = isset( $objects[ $name ] ) && method_exists( $objects[ $name ], 'conditionWhere' )
                ? $objects[ $name ]->conditionWhere() : null;

            if ( ! $where || ( $shared !== null && $where !== $shared ) ) {

                return null;
            }

            $shared = $where;
        }

        return $shared;
    }
    
    /**
     * Generate constraint clause using metrics and dimensions
     *
     * @param  Constraint             $constraint
     * @param  \OWA\Core\Db|''        $db     the query it is applied to
     * @param  \OWA\Core\Entity|''    $entity '' for the base entity
     * @return void
     */
    public function applyConstraint( $constraint, $db = '', $entity= '') {

        if ( ! $entity ) {
            $entity = $this->baseEntity;
        }

        // A constraint with NO VALUE is refused, not quietly ignored.
        //
        // Db::where() skips a constraint whose value isEmpty(), so an empty one would
        // mean "no filter": the query would run unconstrained and return the full total
        // with no error anywhere, and a lost parameter would be indistinguishable from
        // a request for everything -- "source==" reaches here whenever owa_source goes
        // missing from the request.
        //
        // Refused the same way an unknown sort column is, so the caller sees an error
        // instead of plausible wrong numbers.
        if ( \OWA\Core\Lib::isEmpty( $constraint['value'] ) ) {

            $this->addError( sprintf(
                \OWA\Core\CoreAPI::t( 'The %s constraint has no value.' ), $constraint['name'] ) );

            return;
        }

        /*
         * The period's clock bound, in seconds, on the cube's `ts`: the edge
         * stamp in microseconds. The end second is counted whole.
         */
        if ( $constraint['name'] === self::TIME_BOUND && is_array( $constraint['value'] ) ) {

            $db->where( $entity->getTableAlias() . '.ts', [
                'start' => (int) $constraint['value']['start'] * 1000000,
                'end'   => (int) $constraint['value']['end'] * 1000000 + 999999,
            ], 'BETWEEN', $constraint['key'] ?? null );

            return;
        }

        /*
         * `==(not set)` AND `!=(not set)` ARE THE EMPTY TEST, not a comparison.
         *
         * "(not set)" is the label formatDimensionValue() gives NULL and '';
         * it is never stored. So these two select on emptiness -- both states --
         * and `!=` loses the null tolerance a negation otherwise gets, which
         * would keep exactly the rows it is asked to drop.
         */
        $unary = self::emptyTestFor( $constraint );

        if ( $unary && $this->isMetric( $constraint['name'] ) ) {

            $this->addError( sprintf( \OWA\Core\CoreAPI::t( '%s is a metric; only a dimension can be %s.' ),
                $constraint['name'], self::NOT_SET_LABEL ) );
            return;
        }

        if ( $unary ) {

            $constraint['operator'] = $unary;
        }

        if ( $this->isDimension( $constraint['name'] ) ) {

            $dim = $this->lookupDimension($constraint['name'], $entity);

            // A Yes/No dimension, filtered in the words its cells show; a request's is checked in setConstraints().
            if ( ! $unary && ( $dim['data_type'] ?? null ) === 'boolean' ) {

                $flag = self::booleanConstraint( $constraint['operator'], $constraint['value'] );

                if ( ! is_array( $flag ) ) {

                    $this->addError( sprintf( $flag, $dim['label'] ?? $constraint['name'] ) );
                    return;
                }

                [ $constraint['operator'], $constraint['value'] ] = $flag;
            }

            $col = $dim['column'];
            $constraint['name'] = $col;

            $db->where($constraint['name'], $constraint['value'], $constraint['operator'], $constraint['key'] ?? null);
        }

        if ( $this->isMetric( $constraint['name'] ) ) {

            // get metric object
            $m = $this->getMetricImplementation( $constraint['name'] );
            // if not calculated
            if ( ! $m->isCalculated() ) {
                $col = $m->getSelectWithNoAlias();
                $db->having($col, $constraint['value'], $constraint['operator'], $constraint['key'] ?? null);
            } else {

                $this->addError( \OWA\Core\CoreAPI::t( 'A calculated metric cannot be a constraint.' ) );
            }
        }
    }

    /**
     * A constraint on a boolean dimension as the column holds it: [operator,
     * value], or why it is refused, a sprintf() format for the dimension's label.
     *
     * The value is what its cells show: Yes or No (in the page's language and
     * in English), or true and false, or 1 and 0, in any case. The flags hold
     * 1 for true and 0 or NULL for false, so Yes is `== 1` and No is `!= 1`,
     * which Db::where() widens to NULL. Compared as written, "Yes" against an
     * integer column is cast to 0, and the filter selected the false rows.
     * Only is and is not mean anything here.
     *
     * @param  string $operator
     * @param  mixed  $value
     * @return array{0: string, 1: int}|string
     */
    public static function booleanConstraint( $operator, $value ) {

        $v   = mb_strtolower( trim( (string) $value ) );
        $yes = in_array( $v, [ '1', 'true', 'yes', mb_strtolower( \OWA\Core\CoreAPI::t( 'Yes' ) ) ], true );
        $no  = in_array( $v, [ '0', 'false', 'no', mb_strtolower( \OWA\Core\CoreAPI::t( 'No' ) ) ], true );

        if ( ! in_array( $operator, [ '==', '=', '!=' ], true ) ) {

            return \OWA\Core\CoreAPI::t( '%s is Yes or No, so it can only be filtered with is or is not.' );
        }

        if ( $yes === $no ) {

            return sprintf( \OWA\Core\CoreAPI::t( '%%s is Yes or No; "%s" is neither.' ), str_replace( '%', '%%', (string) $value ) );
        }

        return ( $yes xor $operator === '!=' ) ? [ '==', 1 ] : [ '!=', 1 ];
    }

    /**
     * The Db operator a constraint's empty test maps to, or '' if it is not one.
     *
     * @param array<string, mixed> $constraint name, value, operator
     * @return string 'empty', 'notempty' or ''
     */
    public static function emptyTestFor( array $constraint ) {

        // A BETWEEN constraint's value is array( 'start', 'end' ), never the label.
        if ( ! isset( $constraint['value'] ) || ! is_scalar( $constraint['value'] )
            || trim( (string) $constraint['value'] ) !== self::NOT_SET_LABEL ) {

            return '';
        }

        switch ( isset( $constraint['operator'] ) ? $constraint['operator'] : '' ) {

            case '==':
                return 'empty';

            case '!=':
                return 'notempty';
        }

        return '';
    }

    /**
     * @param  string $segment a constraints string, optionally after dynamic::
     * @return void
     */
    public function setSegment($segment) {

        $this->query_params['segment'] = $segment;

        /*
         * A saved segment (id::) is not implemented. Refused: with no
         * conditions read, the query would run over everybody.
         */
        if ( substr($segment, 0, 4) === 'id::') {

            $this->addRequestError( \OWA\Core\CoreAPI::t( 'A saved segment (id::) is not supported. Send its conditions as dynamic::.' ) );

            return;
        }

        if ( substr($segment, 0, 9) === 'dynamic::') {

            $segment = substr($segment, 9);
        }

        $parsed = $this->parseConstraintsString( $segment );
        $dimensions = [];

        foreach ( $parsed as $item ) {

            if ( $this->isMetric( $item['name'] ) ) {

                // Not implemented: the segment's query selects on dimensions only.
                $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t(
                    '"%s" is a metric; a segment can select only on dimensions.' ), $item['name'] ) );

            } elseif ( in_array( $item['operator'] ?? '', [ '=~', '!~' ], true )
                    && ( $problem = self::regexProblem( (string) $item['value'] ) ) !== null ) {

                // Run in the database as a filter's pattern is, so refused as one is (setConstraints()).
                $this->addRequestError( sprintf(
                    \OWA\Core\CoreAPI::t( 'The "%1$s" pattern was refused: %2$s' ), $item['name'], $problem ) );

            } elseif ($this->isDimension( $item['name'] ) ) {
                $dimensions[$item['name']] = $item;

            } else {

                /*
                 * Refused, as an unknown constraint name is (setConstraints()).
                 * Dropped, it took that condition out of the segment and the
                 * query answered for everybody the rest of it selects.
                 */
                $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t(
                    '"%s" is not a dimension or a metric, so a segment cannot select on it.' ),
                    $item['name'] ) );
            }
        }

        $this->segment = ['metrics' => [], 'dimensions' => $dimensions];
    }

    /**
     * @return Segment|null
     */
    public function getSegment() {

        return $this->segment;
    }

    /**
     * @return list<string>
     */
    public function getDimensionNamesFromSegment() {

        if ( isset( $this->segment['dimensions'] ) ) {

            return array_keys( $this->segment['dimensions'] );
        } else {
            return [];
        }
    }

    /**
     * The cube entity the query reads, once the metrics it names are built.
     *
     * Null when it names no metric, and null with a request error when a
     * metric or dimension it names is not one.
     *
     * @return \OWA\Core\Entity|null
     */
    public function loadBaseEntity() {

        foreach ( array_unique( array_merge( $this->metrics, $this->getMetricNamesFromConstraints() ) ) as $metric_name ) {

            if ( ! $this->loadMetric( $metric_name ) ) {

                $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t( '"%s" cannot be queried here.' ), $metric_name ) );

                return null;
            }
        }

        if ( ! $this->metricObjects ) {

            return null;
        }

        $entity = $this->entityFor( \OWA\Core\Module::FACT_ENTITY );

        $dimensions = array_merge( $this->getDimensionNamesFromSegment(), $this->dimensions,
            $this->getDimensionsFromConstraints() );

        foreach ( array_unique( $dimensions ) as $dimension ) {

            if ( ! $this->lookupDimension( $dimension, $entity ) ) {

                $this->addRequestError( sprintf( \OWA\Core\CoreAPI::t( '"%s" cannot be queried here.' ), $dimension ) );

                return null;
            }
        }

        return $this->baseEntity = $entity;
    }

    /**
     * @return list<string>
     */
    public function getDimensionsFromConstraints() {

        $dims = [];

        $constraints = $this->getConstraints();
        if (!empty($constraints)) {

            foreach ($constraints as $carray) {

                if ($this->isDimension( $carray['name'] ) ) {
                    $dims[] = $carray['name'];
                }
            }
        }

        return $dims;
    }

    /**
     * @return list<string>
     */
    public function getMetricNamesFromConstraints() {

        $metrics = [];
        foreach ($this->getConstraints() as $k => $constraint) {

            if ( $this->isMetric( $constraint['name'] ) ) {
                $metrics[] = $constraint['name'];
            }
        }

        return $metrics;
    }

    /**
     * Build a metric's object, and a calculated metric's children.
     *
     * @param  string $metric_name
     * @return bool whether the name is a registered metric
     */
    public function loadMetric( $metric_name ) {

        $classes = \OWA\Core\CoreAPI::serviceSingleton()->getMetricClasses( $metric_name );

        if ( ! $classes ) {

            return false;
        }

        foreach ( $classes as $map ) {

            $m = \OWA\Core\CoreAPI::metricFactory( $map['class'], $map['params'] );

            if ( $m->isCalculated() ) {

                $this->addCalculatedMetric( $m );

                foreach ( $m->getChildMetrics() as $child_name ) {

                    $this->loadMetric( $child_name );
                }

            } else {

                // A money metric's input is expressed in this Property's currency,
                // resolved here, before any query is being built.
                $property_id = $this->propertyIdForRequest();
                $m->setPropertyId( $property_id, \OWA\Module\Base\Classes\Currency::ofProperty( $property_id ) );

                $this->metricObjects[ $metric_name ] = $m;
            }
        }

        return true;
    }

    /**
     * @param  string $name
     * @return bool
     */
    public function isDimension( $name ) {

        return $this->dimensionRegistry()->has( $name );
    }

    /**
     * @param  string $name
     * @return bool
     */
    public function isMetric( $name ) {

        $metrics = \OWA\Core\CoreAPI::getAllMetrics();
        return in_array( $name, array_keys( $metrics ) );
    }

    /**
     * Whether a registered metric is computed from others (a ratio, a
     * difference) rather than aggregated from a column.
     *
     * @param  string $name
     * @return bool
     */
    protected function isCalculatedMetric( $name ) {

        foreach ( (array) \OWA\Core\CoreAPI::serviceSingleton()->getMetricClasses( $name ) as $map ) {

            if ( \OWA\Core\CoreAPI::metricFactory( $map['class'], $map['params'] )->isCalculated() ) {

                return true;
            }
        }

        return false;
    }

    /**
     * A dimension of the entity, its column behind the entity's alias, or
     * null when the name is not one.
     *
     * @param string           $name   the name of the dimension
     * @param \OWA\Core\Entity $entity the entity object
     * @return DimensionEntry|null
     */
    public function lookupDimension($name, $entity) {

        $dim = $this->dimensionRegistry()->denormalized( $name, $entity->getName() );

        if ( ! $dim ) {

            $msg = "$name is not a registered dimension.";
            \OWA\Core\CoreAPI::debug( $msg );
            $this->addError( $msg );

            return null;
        }

        /*
         * A dimension registered as an EXPRESSION already carries its own
         * SQL, with %1$s wherever the alias belongs. Writing the alias in
         * FRONT of it -- which is what every column dimension needs -- turns
         * CONCAT(...) into event.CONCAT(...), and MySQL reads that as a call
         * to a function named CONCAT in a schema named event.
         */
        /*
         * Two arguments, because a date part reading the clock needs the
         * timezone as well as the alias. An expression that does not use
         * the second simply ignores it, which is how one substitution
         * serves both kinds.
         */
        $dim['column'] = ! empty( $dim['expression'] )
            ? sprintf( $dim['column'], $entity->getTableAlias(),
                \OWA\Module\Base\Classes\DimensionExpression::timezone() )
            : $entity->getTableAlias().'.'.$dim['column'];

        return $dim;
    }

    /**
     * @param  int|string|null $value rows per page; empty leaves it
     * @return void
     */
    public function setLimit($value) {

        if (!empty($value)) {

            $this->limit = $value;
        }
    }

    /**
     * @param  array<Sort>|null $array as sortStringToArray() makes them
     * @return void
     */
    public function setSorts($array) {

        if (is_array($array)) {

            if (!empty($this->params['orderby'])) {
                $this->params['orderby'] = array_merge($array, $this->params['orderby']);

            } else {
                $this->params['orderby'] = $array;
            }
        }
    }

    /**
     * @return void
     */
    public function applySorts() {

        $sorts = $this->params['orderby'];

        if ($sorts) {

            foreach ($sorts as $sort) {

                $sort_col = null;

                if ( $this->isMetric( $sort[0] ) ) {
                    $sort_metric = $this->getMetricImplementation($sort[0]);
                    if ( $sort_metric->isDifference() ) {

                        // In SQL, for the reason a ratio is; a side with no rows
                        // is zero, as the value treats it.
                        $minuend    = $this->getMetricImplementation( $sort_metric->getMinuend() )->getSelect();
                        $subtrahend = $this->getMetricImplementation( $sort_metric->getSubtrahend() )->getSelect();

                        $sort_col = sprintf( 'COALESCE((%s), 0) - COALESCE((%s), 0)',
                            $minuend[0], $subtrahend[0] );

                    } elseif ( $sort_metric->isRatio() ) {

                        /*
                         * Rendered into SQL, because a sort has to happen on
                         * the server: computing the value in PHP and sorting
                         * that would order the page rather than the result.
                         *
                         * NULLIF, so a zero denominator is not an error, and
                         * COALESCE, so it sorts as the 0 the value is.
                         */
                        $numerator   = $this->getMetricImplementation( $sort_metric->getNumerator() )->getSelect();
                        $denominator = $this->getMetricImplementation( $sort_metric->getDenominator() )->getSelect();

                        $sort_col = sprintf( 'COALESCE((%s) / NULLIF((%s), 0), 0)',
                            $numerator[0], $denominator[0] );

                        if ( $sort_metric->getPrecision() !== null ) {

                            $sort_col = sprintf( 'round(%s, %d)',
                                $sort_col, $sort_metric->getPrecision() );
                        }

                    } else {

                        $select = $sort_metric->getSelect();
                        $sort_col = $select[0];
                    }
                } elseif ( $this->isDimension( $sort[0] ) ) {

                    $dim = $this->lookupDimension( $sort[0], $this->baseEntity );
                    if ( ! empty( $dim['column'] ) ) {
                        $sort_col = $dim['column'];
                    }
                }

                if ( $sort_col === null ) {
                    $this->addError( sprintf( \OWA\Core\CoreAPI::t( '%s is not a valid sort column.' ), $sort[0] ) );
                    continue;
                }

                $this->db->orderBy($sort_col, $sort[1]);
            }
         }
    }

    /**
     * @param  string|null $string
     * @return array<string, Sort>|void nothing for an empty string
     */
    public function sortStringToArray($string) {

        if ($string) {

            // add string to query params array for use in URLs.
            $this->query_params['sort'] = $string;

            $sorts = explode(',', $string);

            $sort_array = [];

            foreach ($sorts as $sort) {

                if (strpos($sort, '-')) {
                    $column = substr($sort, 0, -1);
                    $order = 'DESC';
                } else {
                    $column = $sort;
                    $order = 'ASC';
                }

                $check = $this->isSortValid($column);

                if ($check) {

                    $col_name = $column;

                    if ($col_name) {
                        $sort_array[$sort][0] = $col_name;
                        $sort_array[$sort][1] = $order;

                    } else {
                        $this->addError("$column is not a valid column to sort on");
                    }
                }
            }

            return $sort_array;
        }
    }

    /**
     * @param  string $needle
     * @return bool
     */
    public function isSortValid($needle) {

        $haystack = array_merge($this->metrics, $this->dimensions);
        return in_array($needle, $haystack);
    }

    /**
     * @param  int|string|null $value empty leaves it
     * @return void
     */
    public function setPage($value) {

        if (!empty($value)) {

            $this->page = $value;
        }
    }

    /**
     * @param  int|string|null $value empty leaves it
     * @return void
     */
    public function setOffset($value) {

        if (!empty($value)) {
            $this->params['offset'] = $value;
        }
    }

    /**
     * @param  \OWA\Module\Base\Classes\TimePeriod $value
     * @return void
     */
    public function setPeriod($value) {
        if (!empty($value)) {
            $this->params['period'] = $value;
        }
    }
    
    /**
     * @return string
     */
    public function getTimeResolution() {
      
      return $this->resolution;
    }

    /**
     * The rows a query can return over the period when it names no page size.
     *
     * By date, one per day with both ends counted; by dateHour, 24 per day.
     * The day the clocks go back is still 24 values of dateHour: its repeated
     * hour groups as one. The day difference alone is one short -- a week of
     * days is seven apart and eight long -- and sorted by date the row it cut
     * was today's. By yearMonth or year, the days too: a period has no more
     * months or years than days.
     *
     * A query by anything else is paginated instead (DEFAULT_RESULTS_PER_PAGE,
     * computeDimensionalRows()), and does not reach here.
     *
     * @return int|float
     */
    public function timePeriodRows() {

        $difference = $this->timePeriod->getDaysDifference();

        // Fractional across a daylight-saving change: a midnight-to-midnight
        // difference, in seconds, over 86400.
        $days = (int) round( $difference ) + 1;

        if ( in_array( 'dateHour', $this->dimensions, true ) ) {

            return $days * 24;
        }

        return $days;
    }

    /** The dimensions a trend is drawn over: one row per interval of the period. */
    const TREND_AXES = [ 'date', 'dateHour', 'yearMonth', 'year' ];

    /**
     * Whether the query is by a trend's axis (TREND_AXES), so bounded by the period rather than paged.
     *
     * @return bool
     */
    public function isByDate() {

        return (bool) array_intersect( self::TREND_AXES, (array) $this->dimensions );
    }

    /**
     * @param  string|null     $period_name
     * @param  int|string|null $startDate yyyymmdd
     * @param  int|string|null $endDate   yyyymmdd
     * @param  int|string|null $startTime unix time
     * @param  int|string|null $endTime   unix time
     * @return void
     */
    public function setTimePeriod($period_name = '', $startDate = null, $endDate = null, $startTime = null, $endTime = null) {
      
      $map = false;
      
      if ($startDate && $endDate) {
      
          $period_name = 'date_range';
          $map = ['startDate' => $startDate, 'endDate' => $endDate];
          $dimension_name = 'date';
          $format = 'yyyymmdd';
      
      } elseif ($startTime && $endTime) {
      
          $period_name = 'time_range';
          $map = ['startTime' => $startTime, 'endTime' => $endTime];
          $dimension_name = self::TIME_BOUND;
          $format = 'timestamp';
      
      } else {
      
          \OWA\Core\CoreAPI::debug('no start/end params passed to owa_metric::setTimePeriod');
          $dimension_name = 'date';
          $format = 'yyyymmdd';
      }

      // add to query params array for use in URL construction
      if ($map) {
          $this->query_params = array_merge($map, $this->query_params);
      } else {
          $this->query_params['period'] = $period_name;
      }
        
      if ( $period_name ) {

        // create timePeriod class
        $this->timePeriod = new \OWA\Module\Base\Classes\TimePeriod();

        $this->timePeriod->set($period_name, $map);
        
        // needed?
        $this->setPeriod($this->timePeriod);

        $start = $this->timePeriod->startDate->get($format);
        $end = $this->timePeriod->endDate->get($format);
      
        // set time period constraint for query
        $this->setConstraint($dimension_name, ['start' => $start, 'end' => $end], 'BETWEEN');

        // A TIME bound also gets a closed DATE bound, derived here.
        //
        // The fact tables are RANGE-partitioned on yyyymmdd, so a predicate that names
        // only `timestamp` cannot prune -- and it is unselective enough that the
        // optimiser abandons the timestamp index too. An OPEN lower bound on yyyymmdd
        // still reads everything from that day onwards; the bound has to be closed at
        // both ends to prune to a span.
        //
        // Derived in the query builder rather than left to whoever writes the report,
        // because a report author has no reason to know the physical partitioning --
        // and because this is the seam a non-SQL reporting backend would override with
        // its own pruning predicate.
        //
        // The date is computed from the same timestamps in the same timezone the
        // yyyymmdd column was written in, so a range spanning midnight yields two days
        // and still prunes to those two partitions.
        if ( $dimension_name === self::TIME_BOUND && $start && $end ) {

            $this->setConstraint(
                'date',
                [ 'start' => date( 'Ymd', (int) $start ), 'end' => date( 'Ymd', (int) $end ) ],
                'BETWEEN'
            );
        }

        // And the reverse: a period covering PART of a day needs a time bound as
        // well, or it silently widens to the whole day.
        //
        // last_half_hour and last_hour are allowlisted periods that produced
        // exactly the same constraint as `today` -- date BETWEEN D AND D, with no
        // timestamp bound at all. The period knew it meant 22:15-22:45; the query
        // asked for all 1,440 minutes. Wrong answers, not merely slow ones, and
        // nothing reported it.
        //
        // Only for periods that do NOT sit on day boundaries. today, yesterday,
        // this_week and the rest already align, and adding a redundant timestamp
        // bound to them would risk excluding rows whose yyyymmdd and timestamp
        // disagree at a timezone edge.
        if ( $dimension_name === 'date' ) {

            $startTs = $this->timePeriod->startDate->getTimestamp();
            $endTs   = $this->timePeriod->endDate->getTimestamp();

            // Sub-day means SPAN, not clock alignment. last_seven_days runs
            // 23:59:59 to 23:59:59, so an alignment test flags it as partial and
            // would narrow it from seven whole days to a rolling 7x24h window --
            // quietly changing numbers users already read. A span under a day
            // isolates exactly the windows that need a time bound: currently
            // last_half_hour and last_hour.
            // Two conditions, and both are needed.
            //
            // Span alone is not enough: `today` runs 00:00:00 to 23:59:59, which
            // is 86,399 seconds and slips under a day. Start-of-day alone is not
            // enough either: `last_seven_days` starts at 23:59:59, so an
            // alignment test alone would flag it and narrow seven whole days to a
            // rolling 7x24h window -- quietly changing numbers users already
            // read. Together they select exactly the partial-day windows:
            // currently last_half_hour and last_hour.
            if ( ( $endTs - $startTs ) < 86400 && date( 'His', $startTs ) !== '000000' ) {

                $this->setConstraint(
                    self::TIME_BOUND,
                    [ 'start' => $startTs, 'end' => $endTs ],
                    'BETWEEN'
                );
            }
        }
        }
    }

    /**
     * @param  array<Row>|null $results
     * @return list<ResultRow>
     */
    public function applyMetaDataToResults( $results ) {

        $new_rows = [];
        
        if ( $results ) {

            foreach ($results as $row) {
    
                $new_rows[] = $this->applyMetaDataToSingleResultRow( $row );
            }
        }

        return $new_rows;
    }

    /**
     * @param  Row $row
     * @return ResultRow name => cell
     */
    public function applyMetaDataToSingleResultRow($row) {

        $new_row = [];

        foreach ($row as $k => $v) {

            /*
             * Reset per key. The row can hold a key that is neither metric nor
             * dimension -- a calculated metric's children arrive without having been asked
             * for -- and $type and $data_type would otherwise describe the PREVIOUS column.
             *
             * Null is what the else branch means: no metadata is applied at this stage, as
             * the comment there says.
             */
            $type      = null;
            $data_type = null;

            if (in_array($k, $this->dimensions)) {
                $type = 'dimension';
                $dim = $this->lookupDimension($k, $this->baseEntity);
                $data_type = $dim['data_type'];
            } elseif (in_array($k, $this->metrics)){
                $type = 'metric';
                $data_type = $this->getMetric($k)->getDataType();
            }
            else {
                // can't throw exception here as the metrics are sometimes used to geenrate calculated metrics
                // therefor no meta data is applied as this stage.
            }



            $new_row[$k] = [
                'result_type' => $type,
                'name'           => $k,
                'value'       => $v,
                'formatted_value' => $type === 'dimension'
                                     ? $this->formatDimensionValue( $data_type, $v )
                                     : $this->formatValue($data_type, $v),
                'label' => $this->getLabel($k), 'data_type' => $data_type];
        }

        return $new_row;
    }

    /**
     * A dimension the row carries no value for reads as "(not set)".
     *
     * The label belongs HERE and not in the row, which is the rule the v2 plan
     * states as "(not set) is a presentation choice, never a stored value". A
     * magic string in storage is not absence, it is a value that merely looks
     * like it: it groups as its own bucket, sorts among real values, and cannot
     * be told apart from a visitor who genuinely typed it. Deciding the label at
     * render time keeps one copy of the decision, changeable without touching
     * data, and translatable later.
     *
     * Empty string counts as absent alongside null, because it renders as
     * nothing either way and rows already carry both.
     *
     * DIMENSIONS ONLY. A metric of zero is a measurement, not an absence, and
     * must keep formatting as 0.
     */
    const NOT_SET_LABEL = '(not set)';

    /**
     * And a value the PIPELINE could not work out reads as "(unknown)".
     *
     * A different statement from absence, which is why it gets a different
     * word. "(not set)" says the row carried nothing; this says a build had
     * something to read and could not reach an answer -- a visitor whose
     * acquisition was never captured, or an event whose prior_sessions never
     * arrived. Folding the two onto one label would lose the distinction that
     * Classes\V2Event::UNRESOLVED exists to record.
     *
     * The sentinel is a control byte, so without this it would render as
     * NOTHING: a blank axis label and an unlabelled pie slice. Every cube column
     * that resolves can emit it, source and medium included.
     */
    const UNKNOWN_LABEL = '(unknown)';

    /**
     * @param  string|null $data_type
     * @param  mixed       $value
     * @return mixed
     */
    public function formatDimensionValue( $data_type, $value ) {

        if ( $value === null || $value === '' ) {

            return self::NOT_SET_LABEL;
        }

        if ( $value === \OWA\Module\Base\Classes\V2Event::UNRESOLVED ) {

            return self::UNKNOWN_LABEL;
        }

        /*
         * A dimension built from several columns can carry the sentinel in ONE
         * of its parts -- `(unknown) / referral` is a true statement about a
         * session whose source never resolved but whose medium did. The
         * whole-value comparison above cannot see that, and the pair would
         * render as ` / `.
         *
         * Only a joined dimension can reach this. V2Event::strip() removes
         * control bytes from every observed value, and a pass writes the
         * sentinel alone or not at all, so no single column holds it beside
         * other text.
         */
        if ( strpos( (string) $value, \OWA\Module\Base\Classes\V2Event::UNRESOLVED ) !== false ) {

            return str_replace( \OWA\Module\Base\Classes\V2Event::UNRESOLVED,
                self::UNKNOWN_LABEL, (string) $value );
        }

        return $this->formatValue( $data_type, $value );
    }

    /**
     * @param  string|null $type a data type
     * @param  mixed       $value
     * @return mixed the formatter's answer; the value itself when the type has none
     */
    public function formatValue($type, $value) {

        if ($type !== null && array_key_exists($type, $this->formatters)) {

            $value = call_user_func($this->formatters[$type], $value);
        }

        return $value;
    }

    /**
     * A boolean dimension reads as Yes or No, never as 1 and null.
     *
     * NULL is the important half. These columns store 1 for true and leave the
     * row NULL for false rather than writing 0, so an unformatted pie slice is
     * labelled with an empty string and a grid cell shows nothing at all --
     * which reads as missing data rather than as "no".
     *
     * @param  mixed $value
     * @return string
     */
    public function booleanFormatter($value) {

        return ! empty( $value ) ? \OWA\Core\CoreAPI::t( 'Yes' ) : \OWA\Core\CoreAPI::t( 'No' );
    }

    /**
     * @param  mixed $value
     * @return mixed null as it came
     */
    public function numberFormatter($value) {
    if(is_null($value)){
            return $value;
        }
        return \OWA\Core\Strings::formatNumber( $value );
    }

    /**
     * Epoch seconds as a date and time: "Nov 10, 11:04 AM", the year added
     * when it is not this one.
     *
     * In PHP's default zone, which Settings sets from base.timezone at boot:
     * the zone `date` was written in at ingest. A value that is not a number
     * is returned as it came.
     *
     * @param int|string|null $value epoch seconds
     * @return string|null
     */
    public function formatDateTime( $value ) {

        if ( ! is_numeric( $value ) ) {

            return $value;
        }

        $ts = (int) $value;

        return date( date( 'Y', $ts ) === date( 'Y' ) ? 'M j, g:i A' : 'M j, Y, g:i A', $ts );
    }

    /**
     * Seconds as a duration, in formatMilliseconds()'s format.
     *
     * @param int|float|string|null $value seconds
     * @return string|null
     */
    public function formatSeconds($value) {

        if ( $value === null || $value === '' ) {

            return $value;
        }

        return $this->formatMilliseconds( $value * 1000 );
    }

    /**
     * Milliseconds as a duration.
     *
     * THE STORED VALUE STAYS MILLISECONDS, which is what the beacon carries --
     * `engagement_msec`, accrued on the device. Only the display converts, so
     * this is
     * the same rule "(not set)" follows: the row holds the observation and the
     * renderer decides how to say it.
     *
     * A day or more reads as days plus h:mm:ss (25 hours is "1d 1:00:00"),
     * since a sum across a reporting period passes a day routinely.
     *
     * @param int|null $value milliseconds
     * @return string|null
     */
    public function formatMilliseconds($value) {

        if ( $value === null || $value === '' ) {

            return $value;
        }

        $seconds = (int) round( $value / 1000 );

        $days    = intdiv( $seconds, 86400 );
        $hours   = intdiv( $seconds % 86400, 3600 );
        $minutes = intdiv( $seconds % 3600, 60 );
        $rest    = $seconds % 60;

        if ( $days ) {

            return sprintf( '%dd %d:%02d:%02d', $days, $hours, $minutes, $rest );
        }

        if ( $hours ) {

            return sprintf( '%d:%02d:%02d', $hours, $minutes, $rest );
        }

        return sprintf( '%d:%02d', $minutes, $rest );
    }

    /**
     * NULL STAYS NULL, like numberFormatter above: a value the query did not
     * return is not shown as 0.00%.
     *
     * @param  mixed $value
     * @return mixed null as it came
     */
    public function formatPercentage($value) {

        if ( $value === null ) {

            return $value;
        }

        return \OWA\Core\Strings::formatPercent( $value, 2 );
    }

    /**
     * NULL stays NULL, for the same reason formatPercentage does.
     *
     * @param  mixed $value
     * @return mixed
     */
    public function formatCurrency($value) {

        if ( $value === null ) {

            return $value;
        }

        // The Property's currency, which is what its revenue was recorded in.
        return \OWA\Core\Lib::formatCurrency(
                $value,
                \OWA\Core\CoreAPI::getSetting( 'base', 'currencyLocal' ),
                \OWA\Module\Base\Classes\Currency::ofProperty( $this->propertyIdForRequest() )
        );
    }

    /**
     * Sets an individual label
     * return the key so that it can be nested
     *
     * @param  string      $key
     * @param  string|null $label
     * @return string $key
     */
    public function addLabel($key, $label) {

        $this->labels[$key] = $label;
        return $key;
    }

    /**
     * @param  string $key
     * @return string|null|void
     */
    public function getLabel($key = '') {

        if (array_key_exists($key, $this->labels)) {
            return $this->labels[$key];
        } else {
        }

    }

    /**
     * Retrieve the labels of the measures
     *
     * @return array<string, string|null>
     */
    public function getLabels() {

        return $this->labels;
    }

    /**
     * Set the labels of the measures
     *
     * @param  array<string, string|null> $array
     * @return void
     */
    public function setLabels($array) {

        $this->labels = $array;
    }

    /**
     * @return \OWA\Module\Base\Classes\TimePeriod
     */
    public function getPeriod() {

        return $this->params['period'];
    }

    /**
     * @return int|string|null
     */
    public function getLimit() {

        return $this->limit;
    }
    
    /**
     * @return int|string|null
     */
    public function getPage() {

        return $this->page;
    }

    /**
     * Adds a dimension to the dimension map
     *
     * Retrieves dimension info from service layer and checks to see if
     * dimension is denromalized or if it is a valid relation
     *
     * @param  string $name
     * @return void
     */
    public function setDimension($name) {

        if ($name) {
            $this->dimensions[] = $name;
        }
    }

    /**
     * @param  list<string>|null $array
     * @return void
     */
    public function setDimensions($array) {

        if ($array) {

            foreach($array as $name) {

                $this->setDimension($name);
            }
        }
    }

    /**
     * @param  string $string comma-separated
     * @return list<string>
     */
    public function dimensionsStringToArray($string) {

        // add string to query params array for use in URLs.
        $this->query_params['dimensions'] = $string;
        return explode(',', $string);
    }

    /**
     * @param  string $string comma-separated
     * @return list<string>
     */
    public function metricsStringToArray($string) {

        // add string to query params array for use in URLs.
        $this->query_params['metrics'] = $string;
        return explode(',', $string);
    }


    /**
     * Applies dimensional sql to dao object
     *
     * @return void
     */
    public function applyDimensions() {

        foreach ($this->dimensions as $dimension_name) {
            $dim = $this->lookupDimension($dimension_name, $this->baseEntity);
            // add column name to select statement
            $this->db->selectColumn($dim['column'], $dim['name']);
            // add groupby
            $this->db->groupBy($dim['column']);
            $this->addLabel($dim['name'], $dim['label']);
        }
    }

    /**
     * @param  \OWA\Core\Metric $calc_metric_obj
     * @return void
     */
    public function addCalculatedMetric($calc_metric_obj) {

        // add label of calculated metric obj
        $this->addLabel($calc_metric_obj->getName(),$calc_metric_obj->getLabel());
        // add to calculated metric map
        $this->calculatedMetrics[$calc_metric_obj->getName()] = $calc_metric_obj;

    }

    /**
     * @param  string $name
     * @return \OWA\Core\Metric
     */
    public function getCalculatedMetricByName($name) {

        return $this->calculatedMetrics[$name];
    }

    /**
     * Can only be called after loadBaseEntity().
     *
     * @param  string $metric_name
     * @return \OWA\Core\Metric
     */
    public function getMetricImplementation($metric_name) {

        if (!array_key_exists($metric_name, $this->calculatedMetrics)) {

            return $this->metricObjects[$metric_name];

        } else {
            return $this->getCalculatedMetricByName($metric_name);
        }
    }
    
    /**
     * Generates select statment from metrics
     *
     * @return void
     */
    public function applyMetrics() {
        foreach($this->metrics as $k => $metric_name) {

            if (!array_key_exists($metric_name, $this->calculatedMetrics)) {

                $m = $this->metricObjects[$metric_name];

                $select = $m->getSelect();
                $this->db->selectColumn($select[0], $select[1]);
            } else {
                $m = $this->getCalculatedMetricByName($metric_name);
            }

            $this->addLabel($m->getName(), $m->getLabel());
        }

        // add selects for calculated metrics
        if (!empty($this->calculatedMetrics)) {

            // loop through calculated metric objects
            foreach ($this->calculatedMetrics as $cmetric) {
                //create child metrics
                foreach( $cmetric->getChildMetrics() as $child_name) {
                    // check to see if the metric has already been added
                    if (!in_array($child_name, $this->metrics)) {

                        $child = $this->metricObjects[$child_name];
                        $select = $child->getSelect();
                        $this->db->selectColumn($select[0], $select[1]);
                        // needed so we can remove this temp metric later
                        $this->childMetrics[] = $child_name;
                        \OWA\Core\CoreAPI::debug("Added $child_name to ChildMetrics array");
                    }
                }
            }
        }
    }

    /**
     * Sets a metric's column name into the all_columns map
     *
     * this is needed when combining metrics so that sort and
     * constraint column names can be looked up fro ma single map.
     *
     * NEEDED???
     *
     * @param  string $name
     * @param  mixed  $col
     * @return void
     */
    public function addColumn($name, $col) {

        $this->all_columns[$name] = $col;
    }

    /**
     * The request asked for something that cannot be honoured.
     *
     * A query is NOT run when one of these is recorded. Returning rows anyway
     * is what made the original bug so hard to see: the caller got an error in
     * a field it had no reason to read AND a plausible set of numbers computed
     * without the filter it asked for, under a success status.
     *
     * Reserved for what a caller can fix by sending a different request.
     * Routine internal misses stay in addError().
     *
     * @param  string $msg
     * @return void
     */
    public function addRequestError($msg) {

        $this->request_errors[] = $msg;

        $this->addError($msg);
    }

    /**
     * Whether the request is malformed, so no query should run.
     *
     * @return bool
     */
    public function hasRequestErrors() {

        return ! empty( $this->request_errors );
    }

    /**
     * @param  string $msg
     * @return void
     */
    public function addError($msg) {

        $this->errors[] = $msg;
        \OWA\Core\CoreAPI::debug($msg);
    }
    
    /**
     * @param \OWA\Core\Entity $bm the base entity
     * @return Row|null the totals
     */
    public function computeAggregates( $bm ) {

        /*
         * A FILTER ON A METRIC KEEPS GROUPS, so the totals are of the groups it
         * keeps. As HAVING on this query, which has no GROUP BY, it tested the
         * grand total: pageViews>50 by page path gave the total of every page,
         * or no total at all. The groups are found by the grid's own grouping
         * and the totals read only their rows (keptGroupsCondition()), so a
         * distinct count is distinct across them, not a sum of each group's.
         */
        $metric_constraints = $this->dimensions ? $this->metricConstraints() : [];

        if ( $metric_constraints ) {

            foreach ( array_diff_key( (array) $this->getConstraints(), $metric_constraints ) as $constraint ) {

                $this->applyConstraint( $constraint, $this->db );
            }

            $this->applySharedMetricCondition( $this->db );

            $kept = $this->keptGroupsCondition( $bm );

            if ( $kept ) {

                $this->db->whereSql( 'owa_kept_groups', $kept[0], $kept[1] );
            }

        } else {

            // generates where clause based on metrics and dimensions
            $this->applyConstraints();
        }
        
        // generates select statement from metrics
        $this->applyMetrics();

        // generates from clause or a subselect if segment is specified
        if ( $this->segment ) {
            
            [ $segment_sql, $segment_bindings ] = $this->segmentQuery( $bm );
            $this->db->selectFrom( $segment_sql, $bm->getTableAlias(), $segment_bindings );
        
        } else {
        
            $this->db->selectFrom($bm->getTableName(), $bm->getTableAlias());
        }

        /*
         * AS OF WHEN. Every cube row carries built_at, the moment the build
         * wrote its partition, so the newest one among the rows this report
         * reads is what its numbers are "as of" -- a report reads the cube,
         * and the cube moves only when a build runs. MAX, not MIN: settled
         * partitions are not rebuilt because they are complete, so their older
         * build times say nothing about staleness.
         *
         * Carried on the query already being run, so it costs no statement of
         * its own. Not under a segment, whose subselect names its own columns.
         */
        $as_of = ! $this->segment && method_exists( $bm, 'getPropertyId' ) && $bm->getPropertyId() !== '';

        if ( $as_of ) {

            $this->db->selectColumn( sprintf( 'MAX(%s.built_at)', $bm->getTableAlias() ), 'owa_as_of' );
        }

        // Bounded as the rows are: the same filter and period shape this query.
        $db      = $this->db;
        $results = $db->withSelectTimeLimit( self::QUERY_SECONDS, function () use ( $db ) {
            return $db->getOneRow();
        } );

        if ( $as_of && is_array( $results ) && array_key_exists( 'owa_as_of', $results ) ) {

            $this->resultSet->asOf = $results['owa_as_of']
                ? intdiv( (int) $results['owa_as_of'], 1000000 ) : null;

            unset( $results['owa_as_of'] );
        }
        
        return $results;
    }
    
    /**
     * The request's constraints on metrics, by their key.
     *
     * @return array<string, Constraint>
     */
    public function metricConstraints() {

        $out = [];

        foreach ( (array) $this->getConstraints() as $key => $constraint ) {

            if ( isset( $constraint['name'] ) && $this->isMetric( $constraint['name'] ) ) {

                $out[ $key ] = $constraint;
            }
        }

        return $out;
    }

    /**
     * The rows of the groups the metric constraints keep, as a WHERE test:
     * array( SQL, bindings ), or null when there are no dimensions to group by.
     *
     * The row's key (groupKeySql()) IN the keys of the grid's own groups
     * (groupKeysQuery()), HAVING included.
     *
     * @param \OWA\Core\Entity $bm the base entity
     * @return array{0: string, 1: list<mixed>}|null
     */
    public function keptGroupsCondition( $bm ) {

        $key   = $this->groupKeySql();
        $inner = $key ? $this->groupKeysQuery() : null;

        if ( ! $inner ) {

            return null;
        }

        return [ sprintf( '%s IN (SELECT owa_group_key FROM (%s) owa_kept)', $key, $inner[0] ), $inner[1] ];
    }

    /**
     * A row's group as SQL: every dimension's column, each marked as a value
     * ('v' and the value) or as NULL ('n'), joined by CHAR(31). A group whose
     * dimension is unset has a key like any other, and never the key of one
     * holding ''. Null with no dimensions.
     *
     * @return string|null
     */
    public function groupKeySql() {

        $parts = [];

        foreach ( $this->groupColumns() as $column ) {

            $parts[] = sprintf( "COALESCE(CONCAT('v', %s), 'n')", $column );
        }

        return $parts ? sprintf( OWA_SQL_GROUP_KEY, implode( ', ', $parts ) ) : null;
    }

    /**
     * A result row's group key, as groupKeySql() computes it in SQL.
     *
     * @param ResultRow    $row        a row of resultsRows: name => cell
     * @param list<string> $dimensions the dimensions it is grouped by
     * @return string
     */
    public static function groupKeyOf( array $row, array $dimensions ) {

        $parts = [];

        foreach ( $dimensions as $name ) {

            $value   = isset( $row[ $name ] ) && is_array( $row[ $name ] ) ? $row[ $name ]['value'] : null;
            $parts[] = $value === null ? 'n' : 'v' . (string) $value;
        }

        return implode( "\x1f", $parts );
    }

    /**
     * The columns of the query's dimensions, in their order.
     *
     * @return list<string>
     */
    private function groupColumns() {

        $columns = [];

        foreach ( (array) $this->dimensions as $dimension_name ) {

            $dim = $this->lookupDimension( $dimension_name, $this->baseEntity );

            if ( $dim ) {

                $columns[] = $dim['column'];
            }
        }

        return $columns;
    }

    /**
     * The query's groups as SQL selecting each one's key (groupKeySql()) as
     * owa_group_key: its FROM, WHERE, GROUP BY and HAVING, without the
     * pagination. array( SQL, bindings ), or null when there are no
     * dimensions to group by or no table to read.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    public function groupKeysQuery() {

        $bm  = $this->baseEntity ?: $this->loadBaseEntity();
        $key = $bm ? $this->groupKeySql() : null;

        if ( ! $key ) {

            return null;
        }

        // The alias written in, not passed: passed, it is escaped, which opens a connection.
        $inner = \OWA\Core\CoreAPI::dbFactory();
        $inner->selectColumn( $key . ' AS owa_group_key' );

        foreach ( $this->groupColumns() as $column ) {

            $inner->groupBy( $column );
        }

        // The grid's WHERE and HAVING, the HAVING now over its groups.
        foreach ( (array) $this->getConstraints() as $constraint ) {

            $this->applyConstraint( $constraint, $inner );
        }

        $shared = ! $this->segment ? $this->sharedMetricCondition() : null;

        if ( $shared ) {

            $inner->where( $shared[0], $shared[1], $shared[2], 'owa_metric_condition' );
        }

        if ( $this->segment ) {

            [ $segment_sql, $segment_bindings ] = $this->segmentQuery( $bm );
            $inner->selectFrom( $segment_sql, $bm->getTableAlias(), $segment_bindings );

        } else {

            $inner->selectFrom( $bm->getTableName(), $bm->getTableAlias() );
        }

        // And the groups the rows are restricted to or exclude, so a count of them is the rows'.
        $this->applyGroupConditions( $inner );

        $sql = $inner->generateSelectQuerySql();

        return [ $sql, $inner->getBindings() ];
    }

    /**
     * How many groups -- rows, unpaged -- the query has: at most $cap + 1
     * when a cap is given, so a count past it costs no more than reading that
     * far.
     *
     * @param  int|null $cap
     * @return int
     */
    public function countGroups( $cap = null ) {

        $inner = $this->groupKeysQuery();

        if ( ! $inner ) {

            return 0;
        }

        $limit = $cap === null ? '' : ' LIMIT ' . ( max( 0, (int) $cap ) + 1 );

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $row = $db->withSelectTimeLimit( self::QUERY_SECONDS, function () use ( $db, $inner, $limit ) {
            return $db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM (%s%s) owa_groups', $inner[0], $limit ), $inner[1] );
        } );

        return (int) ( $row['n'] ?? 0 );
    }

    /** The pages a total is counted through, past the one asked for: as far as a pager offers to go. */
    const COUNT_PAGES_AHEAD = 10;

    /**
     * The result set's total of rows (PaginatedResultSet::setTotalRows()): the
     * rows to this page's end where there is no next, else the groups
     * counted, up to COUNT_PAGES_AHEAD pages past this one.
     *
     * @return void
     */
    private function countPages() {

        $per    = (int) $this->getLimit();
        $offset = $per * ( max( 1, (int) $this->getPage() ) - 1 );

        if ( $per < 1 ) {

            return;
        }

        if ( ! $this->resultSet->more ) {

            $returned = (int) $this->resultSet->resultsReturned;

            // A page past the last row says nothing of where they end: counted.
            $this->resultSet->setTotalRows( $returned || ! $offset ? $offset + $returned : $this->countGroups(), false );

            return;
        }

        $cap = $offset + $per * self::COUNT_PAGES_AHEAD;
        $n   = $this->countGroups( $cap );

        $this->resultSet->setTotalRows( max( $offset + $per + 1, min( $n, $cap ) ), $n > $cap );
    }

    /** @var string[]|null the group keys the rows are restricted to; see restrictToGroups() */
    private $group_keys = null;

    /** @var ResultSetManager|null the query whose groups the rows leave out; see excludeGroupsOf() */
    private $excluded_groups_of = null;

    /** @var array{0: int, 1: int}|null [ offset, count ] of the rows to read; see setRowWindow() */
    private $row_window = null;

    /** @var bool whether the totals are left unread; see setRowsOnly() */
    private $rows_only = false;

    /**
     * Only the rows of these groups (groupKeyOf()). A NULL dimension value is
     * a key like any other, and a request's own filter on the dimension stays.
     *
     * @param string[] $keys
     * @return void
     */
    public function restrictToGroups( array $keys ) {

        $this->group_keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
    }

    /**
     * Only the rows of groups $other has none of: by its FROM, WHERE and
     * HAVING (groupKeysQuery()), over all its rows, not a page of them.
     *
     * @param ResultSetManager $other grouped by the same dimensions
     * @return void
     */
    public function excludeGroupsOf( ResultSetManager $other ) {

        $this->excluded_groups_of = $other;
    }

    /**
     * Read $count rows from row $offset, with no read-ahead: the result set
     * holds all of them, and has no next page.
     *
     * @param int $offset
     * @param int $count  at least 1
     * @return void
     */
    public function setRowWindow( $offset, $count ) {

        $this->row_window = [ max( 0, (int) $offset ), max( 1, (int) $count ) ];
        $this->limit      = $this->row_window[1];
        $this->page       = 1;
    }

    /**
     * Leave the totals (aggregates) unread: the caller uses the rows only.
     *
     * @param  bool $rows_only
     * @return void
     */
    public function setRowsOnly( $rows_only = true ) {

        $this->rows_only = (bool) $rows_only;
    }

    /**
     * restrictToGroups() and excludeGroupsOf() as WHERE tests on the rows'
     * query.
     *
     * @param \OWA\Core\Db $db
     * @return void
     */
    public function applyGroupConditions( $db ) {

        if ( $this->group_keys === null && ! $this->excluded_groups_of ) {

            return;
        }

        $key = $this->groupKeySql();

        if ( ! $key ) {

            return;
        }

        if ( $this->group_keys !== null ) {

            if ( $this->group_keys ) {

                $db->whereSql( 'owa_group_keys', sprintf( '%s IN (%s)', $key,
                    implode( ', ', array_fill( 0, count( $this->group_keys ), '?' ) ) ), $this->group_keys );

            } else {

                $db->whereSql( 'owa_group_keys', '1 = 0' );
            }
        }

        if ( $this->excluded_groups_of ) {

            $inner = $this->excluded_groups_of->groupKeysQuery();

            if ( $inner ) {

                $db->whereSql( 'owa_excluded_groups', sprintf( '%s NOT IN (SELECT owa_group_key FROM (%s) owa_excluded)',
                    $key, $inner[0] ), $inner[1] );
            }
        }
    }

    /**
     * @param  \OWA\Core\Entity $bm the base entity
     * @return list<Row>|null the page's rows, and the read-ahead; null for none
     */
    public function computeDimensionalRows( $bm ) {

        /*
         * A query by anything but a trend's axis that names no page size gets
         * DEFAULT_RESULTS_PER_PAGE, and pages. Without it the date range below
         * would limit by the period -- one row a day, a sound bound only for a
         * query BY day -- and a breakdown over the last seven days would stop
         * at six rows and say there were no more.
         */
        if ( ! $this->getLimit() && ! $this->isByDate() ) {

            $this->setLimit( self::DEFAULT_RESULTS_PER_PAGE );
        }

        // apply dimensional SQL
        $this->applyDimensions();

        $this->applyMetrics();
        
        $this->applyConstraints();

        $this->applyGroupConditions( $this->db );

        // set from table
        if ( $this->segment ) {
            
            [ $segment_sql, $segment_bindings ] = $this->segmentQuery( $bm );
            $this->db->selectFrom( $segment_sql, $bm->getTableAlias(), $segment_bindings );
            
        } else {
            
            $this->db->selectFrom($bm->getTableName(), $bm->getTableAlias());
        }

        // pass limit and page to result set object if one exists
        // needed??
        if ( ! empty( $this->limit ) ) {
            
            $this->resultSet->setLimit( $this->limit );
        }
       
        if ( ! empty( $this->page ) ) {
            
            $this->resultSet->setPage( $this->page );
        }
        
        if ( $this->row_window ) {

            // setRowWindow(): these rows, no read-ahead.
            $this->db->limit( $this->row_window[1] );

        // A page, and one row past it to know there is a next. How many
        // there are in all is counted apart (countPages()).
        } elseif ( $this->getLimit() ) {

            $this->db->limit( $this->getLimit() + 1 );
            
        } else {
          
          // assume it's a date/time range query and use range resolution as limit
          switch ( $this->getTimeResolution() ) {
            
            case "day":
              $this->setLimit( $this->timePeriodRows() );
              break;
              
            case "month":
              $this->setLimit( $this->timePeriod->getMonthsDifference() );
              break;
              
            case "year":
              $this->setLimit( $this->timePeriod->getYearsDifference() );
              break;
            
            default:
              
              break;
              
          }
          
          $this->db->limit( $this->getLimit() );
        }
        
        if ( $this->row_window ) {

            $this->db->offset( $this->row_window[0] );

        } elseif ( ! empty( $this->page ) ) {

            $this->db->offset( $this->calculateOffset() );
            
        } else {
            
            $this->page = 1;
        }

        // Bounded: the filter, period and dimensions are the viewer's choice.
        $db      = $this->db;
        $results = $db->withSelectTimeLimit( self::QUERY_SECONDS, function () use ( $db ) {
            return $db->getAllRows();
        } );

        return $results;
    }
    
    /**
     * @return int|float
     */
    public function calculateOffset() {
        
        if ( $this->page > 1 ) {
        
            return $this->limit * ( $this->page - 1 );
        
        } else {
            
            return 0;
        }
    }

    /**
     * Generates a reporting result set using metrics and dimension
     *
     * @return \OWA\Module\Base\Classes\PaginatedResultSet
     */
    public function getResults() {

        /*
         * A malformed request is not run at all.
         *
         * Recording the error and querying anyway meant the caller received
         * BOTH the complaint and a full set of numbers computed without the
         * filter they asked for -- and, over REST, under a 201. Numbers that
         * look right are worse than none, because nothing downstream doubts
         * them.
         *
         * The result set still carries the errors, so the caller is told why.
         */
        if ( $this->hasRequestErrors() ) {

            $this->resultSet->errors = $this->errors;
        $this->resultSet->request_errors = $this->request_errors;

            return $this->resultSet;
        }

        $bm = $this->loadBaseEntity();

        /*
         * NEVER QUERY A CUBE THAT DOES NOT EXIST. The report controllers and the
         * REST route ask first, so a request reaching here for a Property with
         * no cube came from somewhere that did not -- a custom report preview,
         * or a caller yet to be written. Answered the same way: no rows, and
         * the reason.
         */
        if ( $bm && method_exists( $bm, 'getPropertyId' ) && $bm->getPropertyId() !== ''
                && ! \OWA\Module\Base\Classes\Cube\Cubes::exists( $bm->getPropertyId() ) ) {

            $this->resultSet->notReady = \OWA\Module\Base\Classes\Cube\Status::readinessOfProperty(
                $bm->getPropertyId() );

            return $this->resultSet;
        }

        if ( $bm ) {

            $bname = $bm->getName();

            \OWA\Core\CoreAPI::debug("Using $bname as fact table entity for this result set.");

            // generate aggregate results
            $results = $this->rows_only ? null : $this->computeAggregates( $bm );
            
            // merge into result set
            if ( $results ) {
                
                $this->resultSet->aggregates = array_merge( $this->applyMetaDataToSingleResultRow( $results ), $this->resultSet->aggregates );
            }
            
            $dresults = [];
            // setup dimensional query if dimensions were specified in query
            if ( ! empty( $this->dimensions ) ) {
                
                // Apply sorts
                if ( array_key_exists( 'orderby', $this->params ) ) {
                
                    $sorts = $this->params['orderby'];
                    
                    // apply sort by
                    if ($sorts) {
                        
                        $this->applySorts();
                    
                        foreach ($sorts as $sort) {
                            $this->resultSet->sortColumn = $sort[0];
                            
                            if (isset($sort[1])){
                                
                                $this->resultSet->sortOrder = strtolower($sort[1]);
                            } else {
                                
                                $this->resultSet->sortOrder = 'asc';
                            }
                        }
                    }
                }

                // Query for the dimensional rows.
                //
                // OUTSIDE the orderby block, which is where these two lines used
                // to sit -- their indentation always said they belonged here, but
                // the brace put them inside. The effect was that a breakdown with
                // no sort never ran its query at all: $dresults stayed undefined,
                // generate() received nothing, and the caller got zero rows next
                // to a perfectly correct aggregate. No error, because an
                // unassigned variable is only a notice and nothing was watching.
                //
                // It survived because every one of the ~60 declarative report
                // controllers sets a sort, so no shipped report ever took the
                // unsorted path. Found by reporting-facets.spec.js, which asks
                // for a breakdown without one.
                $dresults = (array) $this->computeDimensionalRows( $bm );

                /*
                 * The row past the page says there is a next, and is not shown:
                 * generate() keeps the first getLimit() rows, so only those
                 * are formatted.
                 */
                $per      = (int) $this->getLimit();
                $dresults = $per > 0
                    ? array_merge( $this->applyMetaDataToResults( array_slice( $dresults, 0, $per ) ),
                        array_slice( $dresults, $per ) )
                    : $this->applyMetaDataToResults( $dresults );

                // generate dimensional results
                $this->resultSet->generate( $dresults, $this->query_params, [
                    
                    'resultsPerPage' => $this->getlimit(),
                    'page'           => $this->getPage()
                ] );

                $this->countPages();
                
            }

            // add labels
            $this->resultSet->setLabels( $this->getLabels() );

            // add period info
            $this->resultSet->setPeriodInfo( $this->params['period']->getAllInfo() );
              
            $this->resultSet = $this->computeCalculatedMetrics( $this->resultSet );
        }
        
        // set any metric/dimension combination errors
        $this->resultSet->errors = $this->errors;
        $this->resultSet->request_errors = $this->request_errors;
        
        // None when the query named something that is not a metric or a dimension.
        $this->resultSet->setRelatedDimensions( $bm ? $this->relatedDimensions() : [] );

        $this->resultSet->currencyExponent = Currency::exponent( Currency::ofProperty( $this->propertyIdForRequest() ) );

        $this->resultSet->setRelatedMetrics( $bm ? $this->relatedMetrics() : [] );

        return $this->resultSet;
    }
    

    /**
     * @param  \OWA\Core\Entity $base_entity
     * @return array{0: string|null, 1: list<scalar|null>} the segment's subselect, in
     *         parentheses, and the values of its placeholders in order; [null, []]
     *         without a segment
     */
    public function segmentQuery( $base_entity ) {

        $segment = $this->getSegment();
        $segment_entity = $this->entityFor($base_entity->getName());
        $segment_entity->setTableAlias( $segment_entity->getTableAlias() . '_segment');

        if ( $segment ) {
            // use a new data access object
            $db = \OWA\Core\CoreAPI::dbFactory();
            $db->select( $segment_entity->getTableAlias().'.*' );
            $db->from( $segment_entity->getTableName(), $segment_entity->getTableAlias() );

            if ( isset( $segment['dimensions'] ) ) {

                $this->applyConstraints( $segment['dimensions'], $db, $segment_entity);

                // apply siteId, startDate, and endDate constraints
                $constraint_names = ['siteId', 'date'];
                $constraints_apply = [];
                foreach ( $constraint_names as $name ) {

                    $con = $this->getConstraint( $name );
                    if ( $con ) {
                        $constraints_apply[$name] = $con;
                    }
                }

                if ( $constraints_apply ) {
                    $this->applyConstraints( $constraints_apply, $db, $segment_entity);
                }
            }

            $sql = $db->generateSelectQuerySql();

            return [ sprintf('(%s)', $sql ), $db->getBindings() ];
        }

        return [ null, [] ];
    }

    /**
     * @param  \OWA\Module\Base\Classes\PaginatedResultSet $rs
     * @return \OWA\Module\Base\Classes\PaginatedResultSet $rs
     */
    public function computeCalculatedMetrics($rs) {

        foreach ($this->calculatedMetrics as $cm) {

            if ( $cm->isDifference() ) {

                $value = $cm->computeDifference(
                    $rs->getAggregateMetric( $cm->getMinuend() ),
                    $rs->getAggregateMetric( $cm->getSubtrahend() ) );

                $rs->setAggregateMetric( $cm->getName(), $value, $cm->getLabel(),
                    $cm->getDataType(), $this->formatValue( $cm->getDataType(), $value ) );

                foreach ( $rs->getRowCount() > 0 ? $rs->resultsRows : [] as $k => $row ) {

                    $v = $cm->computeDifference(
                        $row[ $cm->getMinuend() ]['value'] ?? null,
                        $row[ $cm->getSubtrahend() ]['value'] ?? null );

                    $rs->appendRow( $k, 'metric', $cm->getName(), $v, $cm->getLabel(),
                        $cm->getDataType(), $this->formatValue( $cm->getDataType(), $v ) );
                }

                continue;
            }

            // add aggregate metric
            if ( $cm->isRatio() ) {

                $value = $cm->computeRatio(
                    $rs->getAggregateMetric( $cm->getNumerator() ),
                    $rs->getAggregateMetric( $cm->getDenominator() ) );

                $rs->setAggregateMetric( $cm->getName(), $value, $cm->getLabel(),
                    $cm->getDataType(), $this->formatValue( $cm->getDataType(), $value ) );

                $this->appendRatioRows( $rs, $cm );

                continue;
            }

            // Every calculated metric is a difference or a ratio: a formula is
            // refused when the metric is built (ConfigurableMetric).
        }

        // clean up by removing child metrics before returning the result set.
        foreach ($this->childMetrics as $metric_name) {

            $rs->removeMetric($metric_name);
        }

        return $rs;
    }

    /**
     * A ratio's value on every row of a breakdown.
     *
     * The same division as the aggregate, per row. A row with no denominator
     * is 0, as computeRatio() answers.
     *
     * @param  \OWA\Module\Base\Classes\PaginatedResultSet $rs
     * @param  \OWA\Core\Metric $cm
     * @return void
     */
    protected function appendRatioRows( $rs, $cm ) {

        if ( $rs->getRowCount() < 1 ) {

            return;
        }

        $numerator   = $cm->getNumerator();
        $denominator = $cm->getDenominator();

        foreach ( $rs->resultsRows as $k => $row ) {

            $value = $cm->computeRatio(
                array_key_exists( $numerator, $row )   ? $row[ $numerator ]['value']   : null,
                array_key_exists( $denominator, $row ) ? $row[ $denominator ]['value'] : null );

            $rs->appendRow( $k, 'metric', $cm->getName(), $value, $cm->getLabel(),
                $cm->getDataType(), $this->formatValue( $cm->getDataType(), $value ) );
        }
    }

    /**
     * A metric's object, built if the query did not name it.
     *
     * @param    string    $name    the name of the metric
     * @return   \OWA\Core\Metric
     */
    public function getMetric($name) {

        if ( ! in_array( $name, $this->metrics ) ) {

            $this->loadMetric( $name );
        }

        return $this->metricObjects[$name];
    }

    /**
     * The dimensions a query can group by, by family, each with what the
     * choosers show: its description, its family's name and its value type.
     *
     * @return RelatedDimensions
     */
    public function relatedDimensions() {

        $dims = [];

        foreach ( $this->dimensionRegistry()->onEntity( \OWA\Core\Module::FACT_ENTITY ) as $ddim ) {

            $dims[ $ddim['family'] ][] = [
                'name'        => $ddim['name'],
                'label'       => $ddim['label'],
                'description' => (string) ( $ddim['description'] ?? '' ),
                'group_label' => FieldGroups::label( $ddim['family'] ),
                'data_type'   => (string) ( $ddim['data_type'] ?? '' ),
            ];
        }

        return $dims;
    }

    /**
     * The metrics a query can ask for, by group: every metric, but a
     * calculated one only when each of its children is a metric of its own.
     *
     * Each carries its metric_type so the grid can tell a count, whose rows
     * are each a share of the total, from a ratio or an average, whose rows
     * are not.
     *
     * @return RelatedMetrics
     */
    public function relatedMetrics() {

        $all       = \OWA\Core\CoreAPI::serviceSingleton()->getAllMetrics();
        $built     = [];
        $plain     = [];
        $related   = [];

        foreach ( $all as $metric_name => $implementations ) {

            foreach ( $implementations as $implementation ) {

                $m = \OWA\Core\CoreAPI::metricFactory( $implementation['class'], $implementation['params'] );

                $built[] = [ $metric_name, $implementation, $m ];

                if ( ! $m->isCalculated() ) {

                    $plain[ $implementation['name'] ] = true;
                }
            }
        }

        foreach ( $built as list( $metric_name, $implementation, $m ) ) {

            if ( $m->isCalculated() && array_diff( $m->getChildMetrics(), array_keys( $plain ) ) ) {

                continue;
            }

            $related[ $implementation['group'] ][] = [
                'name'        => $metric_name,
                'label'       => $implementation['label'],
                'description' => $implementation['description'],
                'group'       => $implementation['group'],
                'group_label' => FieldGroups::label( $implementation['group'] ),
                'metric_type' => $implementation['params']['metric_type'] ?? ''
            ];
        }

        return $related;
    }
}
