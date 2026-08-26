<?php
namespace OWA\Module\Base\Classes;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//


/**
 * Custom reports: storing them, listing them, and deciding what may be stored.
 *
 * WHY THIS CAN ACCEPT USER INPUT AT ALL
 *
 * A custom report is a report DEFINITION -- the same JSON a shipped report in
 * modules/Base/reports/ holds -- and it renders through the same
 * Core\ConfiguredReport. The format was narrowed over the conversion so that it
 * could one day come from a user, and each narrowing is load-bearing here:
 *
 *   - The renderer is FIXED (ConfiguredReport::SUBVIEW), not named by the
 *     definition. A definition that named its own view could point at any view
 *     in the tree.
 *   - Formatters are selected by NAME from a known list, never carried as code.
 *   - excludeColumns is a list of names, not a fragment of script.
 *
 * None of those may be relaxed while definitions are user-authored.
 *
 * WHAT THIS ADDS ON TOP
 *
 * getDefinitionError() already refuses an unknown top-level key and a missing
 * title. That is necessary and not sufficient for user input, because it says
 * nothing about whether the NAMES inside resolve. So validate() below also
 * requires that:
 *
 *   - there are at most MAX_WIDGETS widgets;
 *   - every widget names one of the types a person can actually build;
 *   - every metric, dimension and sort name resolves through the registry.
 *
 * That last one is the REST-to-SQL invariant: a name that reaches the query
 * builder without resolving through the registry is a name the reader chose
 * appearing in SQL. Refusing at SAVE time is the belt to the query layer's
 * braces -- ResultSetManager refuses an unknown constraint at query time too --
 * and it means the author is told at the moment they can still fix it, rather
 * than being handed a report that is broken every time it is opened.
 *
 * @since owa 1.8.0
 */
class CustomReports {

    /**
     * How many widgets one custom report may carry.
     *
     * A limit rather than an opinion about layout: every widget is at least one
     * query, so a report is a multiplier on database work and this bounds it.
     */
    const MAX_WIDGETS = 10;

    /**
     * How many metrics one query may ask for.
     *
     * A limit on READABILITY as much as on cost. A metric-boxes widget draws a
     * box per metric and a trend draws a box per metric under its chart, so
     * beyond about four they stop fitting a row and the widget reads as a wall
     * of numbers. Every metric is also another aggregate in the query.
     */
    const MAX_METRICS = 4;

    /**
     * How many dimensions one query may group by.
     *
     * Same number, different reason. Each dimension is another GROUP BY column
     * and another join, so rows multiply with every one added -- and a grid
     * grouped six ways is a list of near-unique rows, which answers nothing a
     * reader came for.
     */
    const MAX_DIMENSIONS = 4;

    /**
     * The widget types the builder offers.
     *
     * A SUBSET of what the renderer can draw, and deliberately so. The others
     * exist to serve one shipped report each -- report-links needs a list of
     * report ids, heatmap-link needs a document, the badge widgets need a
     * particular dimension in a particular column -- so offering them would be
     * offering configurations that mostly cannot be made to work.
     *
     * Keys are what a definition says; values are what a person is shown.
     */
    const WIDGET_TYPES = array(
        'grid'          => 'Table',
        'pie'           => 'Pie chart',
        'trend'         => 'Trend chart',
        'metric-boxes'  => 'Info boxes',
    );

    /** Roster ordering: most recently changed first. */
    const ROSTER_LIMIT = 500;

    /**
     * What the roster may be ordered by, and the column each one means.
     *
     * An ALLOWLIST, because the sort arrives from a URL and reaches an ORDER BY
     * -- which cannot be a bound parameter. Mapping a known key to a known
     * column is what keeps a reader's choice from being SQL, the same rule the
     * reporting stack applies to every dimension and metric name.
     */
    const ROSTER_SORTS = array(
        'name'    => 'name',
        'author'  => 'user_id',
        'updated' => 'last_updated_timestamp',
    );

    /** Ordered by when it changed, newest first: the useful default for a list. */
    const ROSTER_DEFAULT_SORT = 'updated';

    /**
     * Why this definition may not be stored, or '' if it may.
     *
     * Static, and returning a string rather than throwing, so the builder can
     * put the reason next to the field that caused it and a test can ask the
     * same question without a request.
     *
     * @param mixed $definition a decoded definition
     * @return string
     */
    public static function validate( $definition ) {

        // Everything the renderer already refuses: a missing title, an unknown
        // top-level key, a malformed widget list, an unknown formatter.
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( $definition );

        if ( $error !== '' ) {

            return $error;
        }

        $widgets = isset( $definition['widgets'] ) ? (array) $definition['widgets'] : array();

        if ( ! $widgets ) {

            return 'a report needs at least one widget';
        }

        if ( count( $widgets ) > self::MAX_WIDGETS ) {

            return sprintf( 'a report may have at most %d widgets; this one has %d',
                self::MAX_WIDGETS, count( $widgets ) );
        }

        foreach ( $widgets as $i => $widget ) {

            $where = sprintf( 'widget %d', $i + 1 );

            if ( ! is_array( $widget ) ) {

                return $where . ' is not an object';
            }

            $type = isset( $widget['type'] ) ? (string) $widget['type'] : '';

            if ( ! isset( self::WIDGET_TYPES[ $type ] ) ) {

                return sprintf( '%s names widget type "%s", which is not one that can be built. Choose one of: %s',
                    $where, $type, implode( ', ', array_keys( self::WIDGET_TYPES ) ) );
            }

            $error = self::validateQuery( $widget, $where );

            if ( $error !== '' ) {

                return $error;
            }
        }

        /*
         * The report-level metric set: the metrics the report offers as its own
         * tab group, independent of any one widget. Same registry check -- an
         * unresolvable name here takes down every widget rather than one.
         */
        $error = self::validateNames( $definition, 'metrics', 'metric', 'the report metric set' );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateFieldCount(
            isset( $definition['metrics'] ) ? $definition['metrics'] : '',
            self::MAX_METRICS, 'metrics', 'the report metric set',
            'the boxes stop fitting a row and the numbers stop being readable' );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateCombination(
            isset( $definition['metrics'] ) ? $definition['metrics'] : '',
            '', '', 'the report metric set' );

        if ( $error !== '' ) {

            return $error;
        }

        return '';
    }

    /**
     * The names inside one widget's query.
     *
     * @param array  $widget
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateQuery( array $widget, $where ) {

        $query = isset( $widget['query'] ) ? (array) $widget['query'] : array();

        foreach ( array(
            array( 'metrics',    'metric' ),
            array( 'dimensions', 'dimension' ),
        ) as $pair ) {

            list( $key, $kind ) = $pair;

            $error = self::validateNames( $query, $key, $kind, $where );

            if ( $error !== '' ) {

                return $error;
            }
        }

        $error = self::validateFieldCount(
            isset( $query['metrics'] ) ? $query['metrics'] : '',
            self::MAX_METRICS, 'metrics', $where,
            'the boxes stop fitting a row and the numbers stop being readable' );

        if ( $error !== '' ) {

            return $error;
        }

        $error = self::validateFieldCount(
            isset( $query['dimensions'] ) ? $query['dimensions'] : '',
            self::MAX_DIMENSIONS, 'dimensions', $where,
            'every dimension multiplies the rows, and a grid grouped that many ways '
          . 'is a list of near-unique rows' );

        if ( $error !== '' ) {

            return $error;
        }

        /*
         * ...and everything asked for has to be ASKABLE TOGETHER.
         *
         * Metrics, dimensions AND the dimensions named by the widget's
         * constraints: a query is answered from one fact table, and that table
         * has to serve all three. Checked after the names resolve, because a
         * combination check on a name that does not exist would report the
         * wrong problem.
         */
        $error = self::validateCombination(
            isset( $query['metrics'] ) ? $query['metrics'] : '',
            isset( $query['dimensions'] ) ? $query['dimensions'] : '',
            isset( $widget['constraints'] ) ? $widget['constraints'] : '',
            $where );

        if ( $error !== '' ) {

            return $error;
        }

        /*
         * Sorts carry a direction as a trailing '-', which is not part of the
         * name. Stripping it here rather than validating the whole token is
         * what lets `visits-` resolve as `visits`; the direction itself never
         * reaches SQL as text -- sortStringToArray turns it into the literal
         * ASC or DESC.
         */
        if ( ! empty( $query['sort'] ) ) {

            foreach ( explode( ',', (string) $query['sort'] ) as $sort ) {

                $name = rtrim( trim( $sort ), '-' );

                if ( $name === '' ) {

                    continue;
                }

                if ( ! self::isKnownName( $name ) ) {

                    return sprintf( '%s sorts on "%s", which is not a metric or a dimension', $where, $name );
                }
            }
        }

        return '';
    }

    /**
     * Every comma-separated name under $key must resolve as $kind.
     *
     * @param array  $bag
     * @param string $key
     * @param string $kind metric|dimension
     * @param string $where
     * @return string
     */
    private static function validateNames( array $bag, $key, $kind, $where ) {

        if ( empty( $bag[ $key ] ) ) {

            return '';
        }

        $names = is_array( $bag[ $key ] ) ? $bag[ $key ] : explode( ',', (string) $bag[ $key ] );

        foreach ( $names as $name ) {

            $name = trim( (string) $name );

            if ( $name === '' ) {

                continue;
            }

            $known = $kind === 'metric' ? self::isMetric( $name ) : self::isDimension( $name );

            if ( ! $known ) {

                return sprintf( '%s names "%s", which is not a known %s', $where, $name, $kind );
            }
        }

        return '';
    }

    /**
     * Whether these metrics can be asked for in one query.
     *
     * Every metric is computed from one or more FACT TABLES, and a query is
     * answered from one of them -- so a set is only askable if its metrics
     * share a table. `domClicks` is measured in the click table alone and
     * `visits` in the session or the request; no table holds both, so asking
     * for them together is not a thin result, it is not a question.
     *
     * The answer comes from ResultSetManager, which performs exactly this
     * reduction when it chooses a base entity. Asking IT rather than keeping a
     * list here is what stops the two drifting: a metric registered tomorrow is
     * covered without anything being added.
     *
     * @param string|array $metrics
     * @param string|array $dimensions
     * @param string $constraints the widget's constraint string, if any
     * @param string $where human-readable position, for the message
     * @return string
     */
    private static function validateCombination( $metrics, $dimensions = '', $constraints = '', $where = '' ) {

        $names = self::asNames( $metrics );
        $dims  = self::asNames( $dimensions );

        /*
         * Constraints contribute their DIMENSIONS to the same reduction -- the
         * engine folds them in with getDimensionsFromConstraints(), so a
         * constraint on a field the fact table does not carry breaks a query
         * exactly as a dimension does.
         */
        foreach ( self::constraintDimensions( $constraints ) as $name ) {

            $dims[] = $name;
        }

        // Nothing to reconcile: one field is always askable on its own.
        if ( count( $names ) + count( $dims ) < 2 ) {

            return '';
        }

        if ( ! $names ) {

            /*
             * Dimensions alone cannot be reduced here: the entity list starts
             * from the METRICS, and a widget with none inherits the report's.
             * Left to the query, which checks the pair it actually runs with.
             */
            return '';
        }

        $rsm = new ResultSetManager;

        $clash = $rsm->firstIncompatible( $names, $dims );

        if ( ! $clash ) {

            return '';
        }

        /*
         * The message NAMES BOTH SIDES. Listing everything asked for tells the
         * author nothing they can act on; naming the field that broke the set
         * and what it clashes with tells them which one to remove.
         */
        return $where . ': ' . ResultSetManager::incompatibleMessage(
            $clash['name'], $clash['with'], $clash['kind'] );
    }

    /**
     * At most $max of one kind of field in one query.
     *
     * @param string|array $fields
     * @param int    $max
     * @param string $kind   metrics|dimensions, for the message
     * @param string $where
     * @param string $why    what goes wrong past the limit
     * @return string
     */
    private static function validateFieldCount( $fields, $max, $kind, $where, $why ) {

        $names = self::asNames( $fields );

        if ( count( $names ) <= $max ) {

            return '';
        }

        return sprintf(
            '%s asks for %d %s; %d is the most one widget can carry. Beyond that %s -- '
          . 'split them across widgets instead.',
            $where, count( $names ), $kind, $max, $why );
    }

    /** A comma string or list, as trimmed non-empty names. */
    private static function asNames( $value ) {

        $names = is_array( $value ) ? $value : explode( ',', (string) $value );

        return array_values( array_filter( array_map( 'trim', $names ) ) );
    }

    /**
     * The dimension names a constraint string mentions.
     *
     * A constraint is `name==value`, comma separated. Only the NAME matters
     * here, and only when it is a dimension -- a constraint on a metric is a
     * having clause and does not decide the fact table.
     *
     * @param string $constraints
     * @return array
     */
    private static function constraintDimensions( $constraints ) {

        $out = array();

        foreach ( self::asNames( $constraints ) as $clause ) {

            // Split on the first operator character; the operators are
            // ==, !=, >, <, >=, <=, =~ and so on, all starting with one of these.
            $name = preg_split( '/[=!<>~]/', $clause, 2 )[0];
            $name = trim( (string) $name );

            if ( $name !== '' && self::isDimension( $name ) ) {

                $out[] = $name;
            }
        }

        return $out;
    }

    /** Whether the name resolves as either kind -- what a sort may be. */
    private static function isKnownName( $name ) {

        return self::isMetric( $name ) || self::isDimension( $name );
    }

    public static function isMetric( $name ) {

        $metrics = (array) \OWA\Core\CoreAPI::getAllMetrics();

        return array_key_exists( $name, $metrics );
    }

    public static function isDimension( $name ) {

        $dimensions = (array) \OWA\Core\CoreAPI::getAllDimensions();

        return array_key_exists( $name, $dimensions );
    }

    /**
     * One stored report, or null.
     *
     * Returns the ROW, decoded, rather than an entity: every caller wants the
     * definition as an array and the name as a string, and handing back an
     * entity would make each of them remember to decode.
     *
     * @param string $id
     * @return array|null {id, name, user_id, definition, ...}
     */
    public static function load( $id ) {

        if ( (string) $id === '' ) {

            return null;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $entity->load( $id );

        if ( ! $entity->wasPersisted() ) {

            return null;
        }

        return self::hydrate( $entity->_getProperties() );
    }

    /**
     * The reports a user may see listed.
     *
     * Ownership governs the ROSTER, not viewing: an admin sees every report, a
     * non-admin sees the ones they created. A report reached by its URL renders
     * for anyone with view_reports, which is what makes the URL shareable --
     * and is safe because a custom report can show nothing its reader could not
     * already query for themselves.
     *
     * @param string $user_id
     * @param bool   $all     true for a user who may see everyone's
     * @return array
     */
    public static function roster( $user_id, $all = false, $sort = '', $descending = null ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $sql    = 'SELECT * FROM owa_custom_report';
        $params = array();

        if ( ! $all ) {

            $sql     .= ' WHERE user_id = ?';
            $params[] = (string) $user_id;
        }

        /*
         * The column comes from the allowlist, never from the request. An
         * unknown key falls back to the default rather than erroring: a sort is
         * a way of looking at a list, and a bad one should not take the list
         * away.
         */
        $key = isset( self::ROSTER_SORTS[ $sort ] ) ? $sort : self::ROSTER_DEFAULT_SORT;

        /*
         * Direction defaults to what each column is usually wanted in: a date
         * newest-first, a name A-to-Z. Asking for "updated" and getting the
         * oldest first is right once and wrong every other time.
         */
        if ( $descending === null ) {

            $descending = ( $key === 'updated' );
        }

        // A literal, not a parameter: ASC and DESC are the only two values this
        // can ever be, chosen here rather than passed through.
        $sql .= ' ORDER BY ' . self::ROSTER_SORTS[ $key ]
              . ( $descending ? ' DESC' : ' ASC' )
              . ' LIMIT ' . (int) self::ROSTER_LIMIT;

        // get_results(), not query()->fetchAll(): query() returns the DRIVER's
        // own result and only PDO's has fetchAll(). Null for both no rows and a
        // failed query.
        $rows = $db->get_results( $sql, $params );

        if ( $rows === null ) {

            return array();
        }

        return array_map( array( __CLASS__, 'hydrate' ), $rows );
    }

    /** A stored row, with its definition decoded. */
    private static function hydrate( $row ) {

        $row = (array) $row;

        $raw = isset( $row['definition'] ) ? (string) $row['definition'] : '';

        /*
         * Stored strings are entity-encoded on write, so a definition comes
         * back with its quotes as &quot; and does not parse until they are
         * turned back. Decoding here means no caller has to know that.
         */
        $decoded = json_decode( html_entity_decode( $raw, ENT_QUOTES ), true );

        $row['definition'] = is_array( $decoded ) ? $decoded : array();

        return $row;
    }

    /**
     * Store a report, creating or replacing.
     *
     * Returns the id on success, or a string error. The caller decides how to
     * show the error; this decides whether there is one.
     *
     * @param array  $fields {id, name, definition}
     * @param string $user_id the author, for a new report
     * @return array {ok:bool, id:string, error:string}
     */
    public static function save( array $fields, $user_id ) {

        $name = trim( (string) ( $fields['name'] ?? '' ) );

        if ( $name === '' ) {

            return self::failure( 'a report needs a name' );
        }

        $definition = $fields['definition'] ?? array();

        if ( is_string( $definition ) ) {

            $decoded = json_decode( $definition, true );

            if ( ! is_array( $decoded ) ) {

                return self::failure( 'the report definition is not valid JSON: ' . json_last_error_msg() );
            }

            $definition = $decoded;
        }

        /*
         * The name the author typed is the title the report renders. Set here
         * rather than asked for twice: two fields that must agree is a way for
         * the roster and the report heading to disagree.
         */
        $definition['title'] = $name;

        $error = self::validate( $definition );

        if ( $error !== '' ) {

            return self::failure( $error );
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        $id  = (string) ( $fields['id'] ?? '' );
        $now = time();

        if ( $id !== '' ) {

            $entity->load( $id );

            if ( ! $entity->wasPersisted() ) {

                return self::failure( 'that report no longer exists' );
            }
        }

        $entity->set( 'name', $name );
        $entity->set( 'definition', json_encode( $definition ) );
        $entity->set( 'last_updated_timestamp', $now );

        if ( $id === '' ) {

            $id = (string) \OWA\Core\Lib::generateRandomUid();

            $entity->set( 'id', $id );
            $entity->set( 'user_id', (string) $user_id );
            $entity->set( 'creation_timestamp', $now );
            $entity->create();

        } else {

            // user_id is NOT reassigned on edit. The creator is a fact about
            // the report, and an admin editing someone's report must not
            // silently become its author on the roster.
            $entity->update();
        }

        return array( 'ok' => true, 'id' => $id, 'error' => '' );
    }

    /**
     * @param string $id
     * @return bool
     */
    public static function delete( $id ) {

        if ( (string) $id === '' ) {

            return false;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $entity->load( $id );

        if ( ! $entity->wasPersisted() ) {

            return false;
        }

        $entity->delete( $id );

        return true;
    }

    /**
     * Whether this user may edit this report.
     *
     * Editing is narrower than viewing: the creator, or anyone who may see
     * every report. Checked against the ROW rather than re-read, so a caller
     * cannot check one report and act on another.
     *
     * @param array|null $report
     * @param string     $user_id
     * @param bool       $all
     * @return bool
     */
    public static function mayEdit( $report, $user_id, $all = false ) {

        if ( ! $report ) {

            return false;
        }

        if ( $all ) {

            return true;
        }

        return (string) ( $report['user_id'] ?? '' ) === (string) $user_id
            && (string) $user_id !== '';
    }

    /** @return array */
    private static function failure( $message ) {

        return array( 'ok' => false, 'id' => '', 'error' => $message );
    }
}
