<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Builds one Property's reporting cube: raw events in one partition, enriched
 * into owa_event_<property id>.
 *
 * ONE BUILDER, ONE PROPERTY. Raw is shared and the cubes are not (Cubes), so a
 * builder is constructed with the Property whose cube it writes and restricts
 * every read of raw to that Property's profiles. Its staging and computed
 * tables are named after its target, so two Properties build concurrently
 * without contending for anything.
 *
 * One statement and a swap. The statement builds every row of the partition
 * into a staging table; the swap exchanges that table with the live partition.
 * It creates nothing, deletes nothing and updates nothing in place, so rows in
 * equals rows out and a mismatch is a bug -- checked below, before the swap.
 *
 * THE COLUMN LIST IS THE MAP. Every column past raw's has exactly one
 * registered Step, and this class composes their SQL into one statement. Zero
 * steps for a column is a fatal, and so is two. The previous shape appended
 * sixteen expressions in an order that had to match the entity's declaration
 * order by hand -- reorder the entity and the right values land in the wrong
 * columns, same types, no error.
 *
 * JOINS ARE DEMAND-DRIVEN. The windowed session subquery, the visitor store and
 * the computed side table are emitted only if some registered step asks for
 * them, so an install registering no compute steps pays for no candidate query.
 *
 * WHAT PHP MAY DO. Compute, not stream. Every compute step contributes to ONE
 * shared candidate query and ONE side table, whatever the number registered --
 * the rule that keeps "stackable" from meaning "one scan each". The write shape
 * is what the guarantees rest on, not the language: one bulk build, one swap,
 * no row-at-a-time writes.
 *
 * CONVERGENT. Running a build twice on a partition produces the same partition,
 * so the level-triggered scheduler needs no bookkeeping. That is why a step
 * failing aborts the run rather than being skipped: a build that sometimes
 * includes a column is not convergent.
 */
class Builder {

    /**
     * Days of raw read before the partition starts.
     *
     * Covers a session straddling the boundary. A session is bounded by the
     * idle timeout and split at midnight, so one day is more slack than it
     * needs; the cost is one extra partition read.
     */
    const LOOKBACK_DAYS = 1;

    /** Suffix of the staging table, built and dropped around each swap. */
    const STAGING_SUFFIX = '_rebuild';

    /** Suffix of the side table the compute steps write. */
    const COMPUTED_SUFFIX = '_computed';

    /**
     * Candidate rows a build will hand to PHP before it refuses.
     *
     * Exceeding it FAILS the build rather than skipping the enrichment:
     * degrading would make two builds of one partition disagree, and
     * convergence is what the swap rests on.
     */
    const CANDIDATE_CAP = 50000;

    /** @var \OWA\Core\Db */
    protected $db;

    /** @var array table name by role */
    protected $tables = array();

    /** @var Step[] keyed by column */
    protected $steps = array();

    /** @var array per-step accounting from the last build */
    protected $accounting = array();

    /** @var Columns */
    protected $columns;

    /** @var string the Property this build is for */
    protected $property_id;

    /** @var string[] the site ids whose raw rows belong in it */
    protected $site_ids;

    /**
     * @param int|string $property_id  whose cube this builds
     */
    function __construct( $property_id ) {

        $this->db          = \OWA\Core\CoreAPI::dbSingleton();
        $this->property_id = (string) $property_id;
        $this->site_ids    = Cubes::siteIds( $this->property_id );

        $this->tables['target'] = Cubes::tableFor( $this->property_id );

        foreach ( array(
            'raw'      => 'base.event_raw',
            'visitors' => 'base.visitor_acquisition',
        ) as $role => $entity ) {

            $this->tables[ $role ] =
                \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName();
        }

        $this->tables['staging']  = $this->tables['target'] . self::STAGING_SUFFIX;
        $this->tables['computed'] = $this->tables['target'] . self::COMPUTED_SUFFIX;

        $this->columns = new Columns();
        $this->steps   = $this->columns->steps( $this->derivedColumns() );

        /*
         * And the registered custom dimensions, which are columns of the TABLE
         * rather than of the entity: they are this Property's, added by a
         * registration rather than by a release, so the entity cannot know
         * them. They arrive as ordinary Steps so the assembler needs no case
         * for them and the joins stay demand-driven.
         */
        foreach ( $this->dimensionSteps() as $column => $step ) {

            $this->steps[ $column ] = $step;
        }
    }

    /**
     * A Step per column this Property's registered dimensions own.
     *
     * @return Step[] keyed by column
     */
    protected function dimensionSteps() {

        $steps = array();

        /*
         * THE TABLE IS THE AUTHORITY, NOT THE REGISTRY.
         *
         * A registration is recorded immediately and its column arrives at the
         * next reconcile (Dimensions::reconcile), so a row can name a column
         * that is not there yet -- and one whose ALTER was refused never gets
         * one. Naming it in the INSERT regardless would fail every build with
         * "Unknown column in field list", which is a Property's whole cube
         * stopping because somebody registered one dimension too many.
         *
         * So the registry says what to fill and this says what exists, and
         * only the intersection is built. A pending dimension is simply not
         * filled yet.
         */
        $present = array_flip( Dimensions::registeredColumnsOn( $this->tables['target'] ) );

        foreach ( Dimensions::forProperty( $this->property_id ) as $row ) {

            $column = (string) $row['column_name'];
            $key    = (string) $row['dimension_key'];

            /*
             * The path needs no quoting because a key had to match the
             * tracker's own name pattern to be registered at all. That is the
             * whole reason the pattern is enforced there: a registration taking
             * arbitrary keys would have to build a JSON path out of user text
             * and then put it inside a SQL string literal, escaped twice.
             */
            if ( ! preg_match( Dimensions::KEY_PATTERN, $key ) || ! isset( $present[ $column ] ) ) {

                continue;
            }

            if ( (string) $row['scope'] === \OWA\Module\Base\Entity\CustomDimension::SCOPE_USER ) {

                $steps[ $column ] = new JsonStep( $column,
                    Context::VISITOR . '.properties', '$.' . $key . '.v',
                    (string) $row['data_type'], (int) $row['max_length'], Context::VISITOR );

                $ts = $column . \OWA\Module\Base\Entity\CustomDimension::SET_TS_SUFFIX;

                // Both columns go on in one ALTER, so one without the other
                // means a half-applied reconcile; fill what is there.
                if ( isset( $present[ $ts ] ) ) {

                    $steps[ $ts ] = new SetTimeStep( $ts,
                        Context::VISITOR . '.properties', '$.' . $key . '.ts' );
                }

                continue;
            }

            // Event scope reads the raw row itself, so it costs no join.
            $steps[ $column ] = new JsonStep( $column,
                Context::RAW . '.params', '$.' . $key,
                (string) $row['data_type'], (int) $row['max_length'] );
        }

        return $steps;
    }

    /** @return string the table this build writes */
    public function table() {

        return $this->tables['target'];
    }

    /**
     * The predicate restricting raw to this Property's rows.
     *
     * Raw is shared by every Property, so this is what makes one build's
     * partition its own. It goes on EVERY read of raw the build makes -- the
     * insert, the window, the candidate query, the row count and the last_seen
     * update -- because they have to agree about which rows exist: the build
     * refuses to swap when rows in does not equal rows out, so a filter on one
     * side and not the other would fail every build rather than mis-fill one.
     *
     * A PROPERTY WITH NO PROFILES MATCHES NOTHING, rather than everything. That
     * is a real state -- a cube whose profiles were all deleted or moved -- and
     * an unfiltered build would hand it every other Property's rows.
     *
     * @param string $alias
     * @return string  SQL, beginning with AND
     */
    protected function siteFilter( $alias ) {

        if ( ! $this->site_ids ) {

            return ' AND 1 = 0';
        }

        $quoted = array();

        foreach ( $this->site_ids as $site_id ) {

            $quoted[] = "'" . $this->db->prepare( $site_id ) . "'";
        }

        return sprintf( ' AND %s.site_id IN (%s)', $alias, implode( ', ', $quoted ) );
    }

    /**
     * The partitions of the cube covering a date range, oldest first.
     *
     * A partition is the unit of work because the swap is. The catch-all is
     * never returned: exchanging into it would put rows in a partition that
     * does not describe them.
     *
     * @param int $from yyyymmdd
     * @param int $to   yyyymmdd, inclusive
     * @return array of ['name','start','less_than']
     */
    public function partitions( $from, $to ) {

        $spans = array();

        foreach ( $this->db->getPartitionSpans( $this->tables['target'] ) as $span ) {

            if ( (int) $span['less_than'] > (int) $from && (int) $span['start'] <= (int) $to ) {

                $spans[] = $span;
            }
        }

        return $spans;
    }

    /**
     * What each step did on the last build.
     *
     * @return array of ['step','column','kind','computed','ok','error']
     */
    public function accounting() {

        return $this->accounting;
    }

    /**
     * Build one partition and swap it in.
     *
     * @param array $span    from partitions()
     * @param bool  $dry_run compose the statement, run nothing
     * @return array ['ok','partition','rows','sql','steps','computed','failed']
     */
    public function rebuild( array $span, $dry_run = false ) {

        $built_at = (int) round( microtime( true ) * 1000000 );

        $result = array(
            'ok'        => false,
            'partition' => $span['name'],
            'rows'      => 0,
            'sql'       => '',
            'steps'     => count( $this->steps ),
            'computed'  => 0,
            'failed'    => 0,
        );

        $context = new Context( $span, $built_at, $this->closedBefore( $built_at ) );

        try {

            $context->candidates = $dry_run ? array() : $this->candidates( $span );

            $expressions = $this->runSteps( $context );

        } catch ( \RuntimeException $e ) {

            $result['failed'] = $this->countFailed();

            \OWA\Core\CoreAPI::error( sprintf(
                'Cube build: %s. %s is unchanged.', $e->getMessage(), $this->tables['target'] ) );

            return $result;
        }

        $result['computed'] = $this->countComputed();
        $result['sql']      = $this->statement( $span, $expressions );

        if ( $dry_run ) {

            $result['ok'] = true;

            return $result;
        }

        if ( ! $this->makeStaging() ) {

            return $result;
        }

        if ( ! $this->writeComputed( $context ) ) {

            $this->dropWorkingTables();

            return $result;
        }

        if ( $this->db->query( $result['sql'] ) === false ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Cube build: building %s failed; %s is unchanged.',
                $span['name'], $this->tables['target'] ) );

            $this->dropWorkingTables();

            return $result;
        }

        $expected = $this->countRaw( $span );
        $built    = $this->count( $this->tables['staging'], '' );

        /*
         * A build enriches; it does not create or drop. A mismatch means the
         * statement is wrong -- a join multiplying rows, a predicate excluding
         * them -- and swapping it in would publish that.
         */
        if ( $built !== $expected ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Cube build: %s built %d rows from %d raw rows. Not swapped.',
                $span['name'], $built, $expected ) );

            $this->dropWorkingTables();

            return $result;
        }

        if ( ! $this->db->exchangePartition(
                $this->tables['target'], $span['name'], $this->tables['staging'] ) ) {

            /*
             * The likely cause is worth naming, because the message is all
             * anyone gets: a column added to this table instantly -- MySQL 8's
             * default -- leaves row-format metadata that the staging table,
             * built by CREATE TABLE LIKE, cannot have.
             */
            \OWA\Core\CoreAPI::error( sprintf(
                'Cube build: exchanging %s failed; %s is unchanged. If the server '
              . 'reported 1731, run ALTER TABLE %s FORCE -- a column was added to it '
              . 'instantly and staging cannot match that.',
                $span['name'], $this->tables['target'], $this->tables['target'] ) );

            $this->dropWorkingTables();

            return $result;
        }

        // Staging now holds the partition's previous contents, swapped out.
        $this->dropWorkingTables();

        $this->advanceLastSeen( $span );

        $result['ok']   = true;
        $result['rows'] = $built;

        return $result;
    }

    /**
     * Run every step, recording what each one did.
     *
     * A step that throws aborts the build. It is not skipped: a build that
     * sometimes fills a column is not convergent, and the swap's whole
     * guarantee is that running it twice produces the same partition.
     *
     * @param Context $context
     * @return array column => SQL expression
     * @throws \RuntimeException
     */
    protected function runSteps( Context $context ) {

        $this->accounting = array();

        $expressions = array();

        foreach ( $this->steps as $column => $step ) {

            $compute = $step instanceof ComputeStep;

            $entry = array(
                'step'     => $step->name(),
                'column'   => $column,
                'kind'     => $compute ? 'compute' : 'sql',
                'computed' => 0,
                'msec'     => 0.0,
                'ok'       => false,
                'error'    => '',
            );

            /*
             * Timed because a compute step is the one place a build spends time
             * outside SQL, and the one place a slow callback would be invisible
             * -- it would just look like the build got slower.
             */
            $started = microtime( true );

            try {

                $expressions[ $column ] = $step->execute( $context );

                $entry['msec']     = round( ( microtime( true ) - $started ) * 1000, 2 );
                $entry['computed'] = $compute ? count( $step->values() ) : 0;
                $entry['ok']       = true;

            } catch ( \Exception $e ) {

                $entry['msec']      = round( ( microtime( true ) - $started ) * 1000, 2 );
                $entry['error']     = $e->getMessage();
                $this->accounting[] = $entry;

                throw new \RuntimeException( sprintf(
                    'step %s failed: %s', $step->name(), $e->getMessage() ) );
            }

            $this->accounting[] = $entry;
        }

        return $expressions;
    }

    /** @return int */
    protected function countComputed() {

        $n = 0;

        foreach ( $this->accounting as $entry ) {

            $n += (int) $entry['computed'];
        }

        return $n;
    }

    /** @return int */
    protected function countFailed() {

        $n = 0;

        foreach ( $this->accounting as $entry ) {

            $n += $entry['ok'] ? 0 : 1;
        }

        return $n;
    }

    /**
     * The one candidate query every compute step shares.
     *
     * Projects the union of their reads, and ORs their rendered predicates. One
     * scan per build however many are registered, and none at all when none is.
     *
     * @param array $span
     * @return array
     * @throws \RuntimeException past the cap
     */
    protected function candidates( array $span ) {

        $reads = array();
        $where = array();

        foreach ( $this->steps as $step ) {

            if ( ! $step instanceof ComputeStep ) {

                continue;
            }

            foreach ( $step->reads() as $column ) {

                if ( ! in_array( $column, $this->rawColumns(), true ) ) {

                    throw new \RuntimeException( sprintf(
                        '%s reads %s, which is not a column of %s',
                        $step->name(), $column, $this->tables['raw'] ) );
                }

                $reads[ $column ] = true;
            }

            $rendered = array();

            foreach ( $step->when() as $clause ) {

                $rendered[] = $this->renderClause( $clause );
            }

            if ( $rendered ) {

                $where[] = '(' . implode( ' AND ', $rendered ) . ')';
            }
        }

        if ( ! $where ) {

            return array();
        }

        $columns = array_merge( array( 'id', 'yyyymmdd' ), array_keys( $reads ) );

        $rows = (array) $this->db->get_results( sprintf(
            'SELECT %s FROM %s r WHERE r.yyyymmdd >= %d AND r.yyyymmdd < %d%s AND (%s) LIMIT %d',
            'r.' . implode( ', r.', $columns ),
            $this->tables['raw'],
            (int) $span['start'],
            (int) $span['less_than'],
            $this->siteFilter( 'r' ),
            implode( ' OR ', $where ),
            self::CANDIDATE_CAP + 1
        ) );

        if ( count( $rows ) > self::CANDIDATE_CAP ) {

            throw new \RuntimeException( sprintf(
                'the candidate query returned more than %d rows for %s. Refusing rather '
              . 'than enriching part of the partition, which would not be convergent.',
                self::CANDIDATE_CAP, $span['name'] ) );
        }

        return $rows;
    }

    /**
     * A predicate shape rendered to SQL.
     *
     * Shapes rather than SQL strings in the registration, for the reason
     * A.1.23 gives about dimensions: the first non-MySQL store would otherwise
     * rewrite every one by hand.
     *
     * @param array $clause (column, operator[, value])
     * @return string
     * @throws \RuntimeException on an unknown operator or column
     */
    protected function renderClause( array $clause ) {

        $column   = isset( $clause[0] ) ? (string) $clause[0] : '';
        $operator = isset( $clause[1] ) ? strtolower( (string) $clause[1] ) : '';

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $column )
          || ! in_array( $column, $this->rawColumns(), true ) ) {

            throw new \RuntimeException( sprintf(
                '%s is not a column of %s', $column, $this->tables['raw'] ) );
        }

        switch ( $operator ) {

            case 'is null':
                return $column . ' IS NULL';

            case 'is not null':
                return $column . ' IS NOT NULL';

            case 'equals':
                return sprintf( "%s = '%s'", $column, $this->db->prepare( (string) $clause[2] ) );
        }

        throw new \RuntimeException( sprintf( 'unknown predicate operator "%s"', $operator ) );
    }

    /**
     * The side table holding what the compute steps worked out.
     *
     * One table, one column per step, bulk-inserted. A step never writes rows
     * of its own.
     *
     * @param Context $context
     * @return bool
     */
    protected function writeComputed( Context $context ) {

        $steps = array();

        foreach ( $this->steps as $column => $step ) {

            if ( $step instanceof ComputeStep ) {

                $steps[ $column ] = $step;
            }
        }

        if ( ! $steps ) {

            return true;
        }

        $definitions = array( 'c_id BIGINT NOT NULL', 'c_yyyymmdd INT NOT NULL' );

        foreach ( array_keys( $steps ) as $column ) {

            $definitions[] = $column . ' VARCHAR(255) NULL';
        }

        $definitions[] = 'PRIMARY KEY (c_id, c_yyyymmdd)';

        $this->db->query( sprintf( OWA_SQL_DROP_TABLE, $this->tables['computed'] ) );

        if ( ! $this->db->query( sprintf( 'CREATE TABLE %s (%s)',
                $this->tables['computed'], implode( ', ', $definitions ) ) ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Cube build: could not create %s.', $this->tables['computed'] ) );

            return false;
        }

        // Collect by row, so one row carries every step's answer for it.
        $rows = array();

        foreach ( $steps as $column => $step ) {

            foreach ( $step->values() as $key => $value ) {

                $rows[ $key ][ $column ] = $value;
            }
        }

        if ( ! $rows ) {

            return true;
        }

        $columns = array_merge( array( 'c_id', 'c_yyyymmdd' ), array_keys( $steps ) );
        $tuples  = array();

        foreach ( $rows as $key => $values ) {

            list( $id, $yyyymmdd ) = explode( ':', $key );

            $tuple = array( (int) $id, (int) $yyyymmdd );

            foreach ( array_keys( $steps ) as $column ) {

                $tuple[] = isset( $values[ $column ] )
                    ? "'" . $this->db->prepare( $values[ $column ] ) . "'" : 'NULL';
            }

            $tuples[] = '(' . implode( ', ', $tuple ) . ')';
        }

        // Chunked so one oversized statement cannot exceed max_allowed_packet.
        foreach ( array_chunk( $tuples, 500 ) as $chunk ) {

            if ( ! $this->db->query( sprintf( 'INSERT INTO %s (%s) VALUES %s',
                    $this->tables['computed'], implode( ', ', $columns ),
                    implode( ', ', $chunk ) ) ) ) {

                \OWA\Core\CoreAPI::error( 'Cube build: writing the computed values failed.' );

                return false;
            }
        }

        return true;
    }

    /**
     * Which joins some registered step asked for.
     *
     * @return array alias => true
     */
    protected function requiredJoins() {

        $required = array();

        foreach ( $this->steps as $step ) {

            foreach ( $step->requires() as $alias ) {

                $required[ $alias ] = true;
            }
        }

        return $required;
    }

    /**
     * Compose the one statement a build runs.
     *
     * @param array $span
     * @param array $expressions column => SQL
     * @return string
     */
    protected function statement( array $span, array $expressions ) {

        $raw_columns = $this->rawColumns();

        $start    = (int) $span['start'];
        $end      = (int) $span['less_than'];
        $lookback = (int) gmdate( 'Ymd',
            strtotime( $start . ' -' . self::LOOKBACK_DAYS . ' day' ) );

        $select = array();

        foreach ( $raw_columns as $column ) {

            $select[] = Context::RAW . '.' . $column;
        }

        /*
         * In the entity's order, then the registered dimensions'. Keyed lookup
         * rather than a parallel list, so the two cannot drift apart -- and
         * the INSERT names its columns, so this order need only agree with
         * itself rather than with the table's.
         */
        $derived = array_merge( $this->derivedColumns(), $this->dimensionColumns() );

        foreach ( $derived as $column ) {

            $select[] = $expressions[ $column ];
        }

        $required = $this->requiredJoins();
        $joins    = '';

        if ( isset( $required[ Context::SESSION ] ) ) {

            $joins .= sprintf( ' JOIN (%s) %s ON %s.w_id = %s.id AND %s.w_yyyymmdd = %s.yyyymmdd',
                $this->windowedRaw( $lookback, $end ), Context::SESSION,
                Context::SESSION, Context::RAW, Context::SESSION, Context::RAW );
        }

        if ( isset( $required[ Context::VISITOR ] ) ) {

            $joins .= sprintf( ' %s %s %s ON %s.visitor_id = %s.visitor_id',
                OWA_SQL_JOIN_LEFT_OUTER, $this->tables['visitors'], Context::VISITOR,
                Context::VISITOR, Context::RAW );
        }

        if ( isset( $required[ Context::COMPUTED ] ) ) {

            $joins .= sprintf( ' %s %s %s ON %s.c_id = %s.id AND %s.c_yyyymmdd = %s.yyyymmdd',
                OWA_SQL_JOIN_LEFT_OUTER, $this->tables['computed'], Context::COMPUTED,
                Context::COMPUTED, Context::RAW, Context::COMPUTED, Context::RAW );
        }

        return sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s %s%s WHERE %s.yyyymmdd >= %d AND %s.yyyymmdd < %d%s',
            $this->tables['staging'],
            implode( ', ', array_merge( $raw_columns, $derived ) ),
            implode( ', ', $select ),
            $this->tables['raw'],
            Context::RAW,
            $joins,
            Context::RAW, $start,
            Context::RAW, $end,
            $this->siteFilter( Context::RAW )
        );
    }

    /**
     * The session context each row needs, keyed back to the row it belongs to.
     *
     * One window over the session, framed across the whole of it, so
     * FIRST_VALUE reads the landing event and LAST_VALUE the final one. Window
     * functions are why v2 requires MySQL 8.0.
     *
     * It selects the primary key and its own outputs and nothing else. The sort
     * is the cost of this statement, so the width of the rows entering it is
     * what the cost is made of -- selecting every raw column here was 412s at a
     * million rows against 195s this way.
     *
     * @param int $from yyyymmdd, inclusive
     * @param int $to   yyyymmdd, exclusive
     * @return string
     */
    protected function windowedRaw( $from, $to ) {

        $columns = array( 'w.id AS w_id', 'w.yyyymmdd AS w_yyyymmdd' );

        // Only what a column definition actually references. Removing the last
        // definition that reads a value removes it from the sort too.
        foreach ( $this->columns->sessionSources() as $column => $alias ) {

            $columns[] = sprintf( 'FIRST_VALUE(w.%s) OVER session_w AS %s', $column, $alias );
        }

        $columns[] = 'LAST_VALUE(w.id) OVER session_w AS session_last_id';
        $columns[] = 'LAST_VALUE(w.ts) OVER session_w AS session_last_ts';

        return sprintf(
            'SELECT %s FROM %s w WHERE w.yyyymmdd >= %d AND w.yyyymmdd < %d%s '
          . 'WINDOW session_w AS (PARTITION BY w.site_id, w.visitor_id, w.session_id '
          . 'ORDER BY w.ts, w.id ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)',
            implode( ', ', $columns ),
            $this->tables['raw'],
            (int) $from,
            (int) $to,
            $this->siteFilter( 'w' )
        );
    }

    /**
     * An empty, unpartitioned copy of the cube.
     *
     * Dropped first: a run that died before its swap left one behind, holding a
     * half-built partition that must not be swapped in.
     *
     * @return bool
     */
    protected function makeStaging() {

        $this->dropWorkingTables();

        /*
         * FLAT FROM THE START. EXCHANGE PARTITION refuses a partitioned table,
         * and CREATE TABLE LIKE then REMOVE PARTITIONING created the cube's 72
         * partitions only to delete them again -- 4,181ms of a 4,317ms build,
         * against 136ms for everything that did actual work. Creating it
         * unpartitioned costs 55ms.
         *
         * Built fresh every run rather than kept and emptied: it is derived
         * from the live cube's own DDL, so it cannot be stale after a release
         * adds a column or a custom dimension is registered, and there is no
         * shape to check.
         */
        if ( ! $this->db->createUnpartitionedCopy( $this->tables['staging'], $this->tables['target'] ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Cube build: could not create %s.', $this->tables['staging'] ) );

            return false;
        }

        return true;
    }

    /** @return void */
    protected function dropWorkingTables() {

        foreach ( array( 'staging', 'computed' ) as $role ) {

            $this->db->query( sprintf( OWA_SQL_DROP_TABLE, $this->tables[ $role ] ) );
        }
    }

    /**
     * The instant a session must have ended before to count as closed.
     *
     * @param int $now microseconds
     * @return int microseconds
     */
    protected function closedBefore( $now ) {

        $length = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'session_length' );

        if ( $length <= 0 ) {

            $length = 1800;
        }

        return $now - ( $length * 1000000 );
    }

    /**
     * Advance last_seen for every visitor seen in the partition.
     *
     * One set-based UPDATE, and the only write a build makes outside its own
     * tables. It stores a period, so it usually changes nothing; GREATEST keeps
     * it monotonic, so rebuilding partitions out of order cannot move it back.
     *
     * UPDATE ... JOIN is MySQL's spelling of a joined update; PostgreSQL writes
     * UPDATE ... FROM. One statement behind one dialect constant when a second
     * backend exists -- there is nothing to abstract over with one.
     *
     * @param array $span
     * @return bool
     */
    protected function advanceLastSeen( array $span ) {

        return (bool) $this->db->query( sprintf(
            'UPDATE %s v JOIN (SELECT r.visitor_id, FLOOR(MAX(r.yyyymmdd) / 100) AS period FROM %s r '
          . 'WHERE r.yyyymmdd >= %d AND r.yyyymmdd < %d%s GROUP BY r.visitor_id) seen '
          . 'ON seen.visitor_id = v.visitor_id '
          . 'SET v.last_seen = GREATEST(COALESCE(v.last_seen, 0), seen.period)',
            $this->tables['visitors'],
            $this->tables['raw'],
            (int) $span['start'],
            (int) $span['less_than'],
            $this->siteFilter( 'r' )
        ) );
    }

    /**
     * The columns this Property's registered dimensions own, in registry order.
     *
     * Read off the steps rather than the registry a second time, so a
     * registration the constructor skipped cannot reappear in the INSERT's
     * column list with no expression behind it.
     *
     * @return string[]
     */
    protected function dimensionColumns() {

        return array_values( array_diff(
            array_keys( $this->steps ), $this->derivedColumns() ) );
    }

    /** @return string[] owa_event_raw's columns, in declaration order */
    protected function rawColumns() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();
    }

    /**
     * The columns the cube adds, in declaration order.
     *
     * Derived by difference rather than listed, so a column added to either
     * entity cannot leave this out of step with them.
     *
     * @return string[]
     */
    protected function derivedColumns() {

        return array_values( array_diff(
            \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getColumns(),
            $this->rawColumns() ) );
    }

    /** @return int raw rows the partition should hold */
    protected function countRaw( array $span ) {

        return $this->count( $this->tables['raw'] . ' r', sprintf(
            'WHERE r.yyyymmdd >= %d AND r.yyyymmdd < %d%s',
            (int) $span['start'], (int) $span['less_than'],
            $this->siteFilter( 'r' ) ) );
    }

    /**
     * @param string $table
     * @param string $where
     * @return int
     */
    protected function count( $table, $where ) {

        $row = $this->db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s %s', $table, $where ) );

        return $row ? (int) $row['n'] : 0;
    }
}

?>
