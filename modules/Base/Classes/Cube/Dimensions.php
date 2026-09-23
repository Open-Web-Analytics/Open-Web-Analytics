<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

use OWA\Module\Base\Entity\CustomDimension;

/**
 * Registering a custom dimension, and reading back what is registered.
 *
 * A site sets a key; ingest stores it in JSON; registering it here adds a real
 * column to that Property's cube and a build fills it from then on. Nothing
 * reads an unregistered key, so this is the whole of the path from "collected"
 * to "queryable".
 *
 * WHY A REAL COLUMN AND NOT A GENERATED ONE. A VIRTUAL generated column over
 * the JSON was measured and rejected: ~330ms flat and retroactive over all
 * history, which is better on both counts, but it puts the JSON extraction in
 * the SCHEMA rather than in one dialect expression, and the rebuild-and-swap
 * idiom is meant to port to columnar stores, which have columns and generally
 * not generated columns. A real column also parses the JSON once per build
 * rather than on every read.
 *
 * NO BACKFILL, BY DEFAULT. A new column is NULL for rows that already exist and
 * the next build fills it going forward. Backfilling is cmd=cube-rebuild over
 * whatever range is wanted, and it is the operator's decision rather than a
 * side effect of registering.
 *
 * That is worth stating as a capability, because it is the opposite of GA. GA's
 * registration is mandatory AND early: a custom dimension is not retroactive,
 * so everything collected before it was registered is permanently unreportable.
 * v2 keeps `params` on every raw row, so a registration made today can be
 * backfilled across the whole of retained history. The only bounds are raw
 * retention and the cost of rebuilding the coarse end of it.
 */
class Dimensions {

    /**
     * What a key may look like.
     *
     * The tracker's own pattern (OWATracker.PROPERTY_NAME_PATTERN), and the
     * same one on purpose. Two things follow from keeping them identical:
     * a key this refuses is one no OWA tracker could have set, and the JSON
     * path is `$.<key>` with nothing to quote or escape -- which is where a
     * registration that took arbitrary keys would have had to build a path out
     * of user text and put it inside a SQL string literal, escaped twice.
     *
     * A hand-rolled beacon can still send a stranger key, and ingest will still
     * store it. It just cannot be promoted to a column.
     */
    const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    /**
     * How many dimensions one Property may register.
     *
     * A FLAT CAP, not a byte sum, because a byte sum is not a number anyone can
     * plan against: it moves whenever a release adds a column to the cube, and
     * it buys wildly different numbers of dimensions depending on how wide each
     * one is -- 15 at VARCHAR(255) against 106 at VARCHAR(36) on the same cube.
     * Twenty of a fixed width is a promise that stays true.
     *
     * AN OUTER CAP, not the whole answer: capacityFor() asks the server how
     * many actually fit and returns the smaller of the two.
     *
     * Twenty is where a person's sense of "enough" is, and it is close to what
     * the tightest server allows -- MySQL 8.0 takes 19 on a cube of today's
     * shape, 8.4 takes at least 20. Neither number can be assumed, because
     * WHICH LIMIT BINDS DEPENDS ON THE SERVER. 8.4 refuses at 65,535 bytes,
     * MySQL's row DEFINITION limit; 8.0 refuses at 8,126, InnoDB's limit on
     * the part of a row that lives on the page. The second is far tighter, and
     * arithmetic that predicted the first exactly said nothing useful about it.
     *
     * So the budget is measured rather than modelled. Two attempts to compute
     * this from column widths were confidently wrong, and the second agreed
     * with the permissive server while the strict one was refusing.
     */
    const MAX_PER_PROPERTY = 20;

    /**
     * How wide a string dimension is. Not an option.
     *
     * Between GA's two caps -- it truncates a user property at 36 characters
     * and an event parameter at 100 -- and chosen as one number because a
     * per-registration width is a knob whose only effect is to spend room the
     * cap has already made irrelevant. The build clamps to it, so a longer
     * value is truncated rather than aborting the partition.
     */
    const DIMENSION_LENGTH = 64;

    /** Suffix of the throwaway table capacityFor() measures against. */
    const CAPACITY_SUFFIX = '_capacity';

    /** @var array property id => measured capacity, for this request only */
    private static $capacity = array();

    /**
     * How many custom dimensions this Property's cube will actually take.
     *
     * ASKED, NOT CALCULATED. The limit that binds depends on the server -- 8,126
     * on-page bytes on MySQL 8.0, 65,535 declared bytes on 8.4 -- and the second
     * is loose enough that arithmetic tuned to it is silently wrong on the
     * first. Both were tried; both were wrong in the same direction.
     *
     * Measured on a THROWAWAY COPY of the cube, never on the cube itself: the
     * search adds and drops columns, and doing that to a live table would be a
     * rebuild each time and would race the build. An unpartitioned copy is 65ms
     * and the whole search is about 850, which is affordable for an admin
     * screen and would not be on a write path -- nothing on the write path asks.
     *
     * Measured on the cube's RELEASE shape, so the answer does not move as
     * dimensions are registered: this is capacity, and what is left is capacity
     * minus what is used. The expensive shape is assumed -- user-scoped, which
     * carries a set-time column too -- so an installation registering
     * event-scoped ones has more room than it is promised rather than less.
     *
     * NEVER BLOCKS ON NOT KNOWING. If the cube is missing, or the copy cannot
     * be made, this answers the outer cap and lets reconcile()'s ALTER be the
     * judge. Refusing a registration because a measurement failed would be the
     * measurement causing the outage it exists to prevent.
     *
     * @param int|string $property_id
     * @return int
     */
    public static function capacityFor( $property_id ) {

        $key = (string) $property_id;

        if ( isset( self::$capacity[ $key ] ) ) {

            return self::$capacity[ $key ];
        }

        self::$capacity[ $key ] = self::measureCapacity( $property_id );

        return self::$capacity[ $key ];
    }

    /**
     * @param int|string $property_id
     * @return int
     */
    protected static function measureCapacity( $property_id ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $cube  = Cubes::tableFor( $property_id );

        if ( ! $cube || ! $db->tableExists( $cube ) ) {

            return self::MAX_PER_PROPERTY;
        }

        $scratch = $cube . self::CAPACITY_SUFFIX;

        $db->query( sprintf( OWA_SQL_DROP_TABLE, $scratch ) );

        if ( ! $db->createUnpartitionedCopy( $scratch, $cube ) ) {

            return self::MAX_PER_PROPERTY;
        }

        /*
         * The copy carries whatever is already registered, so the search runs
         * from there and its answer is added back to what is used. Measuring a
         * pristine shape would mean dropping the live columns off the copy
         * first, which is more ALTERs for the same number.
         */
        $used = count( self::forProperty( $property_id ) );
        $room = 0;
        $lo   = 0;
        $hi   = max( 0, self::MAX_PER_PROPERTY - $used );

        while ( $lo < $hi ) {

            $mid     = intdiv( $lo + $hi + 1, 2 );
            $columns = array();

            for ( $i = 0; $i < $mid; $i++ ) {

                $columns[ 'cd_fit' . $i ] = self::definitionFor(
                    CustomDimension::TYPE_STRING, self::DIMENSION_LENGTH );

                $columns[ 'cd_fit' . $i . CustomDimension::SET_TS_SUFFIX ] =
                    OWA_DTD_BIGINT . ' NULL';
            }

            if ( $db->alterColumnsRebuilding( $scratch, $columns ) ) {

                $lo   = $mid;
                $room = $mid;

                $db->alterColumnsRebuilding( $scratch, array(), array_keys( $columns ) );

            } else {

                $hi = $mid - 1;
            }
        }

        $db->query( sprintf( OWA_SQL_DROP_TABLE, $scratch ) );

        return min( self::MAX_PER_PROPERTY, $used + $room );
    }

    /**
     * The column name a key becomes.    /**
     * The column name a key becomes.
     *
     * Derived rather than equal, because Db enforces ^[A-Za-z0-9_]+$ on every
     * DDL path. With KEY_PATTERN in force the derivation is almost the identity
     * -- lowercasing is the only change -- and lowercasing is the reason
     * uniqueness has to be checked on the COLUMN: JSON keys are case-sensitive
     * where column names are not, so `Plan` and `plan` are two keys and one
     * column.
     *
     * @param string $key
     * @return string  '' if the key could never be a column
     */
    public static function columnFor( $key ) {

        if ( ! preg_match( self::KEY_PATTERN, (string) $key ) ) {

            return '';
        }

        return CustomDimension::PREFIX . strtolower( (string) $key );
    }

    /**
     * Every dimension registered against a Property, oldest first.
     *
     * @param int|string $property_id
     * @return array of rows
     */
    public static function forProperty( $property_id ) {

        if ( ! ctype_digit( (string) $property_id ) ) {

            return array();
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' )->getTableName();

        return (array) $db->get_results( sprintf(
            'SELECT * FROM %s WHERE property_id = %s ORDER BY id',
            $table, (string) (int) $property_id ) );
    }

    /**
     * The Properties with a registration whose column is not on the cube yet.
     *
     * ONE INDEXED READ FOR THE WHOLE INSTALLATION, which is what lets the apply
     * job run every fifteen minutes without being a cost: on almost every run
     * there is nothing pending and this is all it does, whether the
     * installation has one cube or a hundred and fifty.
     *
     * A DE-REGISTRATION IS NOT PENDING WORK. Removing a registration deletes
     * the row, so there is nothing here to find -- and that is deliberate
     * rather than an oversight. The surplus column is inert, because a build
     * fills the columns the registry names; all it costs is the space, and the
     * next build's reconcile reclaims it. What a person is actually waiting for
     * is a new dimension appearing, and that is what this finds.
     *
     * @return string[] property ids
     */
    public static function propertiesWithPendingWork() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' )->getTableName();

        $ids = array();

        foreach ( (array) $db->get_results( sprintf(
                "SELECT DISTINCT property_id AS p FROM %s WHERE state <> '%s'",
                $table, CustomDimension::STATE_APPLIED ) ) as $row ) {

            $ids[] = (string) $row['p'];
        }

        return $ids;
    }

    /**
     * The columns one registration owns.
     *
     * A user-scoped dimension owns two: its value and when it was set. They are
     * added and dropped together, in one ALTER, which the 5% batching margin
     * makes free.
     *
     * @param array $row a registration
     * @return array column name => type definition
     */
    public static function columnsOf( array $row ) {

        $columns = array(
            $row['column_name'] => self::definitionFor(
                (string) $row['data_type'], (int) $row['max_length'] ),
        );

        if ( (string) $row['scope'] === CustomDimension::SCOPE_USER ) {

            $columns[ $row['column_name'] . CustomDimension::SET_TS_SUFFIX ] =
                OWA_DTD_BIGINT . ' NULL';
        }

        return $columns;
    }

    /**
     * The SQL type a declared type becomes.
     *
     * Always NULL. A dimension is absent for every row collected before it was
     * registered and for every event that did not set it, and NULL is what
     * absence is (2.11). NOT NULL would also abort the whole partition's build
     * the first time one row did not carry the key.
     *
     * @param string $type
     * @param int    $max_length
     * @return string
     */
    public static function definitionFor( $type, $max_length ) {

        switch ( $type ) {

            case CustomDimension::TYPE_INTEGER:
                return OWA_DTD_BIGINT . ' NULL';

            case CustomDimension::TYPE_DECIMAL:
                return 'DOUBLE NULL';
        }

        // The stored width, not the constant: a dimension registered before
        // the constant changed keeps the column it was given, because changing
        // it would be an ALTER nobody asked for.
        return sprintf( 'VARCHAR(%d) NULL', $max_length > 0
            ? $max_length : self::DIMENSION_LENGTH );
    }

    /**
     * Record one or more dimensions against a Property.
     *
     * A WRITE, NOT A REBUILD. Putting the column on the cube is a full table
     * rebuild -- 4.4 seconds on an empty 73-partition cube, 11.3 at 1.5M rows,
     * longer on a real one -- and that is past PHP's 30-second limit, past
     * Varnish's 60 and past the load balancer's 65. Doing it here would make an
     * admin screen time out, and a timed-out request does not stop the ALTER:
     * measured, the client is killed and MySQL finishes anyway. The person
     * would see a failure, get the column regardless, and a retry would pay a
     * second rebuild before failing on a duplicate column.
     *
     * So this validates, prices, and writes the rows as pending. reconcile()
     * puts the columns on, under the lock a cube build already holds.
     *
     * ALL OR NOTHING. Every request is checked before any of them is written,
     * so a batch cannot half-register.
     *
     * @param int|string $property_id
     * @param array      $requests  each ['key','scope','type','label']
     * @return array ['ok' => bool, 'error' => string, 'registered' => array,
     *                'columns' => string[]]
     */
    public static function register( $property_id, array $requests ) {

        $fail = function ( $message ) {

            return array( 'ok' => false, 'error' => $message,
                'registered' => array(), 'columns' => array() );
        };

        $table = Cubes::tableFor( $property_id );

        if ( ! $table ) {

            return $fail( 'That is not a Property id.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->tableExists( $table ) ) {

            /*
             * A cube exists once its Property has collected something
             * (2.27.3), so there is nothing to add a column to yet. Refused
             * rather than creating it here: creating a cube is a build's job,
             * and doing it from a registration would spend 2.7 seconds building
             * seventy partitions for a Property that may never collect
             * anything.
             */
            return $fail( sprintf(
                '%s does not exist yet. A Property gets its cube when it first collects '
              . 'something, so there is nothing to register against.', $table ) );
        }

        $existing = array();

        foreach ( self::forProperty( $property_id ) as $row ) {

            $existing[ $row['column_name'] ] = $row;
        }

        $definitions = array();
        $validated   = array();

        // Measured once for the whole call, not per request: it is the same
        // answer for every one of them and it costs an ALTER or five.
        $capacity = self::capacityFor( $property_id );

        foreach ( $requests as $request ) {

            $checked = self::validate( $request, $existing, $definitions, $capacity );

            if ( isset( $checked['error'] ) ) {

                return $fail( $checked['error'] );
            }

            $validated[] = $checked;

            foreach ( self::columnsOf( $checked ) as $column => $definition ) {

                $definitions[ $column ] = $definition;
            }
        }

        if ( ! $definitions ) {

            return $fail( 'Nothing to register.' );
        }

        foreach ( $validated as $row ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' );

            $entity->setProperties( array(
                'id'            => $entity->generateId( $property_id . ':' . $row['column_name'] ),
                'property_id'   => (int) $property_id,
                'scope'         => $row['scope'],
                'dimension_key' => $row['dimension_key'],
                'column_name'   => $row['column_name'],
                'data_type'     => $row['data_type'],
                'max_length'    => $row['max_length'],
                'label'         => $row['label'],
                'creation_date' => time(),
                'state'         => CustomDimension::STATE_PENDING,
            ) );

            if ( $entity->create() !== true ) {

                return $fail( sprintf(
                    'Recording %s failed. Nothing was changed on %s.',
                    $row['column_name'], $table ) );
            }
        }

        return array(
            'ok'         => true,
            'error'      => '',
            'registered' => $validated,
            'columns'    => array_keys( $definitions ),
        );
    }

    /**
     * Why this request would be refused, in words, or '' if it would not.
     *
     * The same rules register() applies, reachable before anything is written,
     * so a screen can refuse a form the way it refuses any other invalid field
     * -- with the reason, on the form, keeping what was typed. Every refusal
     * here explains itself; a screen that replaced them with "could not save"
     * would throw that away at the one moment somebody needs it.
     *
     * ADVISORY, LIKE ANY PRE-CHECK. register() applies the same rules again
     * when it runs, because the answer can change between the two -- another
     * registration, or a cube that has since grown.
     *
     * @param int|string $property_id
     * @param array      $request  as register() takes them
     * @return string
     */
    public static function refusalFor( $property_id, array $request ) {

        $table = Cubes::tableFor( $property_id );

        if ( ! $table ) {

            return 'That is not a Property id.';
        }

        if ( ! \OWA\Core\CoreAPI::dbSingleton()->tableExists( $table ) ) {

            return sprintf(
                'This Property has no reporting cube yet. One is created the first time it '
              . 'collects something, and there is nothing to add a column to until then.' );
        }

        $existing = array();

        foreach ( self::forProperty( $property_id ) as $row ) {

            $existing[ $row['column_name'] ] = $row;
        }

        $checked = self::validate( $request, $existing, array(),
            self::capacityFor( $property_id ) );

        return isset( $checked['error'] ) ? (string) $checked['error'] : '';
    }

    /**
     * Remove a registration.
     *
     * The row goes now; the column goes at the next reconcile, for the same
     * reason it arrived at one. In between it is inert -- a build fills the
     * columns the registry names, and this one is no longer named -- so there
     * is no window in which anything is wrong, only one in which something is
     * untidy.
     *
     * THE VALUES GO WITH THE COLUMN, and that is acceptable because they are
     * derived: a build put them there from `params`, which this does not touch,
     * so registering again and rebuilding the range brings them back.
     *
     * @param int|string $property_id
     * @param string     $key
     * @return array ['ok' => bool, 'error' => string, 'columns' => string[]]
     */
    public static function deregister( $property_id, $key ) {

        $table  = Cubes::tableFor( $property_id );
        $column = self::columnFor( $key );

        if ( ! $table || ! $column ) {

            return array( 'ok' => false, 'error' => 'That is not a Property id and a key.',
                'columns' => array() );
        }

        $found = null;

        foreach ( self::forProperty( $property_id ) as $row ) {

            if ( $row['column_name'] === $column ) {

                $found = $row;
            }
        }

        if ( ! $found ) {

            return array( 'ok' => false, 'columns' => array(), 'error' => sprintf(
                '%s is not registered against Property %s.', $key, $property_id ) );
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' );

        $entity->load( $found['id'] );

        if ( $entity->delete() === false ) {

            return array( 'ok' => false, 'columns' => array(), 'error' => sprintf(
                'Could not remove the registration for %s.', $key ) );
        }

        return array( 'ok' => true, 'error' => '',
            'columns' => array_keys( self::columnsOf( $found ) ) );
    }

    /**
     * Make a cube's columns match its registry, in ONE ALTER.
     *
     * The registry is the desired state and this is the only thing that acts on
     * it. Everything registered or de-registered since the last run is applied
     * together: measured on a 73-partition cube, one added column costs
     * 4,361ms and two cost 4,247ms, and a combined add-and-drop costs 4,193ms
     * -- the rebuild is the cost and the clause count is not. So a reconcile is
     * exactly one rebuild however much has accumulated, which is strictly
     * better than batching in the UI and hoping.
     *
     * THE CALLER MUST HOLD THIS CUBE'S LOCK. A build derives its staging table
     * from the live cube's DDL and then swaps; an ALTER landing between those
     * two makes EXCHANGE PARTITION refuse the pair. `cube-build:<table>` is
     * that lock and it already exists.
     *
     * @param int|string $property_id
     * @return array ['ok','changed','added','dropped','error','skipped']
     */
    public static function reconcile( $property_id ) {

        $nothing = array( 'ok' => true, 'changed' => false, 'added' => array(),
            'dropped' => array(), 'error' => '', 'skipped' => array() );

        $table = Cubes::tableFor( $property_id );
        $db    = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $table || ! $db->tableExists( $table ) ) {

            return $nothing;
        }

        $present  = self::registeredColumnsOn( $table );
        $wanted   = array();
        $rows     = array();

        foreach ( self::forProperty( $property_id ) as $row ) {

            foreach ( self::columnsOf( $row ) as $column => $definition ) {

                $wanted[ $column ] = $definition;
                $rows[ $column ]   = $row;
            }
        }

        $add  = array_diff_key( $wanted, array_flip( $present ) );
        $drop = array_diff( $present, array_keys( $wanted ) );

        if ( ! $add && ! $drop ) {

            // Still reconcile the STATES: a row can say pending against a
            // column that is already there, after a failed write or a rollback.
            self::recordStates( $property_id, $present, array() );

            return $nothing;
        }

        $skipped = array();

        if ( $add || $drop ) {

            /*
             * ASKED, NOT PREDICTED. The server enforces a row-size limit and
             * knows its own accounting; modelling it here would be a second
             * copy of that arithmetic to keep true, guarding a case twenty
             * dimensions cannot reach. A refusal is recorded against the
             * registrations and the build carries on without them.
             */
            if ( ! $db->alterColumnsRebuilding( $table, $add, $drop ) ) {

                $message = sprintf(
                    'Altering %s was refused. If the server reported 1118, the cube has no '
                  . 'room left in its row for another column and something has to be '
                  . 'de-registered first.', $table );

                foreach ( array_keys( $add ) as $column ) {

                    $skipped[ $column ] = $message;
                }

                self::recordStates( $property_id, self::registeredColumnsOn( $table ), $skipped );

                return array( 'ok' => false, 'changed' => false, 'added' => array(),
                    'dropped' => array(), 'error' => $message, 'skipped' => $skipped );
            }
        }

        self::recordStates( $property_id, self::registeredColumnsOn( $table ), $skipped );

        return array(
            'ok'      => true,
            'changed' => (bool) ( $add || $drop ),
            'added'   => array_keys( $add ),
            'dropped' => array_values( $drop ),
            'error'   => '',
            'skipped' => $skipped,
        );
    }

    /**
     * The cd_ columns a cube actually carries.
     *
     * The authority on what is applied. A row in the registry is a statement of
     * intent; this is what the build can read.
     *
     * @param string $table
     * @return string[]
     */
    public static function registeredColumnsOn( $table ) {

        return \OWA\Core\CoreAPI::dbSingleton()->listColumns(
            $table, CustomDimension::PREFIX );
    }

    /**
     * Write back what each registration's column is actually doing.
     *
     * Read from the TABLE rather than from what the ALTER was asked to do, so a
     * state can never claim more than the cube can show.
     *
     * @param int|string $property_id
     * @param string[]   $present  cd_ columns on the cube
     * @param array      $skipped  column => why it could not be added
     * @return void
     */
    protected static function recordStates( $property_id, array $present, array $skipped ) {

        $present = array_flip( $present );

        foreach ( self::forProperty( $property_id ) as $row ) {

            $columns = array_keys( self::columnsOf( $row ) );
            $applied = true;

            foreach ( $columns as $column ) {

                $applied = $applied && isset( $present[ $column ] );
            }

            $message = '';

            foreach ( $columns as $column ) {

                if ( isset( $skipped[ $column ] ) ) {

                    $message = (string) $skipped[ $column ];
                }
            }

            $state = $applied
                ? CustomDimension::STATE_APPLIED
                : ( $message === '' ? CustomDimension::STATE_PENDING
                                    : CustomDimension::STATE_FAILED );

            if ( (string) $row['state'] === $state
              && (string) $row['state_message'] === $message ) {

                continue;
            }

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_dimension' );

            $entity->load( $row['id'] );
            $entity->setProperties( array(
                'state'         => $state,
                'state_message' => substr( $message, 0, 255 ),
            ) );

            if ( $state === CustomDimension::STATE_APPLIED && ! $row['applied_date'] ) {

                $entity->setProperties( array( 'applied_date' => time() ) );
            }

            $entity->update();
        }
    }

    /**
     * One registration request, checked.
     *
     * @param array $request
     * @param array $existing     column => registration already in place
     * @param array $in_this_call column => definition already queued
     * @return array the row to write, or ['error' => string]
     */
    protected static function validate( array $request, array $existing, array $in_this_call,
                                        $capacity = self::MAX_PER_PROPERTY ) {

        $key = isset( $request['key'] ) ? trim( (string) $request['key'] ) : '';

        if ( ! preg_match( self::KEY_PATTERN, $key ) ) {

            return array( 'error' => sprintf(
                '"%s" is not a property name. A name starts with a letter, continues with '
              . 'letters, digits and underscores, and is at most 40 characters -- which is '
              . 'what the tracker will accept, so a name this refuses is one no tracker '
              . 'could have set.', $key ) );
        }

        $scope = isset( $request['scope'] ) ? (string) $request['scope'] : '';

        if ( ! in_array( $scope, CustomDimension::scopes(), true ) ) {

            return array( 'error' => sprintf(
                'scope must be %s. There is no session scope: a session-scoped value is '
              . 'one a build derives, not one a client carries.',
                implode( ' or ', CustomDimension::scopes() ) ) );
        }

        $type = isset( $request['type'] ) && $request['type'] !== ''
            ? (string) $request['type'] : CustomDimension::TYPE_STRING;

        if ( ! in_array( $type, CustomDimension::types(), true ) ) {

            return array( 'error' => sprintf( 'type must be one of %s.',
                implode( ', ', CustomDimension::types() ) ) );
        }

        /*
         * THE BUDGET, which is the smaller of the outer cap and what this
         * server will actually take. Counted across what is already registered
         * AND what this call has queued, so a batch cannot step over it one
         * request at a time.
         */
        $held = count( $existing ) + count( $in_this_call );

        if ( $held >= $capacity ) {

            return array( 'error' => sprintf(
                'this Property has room for %d custom dimensions and already has %d. '
              . 'De-register one to make room.%s',
                $capacity, $held,
                $capacity < self::MAX_PER_PROPERTY
                    ? sprintf( ' (%d rather than the usual %d: this server will not take '
                             . 'more columns on a row of this cube\'s shape.)',
                               $capacity, self::MAX_PER_PROPERTY )
                    : '' ) );
        }

        $column = self::columnFor( $key );

        // Plus the set_ts suffix for a user-scoped one, which is the longer of
        // the two and therefore the one that has to fit.
        if ( strlen( $column . CustomDimension::SET_TS_SUFFIX ) > 64 ) {

            return array( 'error' => sprintf(
                '"%s" makes a column name longer than the 64 characters a name may be.', $key ) );
        }

        if ( isset( $existing[ $column ] ) ) {

            return array( 'error' => sprintf(
                '%s is already registered as %s%s.',
                $column, $existing[ $column ]['dimension_key'],
                $existing[ $column ]['dimension_key'] === $key
                    ? '' : ' -- column names are case-insensitive where JSON keys are not' ) );
        }

        if ( isset( $in_this_call[ $column ] ) ) {

            return array( 'error' => sprintf(
                'two of these derive the same column, %s.', $column ) );
        }

        $label = isset( $request['label'] ) && $request['label'] !== ''
            ? (string) $request['label'] : $key;

        return array(
            'scope'         => $scope,
            'dimension_key' => $key,
            'column_name'   => $column,
            'data_type'     => $type,
            'max_length'    => $type === CustomDimension::TYPE_STRING ? self::DIMENSION_LENGTH : 0,
            'label'         => $label,
        );
    }
}

?>
