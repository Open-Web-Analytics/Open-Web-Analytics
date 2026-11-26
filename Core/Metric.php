<?php
namespace OWA\Core;

/**
 * Metric
 * 
 *
 * @phpstan-type MetricCondition array{column?: string, value?: scalar, operator?: string}
 */
class Metric extends \OWA\Core\Base {

    /**
     * A ratio's two children and its rounding, when it is one.
     *
     * Named rather than written into a formula string, so nothing has to be
     * substituted by name into an expression -- see setRatio() for what that
     * costs.
     *
     * @var string
     */
    protected $numerator = '';

    /** @var string */
    protected $denominator = '';

    /** @var int|null  decimal places, or null to round to none */
    protected $precision = null;

    /**
     * A difference's two children: minuend less subtrahend. Named for the same
     * reason a ratio's sides are.
     *
     * @var string
     */
    protected $minuend = '';

    /** @var string */
    protected $subtrahend = '';

    /**
     * The rows this metric counts, when it counts some of them.
     *
     * ['column' => ..., 'value' => ..., 'operator' => '=']. Empty means every
     * row.
     *
     * @var MetricCondition
     */
    protected $condition = [];

    /**
     * Current Time
     *
     * @var array<string, int|string> as Lib::time_now() answers; nothing here sets it
     */
    public $time_now = [];
    
    /**
     * Data
     *
     * @var mixed nothing here sets or reads it
     */
    public $data;
    
    /**
     * The params of the caller, either a report or graph
     *
     * @var array<string, mixed> name => value, any type
     */
    public $params = [];
        
    /**
     * The lables for calculated measures
     *
     * @var array<string, string> name => label
     */
    public $labels = [];
    
    /**
     * Data Access Object
     *
     * @var object
     */
    public $db;
    
    /** @var mixed nothing here sets or reads it */
    public $page;
    
    /** @var mixed nothing here sets or reads it */
    public $limit;
    
    /** @var mixed nothing here sets or reads it */
    public $order;
    
    /** @var mixed nothing here sets or reads it */
    public $table;
    
    /**
     * An old style metric's SELECT, set by the metric itself: [expression, alias].
     *
     * @var list<string>
     */
    public $select = [];
    
    /**
     * The counted column, qualified by the entity's alias. See setColumn().
     *
     * @var string|null
     */
    public $column;
    
    /** @var bool */
    public $is_calculated = false;
    
    /**
     * One of $supported_data_types; null until setDataType() is given one.
     *
     * @var string|null
     */
    public $data_type;
    
    /** @var string|null null until setName() */
    public $name;
    
    /** The data types a metric's values are formatted by. */
    const DATA_TYPES = ['percentage', 'decimal', 'integer', 'url', 'yyyymmdd', 'timestamp', 'string', 'currency', 'milliseconds'];

    /** @var list<string> DATA_TYPES */
    public $supported_data_types = self::DATA_TYPES;

    /**
     * The aggregation (count, distinct_count, sum, boolean_true_count,
     * avg_difference) or calculated, ratio, difference; null until
     * setMetricType().
     *
     * @var string|null
     */
    public $type;

    /** @var \OWA\Core\Entity|null the entity counted; null until setEntity() */
    public $entity;

    /** @var array<string, string>|null metric name => qualified column; null until setColumn() */
    public $all_columns;
        
    /**
     * @param array<string, mixed> $params the caller's params, any type
     */
    public function __construct($params = []) {
        
        if (!empty($params)) {
            $this->params = $params;
        }
            
        parent::__construct();
    }
    
    /**
     * Set the labels of the measures
     *
     * @param  array<string, string> $array name => label
     * @return void
     */
    public function setLabels($array) {
    
        $this->labels = $array;
        return;
    }
    
    /**
     * Sets an individual label
     * return the key so that it can be nested
     *
     * @param  string $key
     * @param  string $label
     * @return string $key
     */
    public function addLabel($key, $label) {
        
        $this->labels[$key] = $label;
        return $key;
    }
    
    /**
     * @param  string $key a measure's name; '' for this metric's own
     * @return string
     */
    public function getLabel($key = '') {
        
        if (!$key) {
            $key = $this->getName();
        }
        
        return $this->labels[$key];
    }
    
    /**
     * Sets the label of the metric itself
     *
     * @param  string $label
     * @return void
     */
    public function setLabel($label) {
        
        $this->labels[$this->getName()] = $label;
        
    }
    
    /**
     * Retrieve the labels of the measures
     *
     * @return array<string, string> name => label
     */
    public function getLabels() {
    
        return $this->labels;
    
    }

    /**
     * @param  string $name module.entity, e.g. base.event
     * @return void
     */
    public function setEntity($name) {
        
        $this->entity = \OWA\Core\CoreAPI::entityFactory($name);
    }
    
    /**
     * @return string
     */
    public function getTableName() {
        
        return $this->entity->getTableName();
    }
    
    /**
     * @return string|null
     */
    public function getTableAlias() {
        
        return $this->entity->getTableAlias();
    }
    
    /**
     * The SELECT expression and its alias.
     *
     * @return list<string|null> [expression, null when it cannot be built; the metric's name]
     */
    public function getSelect() {
        
        if ( $this->select) {
            // old style metrics populate this explicitly.
            return $this->select;
        } else {
            $db = \OWA\Core\CoreAPI::dbSingleton();

            /*
             * Initialised, and the switch says so when it does not recognise a type:
             * there is nothing sensible to aggregate with.
             */
            $statement = null;

            switch ( $this->type ) {
                
                case 'count':

                    /*
                     * A CONDITION IS THE SAME SCAN, not a subquery. Most of the
                     * v2 vocabulary is "count the rows that are X" --
                     * pageViews, downloads, transactions, goalEvents
                     * -- and an event table answers that by testing a column on
                     * each row it is already reading (EXPLAIN: select_type=SIMPLE,
                     * Using where; Using index).
                     *
                     * `sum(CASE ...)` rather than a FILTER clause or COUNTIF.
                     * CASE is SQL-92 and renders on every engine; FILTER is
                     * SQL:2003 and absent from MySQL, COUNTIF is BigQuery's and
                     * ClickHouse's. It is also what boolean_true_count below
                     * already does, so this follows the house pattern.
                     */
                    if ( $this->hasCondition() ) {

                        $where = $this->renderCondition();

                        // '' means the condition could not be rendered, and
                        // counting every row instead would be a metric quietly
                        // answering a different question. COALESCE because SUM
                        // over no rows at all is NULL where COUNT is 0: a day
                        // with no visits answered "null" page views beside 0
                        // users.
                        $statement = $where === ''
                            ? null
                            : sprintf( OWA_SQL_COALESCE,
                                  sprintf( 'sum(CASE WHEN %s THEN 1 ELSE 0 END)', $where ), '0' );

                    } else {

                        $statement = $db->count( $this->getColumn() );
                    }
                    break;

                case 'distinct_count':

                    /*
                     * The CASE goes INSIDE the distinct, not around it: rows
                     * failing the test contribute NULL, which a distinct count
                     * ignores. Wrapping the aggregate instead would count the
                     * NULL group as a value.
                     */
                    if ( $this->hasCondition() ) {

                        $where = $this->renderCondition();

                        $statement = $where === ''
                            ? null
                            : $db->count( $db->distinct( sprintf( 'CASE WHEN %s THEN %s END',
                                  $where, $this->getColumn() ) ) );

                    } else {

                        $statement = $db->count( $db->distinct( $this->getColumn() ) );
                    }
                    break;
                
                case 'sum':

                    /*
                     * Conditioned the same way count is, and for the same
                     * reason: summing a column over rows that are not the thing
                     * being measured answers a different question. Revenue sits
                     * on a purchase row, so `sum(revenue)` unconditioned would
                     * be right only for as long as nothing else ever writes to
                     * that column -- which is a property of today's data, not a
                     * statement of what the metric means.
                     *
                     * ELSE 0, not ELSE NULL, matching count: a grouping with no
                     * matching rows earned nothing, and 0 is the answer a report
                     * row wants. The two agree wherever any row matches, so the
                     * choice only shows up on the empty case.
                     *
                     * And COALESCE on the column: a matching row can hold NULL
                     * -- a purchase with no tax -- and SUM over NULL alone is
                     * NULL, which a report would show as a blank cell beside zeros.
                     * And around the whole sum, for a period with no rows at
                     * all, where the ELSE 0 never runs.
                     */
                    if ( $this->hasCondition() ) {

                        $where = $this->renderCondition();

                        // '' means the condition could not be rendered, and
                        // summing every row instead would be a metric quietly
                        // answering a different question.
                        $statement = $where === ''
                            ? null
                            : sprintf( OWA_SQL_COALESCE, sprintf( 'sum(CASE WHEN %s THEN %s ELSE 0 END)',
                                  $where, sprintf( OWA_SQL_COALESCE, $this->getColumn(), '0' ) ), '0' );

                    } else {

                        $statement = sprintf( OWA_SQL_COALESCE, $db->sum( $this->getColumn() ), '0' );
                    }
                    break;

                /*
                 * Named kinds, so a definition can describe an expression
                 * without carrying one. Both render exactly what the classes
                 * they replace rendered, character for character -- the
                 * conversion is meant to move where a metric is declared, not
                 * what it computes.
                 */
                case 'boolean_true_count':

                    /*
                     * NULL counts as 0 here, which merges "false" with "not
                     * recorded" (PLAN 1.12): the three-valued boolean is a 1.x
                     * problem that v2's schema removes.
                     */
                    $statement = sprintf( OWA_SQL_COALESCE, sprintf(
                        'sum(CASE %s WHEN TRUE THEN 1 ELSE 0 END)', $this->getColumn() ), '0' );
                    break;

                case 'avg_difference':

                    /*
                     * Two columns on the same table, subtracted then averaged --
                     * a duration. Qualified with the entity's alias, which makes the
                     * columns unambiguous once the query joins anything.
                     */
                    /* Both columns arrive already qualified by their setters. */
                    $statement = sprintf( 'round(avg(%s - %s))',
                        $this->getColumn(), $this->getSubtrahendColumn() );
                    break;

                default:
                    \OWA\Core\CoreAPI::error( sprintf(
                        'Metric "%s" has aggregation type "%s", which is not one of '
                        . 'count, distinct_count or sum. Its column will be missing '
                        . 'from the query.',
                        (string) $this->getName(), (string) $this->type ) );
            }
            
            return [ $statement, $this->getName() ];
        }
        
    }
    
    /**
     * @return string|null getSelect()'s expression
     */
    public function getSelectWithNoAlias() {
        
        if ( $this->select ) {
            return $this->select[0];
        } else {
            $select = $this->getSelect();
            return $select[0];
        }
    }
    
    /**
     * @param  string $name
     * @return void
     */
    public function setName($name) {
        
        $this->name = $name;
    }
    
    /**
     * @return string|null
     */
    public function getName() {
        
        return $this->name;
    }
    
    /**
     * Sets a metric's column
     *
     * @param  string $col_name a column of the entity, unqualified
     * @param  string $name     the metric it is for; '' for this one
     * @return void
     */
    public function setColumn($col_name, $name = '') {
        
        if (!$name) {
            $name = $this->getName();
        }
        $this->column = $this->entity->getTableAlias().'.'.$col_name;
        $this->all_columns[$name] = $this->column;
        
    }
    
    /**
     * Gets a metric's column name
     *
     * @return string|null qualified; null before setColumn()
     */
    public function getColumn() {
        
        return $this->column;
    }
    
    /**
     * The column subtracted from getColumn() by the avg_difference kind.
     *
     * Its own accessor rather than a second meaning for setColumn(), so a
     * metric that does not use it cannot silently acquire one.
     *
     * @var string|null qualified; null before setSubtrahendColumn()
     */
    public $subtrahend_column;

    /**
     * @param  string $col_name a column of the entity, unqualified
     * @return void
     */
    public function setSubtrahendColumn( $col_name ) {

        /*
         * Qualified here, exactly as setColumn() qualifies the primary column.
         * A report query joins several tables and both session.timestamp and
         * request.timestamp exist, so a bare column name is ambiguous -- but
         * that is the setter's job, not the expression's.
         */
        $this->subtrahend_column = $this->entity->getTableAlias() . '.' . $col_name;
    }

    /**
     * @return string|null
     */
    public function getSubtrahendColumn() {

        return $this->subtrahend_column;
    }

    /**
     * @return string|null see Entity::$name
     */
    public function getEntityName() {
        return $this->entity->getName();
    }
    
    /**
     * Children of a calculated metric. Held here so any metric, ConfigurableMetric
     * included, can be calculated.
     *
     * @var list<string> metric names
     */
    public $child_metrics = [];

    /**
     * @param  string $name a metric name
     * @return void
     */
    public function setChildMetric( $name ) {

        $this->child_metrics[] = $name;
    }

    /**
     * @return list<string> metric names
     */
    public function getChildMetrics() {

        return $this->child_metrics;
    }

    /**
     * @return bool
     */
    public function isCalculated() {
        return $this->is_calculated;
    }
    
    /**
     * Ignored unless it is one of $supported_data_types.
     *
     * @param  string $string
     * @return void
     */
    public function setDataType($string) {
        
        if (in_array($string, $this->supported_data_types)) {
            $this->data_type = $string;
        }
        
    }
    
    /**
     * @return string|null
     */
    public function getDataType() {
        return $this->data_type;
    }
    
    /**
     * Restrict what this metric counts to the rows matching one test.
     *
     * A definition names a column, an operator and a value; it never carries
     * SQL. The same rule as a derived dimension naming a shape (PLAN 2.4), and
     * for the same reason -- a metric carrying an expression has to be rewritten
     * by hand if the reporting store ever changes.
     *
     * @param  MetricCondition $condition ['column' => ..., 'value' => ..., 'operator' => '=']
     * @return void
     */
    public function setCondition( array $condition ) {

        $this->condition = $condition;
    }

    /**
     * The condition as a WHERE test: [qualified column, value, operator], or
     * null when there is none or it cannot be rendered. For a query whose
     * every metric shares it (ResultSetManager::sharedMetricCondition()).
     *
     * @return array{0: string, 1: scalar, 2: string}|null
     */
    public function conditionWhere() {

        if ( ! $this->hasCondition() || $this->renderCondition() === '' ) {

            return null;
        }

        $operator = isset( $this->condition['operator'] ) ? (string) $this->condition['operator'] : '=';

        return [
            $this->qualify( $this->condition['column'] ),
            $this->condition['value'],
            $operator === '<>' ? '!=' : $operator,
        ];
    }

    /** @return bool */
    public function hasCondition() {

        return ! empty( $this->condition['column'] )
            && array_key_exists( 'value', (array) $this->condition );
    }

    /**
     * The condition as SQL.
     *
     * The OPERATOR IS NOT INTERPOLATED. It is matched against a fixed list and
     * the match is what reaches the statement, so a definition cannot put
     * anything else there -- these files are repository-controlled, but a
     * comparison operator is exactly the sort of thing that later gets wired to
     * something that is not.
     *
     * @return string
     */
    protected function renderCondition() {

        $allowed = [ '=', '!=', '<>', '>', '<', '>=', '<=' ];

        $asked = isset( $this->condition['operator'] )
            ? (string) $this->condition['operator'] : '=';

        $operator = in_array( $asked, $allowed, true ) ? $asked : '=';

        if ( $operator !== $asked ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Metric "%s" asked for comparison operator "%s", which is not one of %s. '
              . 'Using "=" instead.',
                (string) $this->getName(), $asked, implode( ' ', $allowed ) ) );
        }

        $literal = $this->conditionLiteral();

        if ( $literal === null ) {

            return '';
        }

        return sprintf( '%s %s %s',
            $this->qualify( $this->condition['column'] ), $operator, $literal );
    }

    /**
     * The condition's value as a SQL literal, or null if it may not be one.
     *
     * VALIDATED, NOT ESCAPED, and the difference is the point.
     *
     * A driver's escaper needs a live connection -- Mysql::prepare() calls
     * mysqli_real_escape_string( $this->connection, ... ) -- so escaping here
     * made a metric's SELECT expression depend on the database being connected.
     * That is wrong on its own terms: the expression is a property of the
     * definition, built once at registration, and CI's unit job has no database
     * at all. It rendered `event_type = ''` there, which is a metric that
     * silently counts nothing.
     *
     * Escaping is also the wrong tool for the input. These values come from a
     * repository-controlled config file, so the risk is a typo that breaks the
     * statement rather than a visitor injecting one. A value that could not be
     * a literal is REFUSED and said out loud, which is a better answer than
     * quietly quoting something unexpected -- and the caller then renders no
     * statement at all, so the metric is missing rather than wrong.
     *
     * @return string|null
     */
    protected function conditionLiteral() {

        $value = $this->condition['value'];

        if ( is_int( $value ) || is_float( $value ) ) {

            return (string) $value;
        }

        if ( is_bool( $value ) ) {

            return $value ? '1' : '0';
        }

        $value = (string) $value;

        // Letters, digits, underscore, dot, hyphen and space: enough for an
        // event type, a state name or a short token, and nothing that can end
        // a quoted literal or start a comment.
        if ( ! preg_match( '/^[A-Za-z0-9_.\- ]*$/', $value ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Metric "%s" has a condition value (%s) that cannot be a SQL literal. '
              . 'Its column will be missing from the query.',
                (string) $this->getName(), $value ) );

            return null;
        }

        return "'" . $value . "'";
    }

    /**
     * A column, qualified by this metric's entity alias.
     *
     * setColumn() does this for the counted column; a condition column needs
     * the same treatment or it is ambiguous the moment the query joins
     * anything.
     *
     * @param  string $column
     * @return string
     */
    protected function qualify( $column ) {

        return $this->entity->getTableAlias() . '.' . $column;
    }

    /**
     * Declare this metric as one number divided by another.
     *
     * WHY THIS EXISTS RATHER THAN A FORMULA. Every calculated metric in this
     * codebase is a division -- six are `a / b` and the seventh is that
     * rounded -- and a formula string costs three things a ratio does not:
     * PHP `eval()` to compute one division; substitution of metric NAMES into
     * an expression, which collides when one name contains another (`actions`
     * is inside `transactions`); and a `child_metrics` list restating what the
     * formula already names, so the two can disagree.
     *
     * A ratio names its two children directly, which makes the child list
     * derivable rather than declared, removes the substitution entirely, and
     * lets the division be rendered in SQL for sorting and done in PHP for the
     * value -- the same split a formula gets, without the string handling.
     *
     * @param string   $numerator   a metric name
     * @param string   $denominator a metric name
     * @param int|null $precision   decimal places
     * @return void
     */
    public function setRatio( $numerator, $denominator, $precision = null ) {

        $this->numerator   = (string) $numerator;
        $this->denominator = (string) $denominator;
        $this->precision   = $precision === null ? null : (int) $precision;

        /*
         * The children ARE the two sides, so they are recorded where every
         * other reader already looks for them. Nothing else in the manager has
         * to learn what a ratio is: it resolves children, excludes them from
         * the output and reduces entities exactly as it does for a difference.
         */
        $this->setChildMetric( $this->numerator );
        $this->setChildMetric( $this->denominator );
    }

    /** @return bool */
    public function isRatio() {

        return $this->numerator !== '' && $this->denominator !== '';
    }

    /** @return string */
    public function getNumerator() {

        return $this->numerator;
    }

    /** @return string */
    public function getDenominator() {

        return $this->denominator;
    }

    /** @return int|null */
    public function getPrecision() {

        return $this->precision;
    }

    /**
     * A ratio of two already-computed numbers.
     *
     * 0 WHEN THE DENOMINATOR IS ZERO OR MISSING: a period, interval or row with
     * no sessions has a pages per session, engagement rate and conversion rate
     * of 0, as a trend's empty interval does.
     *
     * @param  int|float|null $numerator
     * @param  int|float|null $denominator
     * @return float|int
     */
    public function computeRatio( $numerator, $denominator ) {

        if ( ! is_numeric( $denominator ) || (float) $denominator == 0.0 ) {

            return 0;
        }

        $value = (float) $numerator / (float) $denominator;

        return $this->precision === null ? $value : round( $value, $this->precision );
    }

    /**
     * Declare this metric as one number less another -- net revenue is revenue
     * less refunds. The children are the two sides, recorded where every reader
     * already looks, exactly as a ratio's are.
     *
     * @param string $minuend    a metric name
     * @param string $subtrahend a metric name
     * @return void
     */
    public function setDifference( $minuend, $subtrahend ) {

        $this->minuend    = (string) $minuend;
        $this->subtrahend = (string) $subtrahend;

        $this->setChildMetric( $this->minuend );
        $this->setChildMetric( $this->subtrahend );
    }

    /** @return bool */
    public function isDifference() {

        return $this->minuend !== '' && $this->subtrahend !== '';
    }

    /** @return string */
    public function getMinuend() {

        return $this->minuend;
    }

    /** @return string */
    public function getSubtrahend() {

        return $this->subtrahend;
    }

    /**
     * One already-computed number less another.
     *
     * A side with no value counts as zero -- revenue with no refunds is the
     * revenue -- but with neither side there is nothing to report.
     *
     * @param  mixed $minuend    a computed value as the result set holds it, any type; not numeric counts as none
     * @param  mixed $subtrahend the same
     * @return float|int|null
     */
    public function computeDifference( $minuend, $subtrahend ) {

        $a = is_numeric( $minuend ) ? $minuend + 0 : null;
        $b = is_numeric( $subtrahend ) ? $subtrahend + 0 : null;

        if ( $a === null && $b === null ) {

            return null;
        }

        return ( $a === null ? 0 : $a ) - ( $b === null ? 0 : $b );
    }

    /**
     * @param  string $type see $type
     * @return void
     */
    public function setMetricType( $type ) {
        $this->type = $type;

        /*
         * A ratio IS a calculated metric: it is computed from other metrics
         * rather than aggregated from a column, which is what everything else
         * in the manager keys off. Saying so here means entity reduction,
         * child resolution and the cleanup that removes children from the
         * output all work unchanged.
         */
        if ( $type === 'calculated' || $type === 'ratio' || $type === 'difference' ) {
             $this->is_calculated = true;
        }
    }
}
