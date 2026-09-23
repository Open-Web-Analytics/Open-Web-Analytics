<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One registered custom dimension: a key a site sets, promoted to a column of
 * that Property's cube.
 *
 * REGISTRATION IS WHAT MAKES A VALUE QUERYABLE. A site can set any key it likes
 * and ingest stores all of them -- event properties in owa_event_raw.params,
 * user properties in owa_visitor_acquisition.properties -- but nothing reads an
 * unregistered key. Everything queryable is a dimension, and a dimension is a
 * real column that a build fills.
 *
 * PER PROPERTY, because the cube is (Classes\Cube\Cubes). Two Properties may
 * use the same key for different things, which is the whole point: v1's numbered
 * slots forced one namespace on an installation and cv3 meant something
 * different on every site. GA registers custom definitions on the property for
 * the same reason.
 *
 * NOT RELEASE SCHEMA. These rows describe one installation's choices, so
 * registering does not move required_schema_version and there is no Update
 * class per dimension. What a release owns is this table; what an operator owns
 * is its contents and the columns they imply.
 *
 * THE ROW IS THE DESIRED STATE, NOT A RECORD OF WHAT HAPPENED. Writing it is
 * cheap and immediate; putting the column on the cube is a full table rebuild
 * and happens later, when something holds that cube's lock -- see
 * Classes\Cube\Dimensions::reconcile(). So a row can exist for a column that
 * is not there yet, and the states below say which.
 *
 * THE COLUMN NAME IS DERIVED FROM THE KEY, NOT EQUAL TO IT, and both are stored.
 * Uniqueness is enforced on the COLUMN, because that is what DDL collides on.
 */
class CustomDimension extends \OWA\Core\Entity {

    /** Set per event, read by a build from owa_event_raw.params. */
    const SCOPE_EVENT = 'event';

    /**
     * Set per visitor, read from the visitor store.
     *
     * There is deliberately no session scope. GA derives session scope rather
     * than letting a tag carry it, and so does v2: a session-scoped value the
     * client carries is exactly what 2.9 refuses -- something the build can
     * derive and the client can get wrong.
     */
    const SCOPE_USER = 'user';

    /** What the column is declared as, and therefore how the JSON is read. */
    const TYPE_STRING  = 'string';
    const TYPE_INTEGER = 'integer';
    const TYPE_DECIMAL = 'decimal';

    /**
     * Prefix on every derived column name.
     *
     * It does three things, and the third is the one that matters.
     *
     *   - it keeps the registered columns separable, so a test can still assert
     *     that the cube's unprefixed columns are exactly the entity's;
     *   - it makes collision with a release column impossible without a
     *     reserved-word list that would go stale every time the cube gains one;
     *   - it CONSTRAINS DDL BUILT FROM USER INPUT. De-registration takes a name
     *     from a person and issues DROP COLUMN. Nothing matching
     *     ^cd_[A-Za-z0-9_]+$ can be a release column, so the pattern is the
     *     guarantee rather than the caller's care.
     */
    const PREFIX = 'cd_';

    /**
     * Registered, but the column is not on the cube yet.
     *
     * The normal state for a few minutes after someone registers one. Adding
     * the column is a full table rebuild -- 11.3 seconds measured at 1.5M rows,
     * and longer on a real cube -- which is past PHP's 30-second limit, past
     * Varnish's 60 and past the load balancer's 65. Worse, a killed request
     * does NOT kill the ALTER: measured, the client dies and MySQL finishes
     * anyway, so a synchronous screen would hand someone a timeout, add the
     * column regardless, and let them retry into a second full rebuild that
     * fails on a duplicate column.
     *
     * So the registration is the record and the DDL happens later, under the
     * lock the cube build already holds.
     */
    const STATE_PENDING = 'pending';

    /** The column is on the cube and a build fills it. */
    const STATE_APPLIED = 'applied';

    /**
     * The ALTER was tried and refused, and state_message says why.
     *
     * Almost always the row budget: two registrations that each fitted on
     * their own do not fit together, which only the check made under the lock
     * can see.
     */
    const STATE_FAILED = 'failed';

    /**
     * Suffix of the companion column a user-scoped dimension gets.
     *
     * Not a dimension itself: a microsecond value has one bucket per event,
     * which is a pathological thing to group by. What it is for is the test
     * kind -- cd_x_set_ts <= ts, "the property was already in force at this
     * event" -- which is boolean and is the question a write-time stamp cannot
     * otherwise answer. A cube row says the property's value; without this it
     * cannot say whether that value applied yet.
     */
    const SET_TS_SUFFIX = '_set_ts';

    function __construct() {

        $this->setTableName( 'custom_dimension' );

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /*
         * Whose cube this column lives on. Not a foreign key: the Property may
         * be deleted while the cube and its columns are still there, and the
         * registrar reads what exists rather than what is still referenced.
         */
        $property_id = new \OWA\Module\Base\Classes\DbColumn( 'property_id', OWA_DTD_BIGINT );
        $property_id->setIndex();
        $this->setProperty( $property_id );

        $scope = new \OWA\Module\Base\Classes\DbColumn( 'scope', OWA_DTD_VARCHAR64 );
        $this->setProperty( $scope );

        /*
         * The key as the tracker sets it -- setEventProperty('plan', ...) --
         * which is what a build looks up in the JSON. Constrained at
         * registration to the tracker's own name pattern, which is what lets
         * the JSON path be built without quoting.
         */
        $dimension_key = new \OWA\Module\Base\Classes\DbColumn( 'dimension_key', OWA_DTD_VARCHAR255 );
        $this->setProperty( $dimension_key );

        // 64 because that is MySQL's identifier limit, and a longer value could
        // never have become a column.
        $column_name = new \OWA\Module\Base\Classes\DbColumn( 'column_name', OWA_DTD_VARCHAR64 );
        $this->setProperty( $column_name );

        /*
         * Declared at registration, because the ALTER fixes it and the JSON
         * does not. A document says "42" and "forty-two" in the same column of
         * the same key on two different beacons; the cube has to have decided.
         */
        $data_type = new \OWA\Module\Base\Classes\DbColumn( 'data_type', OWA_DTD_VARCHAR64 );
        $this->setProperty( $data_type );

        /*
         * How wide a string column is, and therefore how much of the row's
         * 65,535-byte allowance it spends. It is the difference between sixteen
         * dimensions and a hundred, so it is the operator's to choose rather
         * than a constant here. The build clamps to it, so a longer value is
         * truncated rather than aborting the partition under STRICT_ALL_TABLES.
         */
        $max_length = new \OWA\Module\Base\Classes\DbColumn( 'max_length', OWA_DTD_INT );
        $this->setProperty( $max_length );

        /** What a person is shown. Free text, and never part of any identifier. */
        $label = new \OWA\Module\Base\Classes\DbColumn( 'label', OWA_DTD_VARCHAR255 );
        $this->setProperty( $label );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );

        /*
         * WHAT THE RECONCILE HAS DONE ABOUT THIS ROW, for a person to read.
         *
         * It is not what a build trusts. A build fills the columns the registry
         * names AND the table actually has, so a row claiming `applied` against
         * a column that is not there costs nothing -- where trusting it would
         * make every build fail on "Unknown column in field list". The table is
         * the authority; this is the explanation.
         */
        $state = new \OWA\Module\Base\Classes\DbColumn( 'state', OWA_DTD_VARCHAR64 );
        $state->setIndex();
        $this->setProperty( $state );

        /** Why, when the state is `failed`. Empty otherwise. */
        $state_message = new \OWA\Module\Base\Classes\DbColumn( 'state_message', OWA_DTD_VARCHAR255 );
        $state_message->setNullable();
        $this->setProperty( $state_message );

        /** When the column reached the cube. */
        $applied_date = new \OWA\Module\Base\Classes\DbColumn( 'applied_date', OWA_DTD_BIGINT );
        $applied_date->setNullable();
        $this->setProperty( $applied_date );

        /*
         * One registration per column per Property. The lookup that enforces it
         * is this index; the ALTER enforces it again at the table, which is the
         * authority -- two registrations deriving one column name is the case
         * this exists to catch, and it is why uniqueness is on the COLUMN
         * rather than on the key.
         */
        $this->addCompositeIndex( 'property_column', array( 'property_id', 'column_name' ) );
    }

    /** The scopes a registration may declare. @return string[] */
    public static function scopes() {

        return array( self::SCOPE_EVENT, self::SCOPE_USER );
    }

    /** The types a registration may declare. @return string[] */
    public static function types() {

        return array( self::TYPE_STRING, self::TYPE_INTEGER, self::TYPE_DECIMAL );
    }

    /** @return string[] */
    public static function states() {

        return array( self::STATE_PENDING, self::STATE_APPLIED, self::STATE_FAILED );
    }
}

?>
