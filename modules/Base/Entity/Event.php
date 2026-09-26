<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_event -- v2's denormalised event table. The one queries hit.
 *
 * Every column of owa_event_raw, unchanged and in the same order, then the
 * sixteen the cube build derives.
 *
 * IT EXTENDS EventRaw RATHER THAN RESTATING IT
 * "The same columns in the same order" is a requirement, not a description: the
 * pass swaps a staging table into a partition of this one, and EXCHANGE
 * PARTITION refuses two tables that differ anywhere. A hand-copied list would
 * drift the first time raw gained a column.
 *
 * NULLABILITY FOLLOWS WHAT THE PASS CAN PRODUCE
 * Copies -- the landing_page_* set -- are nullable because raw declares their
 * sources nullable. Under STRICT_ALL_TABLES a NULL into a NOT NULL column
 * aborts the statement, so one page with no title would fail the whole
 * partition rebuild.
 *
 * Resolutions -- source, medium, acq_* -- always produce a value or the
 * sentinel, so they are NOT NULL.
 *
 * 2.1's type column lists some of the copies without NULL. Satisfying that
 * would mean inventing a value for an absence, which 2.11 forbids.
 *
 * campaign, ad and search_terms are copies despite being attribution: a
 * campaign exists only because a URL was tagged with one, so no tag is an
 * absence, not an unresolved reading.
 */
class Event extends EventRaw {

    /**
     * Which Property's cube this instance is, as a decimal string.
     *
     * Empty until bindToProperty() is called, and every table operation
     * refuses while it is.
     *
     * @var string
     */
    private $property_id = '';

    /**
     * Whether this instance has a table at all.
     *
     * Separate from $property_id because the pre-split cube has a table and no
     * Property -- and separate from the inherited table name because that name
     * is owa_event_raw until something overwrites it, which is exactly the
     * mistake this exists to make impossible.
     *
     * @var bool
     */
    /**
     * The SQL alias every cube is read under, whatever Property it belongs to.
     * See the constructor: column references are built before a Property is
     * known, so the alias cannot depend on one.
     */
    const ALIAS = 'event';

    private $bound = false;

    function __construct() {

        // Raw's 54 columns, its primary key, its indexes and its partition
        // column, in that order.
        parent::__construct();

        /*
         * NO TABLE HERE. There is no owa_event: this class is the SHAPE of a
         * cube, and a cube belongs to a Property (Cube\Cubes).
         * bindToProperty() is what gives an instance a table.
         *
         * The name parent::__construct() set is removed rather than left. It
         * is owa_event_RAW -- inherited along with raw's columns -- so an
         * unbound instance would otherwise answer with a real table, and a
         * caller that forgot to bind would write the cube's rows into raw.
         */
        unset( $this->_tableProperties['name'] );

        /*
         * THE ALIAS STAYS, and is the same for every Property.
         *
         * A table name and a table alias are not the same kind of thing. The
         * name says which table to read, and an unbound instance must refuse to
         * answer that. The alias is a label local to one query -- `FROM
         * owa_event_<property> AS event` -- and every column reference in that
         * query is written against it.
         *
         * Metrics and dimensions build their column references at REGISTRATION
         * time, from their own entity instance, long before anything knows
         * which Property a query is about (Core\Metric::setColumn prefixes with
         * getTableAlias()). Unsetting the alias made that a warning and an
         * unprefixed column; making it vary per Property would be worse still,
         * since a column reference built against one Property's alias would be
         * wrong for every other.
         *
         * So the alias is fixed. Only the FROM clause needs binding.
         */
        $this->_tableProperties['alias'] = self::ALIAS;

        // And the flag that name set on the way past: setTableName() is what
        // records a binding, and raw's constructor has just called it.
        $this->bound = false;

        /*
         * The session's attribution, read from its first event -- the only row
         * of the session carrying tags, since the landing URL rides the landing
         * beacon and no other.
         *
         * This is the only place the verdict is stored. Not in the cookie,
         * which cannot be revisited when the model changes, and not in raw,
         * which is not rebuilt. Here a corrected classifier is re-applied by
         * rebuilding the partition.
         */
        $this->setProperty( $this->resolved( 'source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->resolved( 'medium', OWA_DTD_VARCHAR64 ) );

        $this->setProperty( $this->column( 'campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'search_terms', OWA_DTD_VARCHAR255 ) );

        /*
         * The session's landing page, copied from that same first event. Makes
         * a landing-page report a GROUP BY: 215ms against 3.1s on the measured
         * corpus.
         *
         * Path and query are copied rather than re-parsed, so they cannot
         * disagree with the row they came from. Exit needs no twin -- is_exit
         * makes it a filter over page_location.
         */
        $this->setProperty( $this->column( 'landing_page_location', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'landing_page_path', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'landing_page_query', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'landing_page_title', OWA_DTD_VARCHAR512 ) );

        /*
         * The one terminal value. Stamped only on a session whose last event is
         * older than the idle timeout; a later rebuild fills in the rest. A
         * rebuild REPLACES the partition, so no session ever has two exits.
         *
         * NOT NULL DEFAULT 0, like every boolean here: a nullable one holds
         * three values and GROUP BY gives each a bucket. 0 therefore covers the
         * not-yet-knowable case, which does not earn a third state.
         *
         * Engagement time, bounce and duration are NOT columns. They are
         * read-time aggregates over the session's rows, and materialising them
         * would be a second authority to keep in step.
         */
        $is_exit = $this->column( 'is_exit', OWA_DTD_BOOLEAN, false );
        $is_exit->setNotNull();
        $is_exit->setDefaultValue( 0 );
        $this->setProperty( $is_exit );

        /*
         * The visitor's acquisition, from the visitor store -- the build's only
         * read outside the partition it is building, and the reason that store
         * exists.
         *
         * Write-once at first_visit: a later campaign moves `source`, never
         * these. Where the store has no row a build writes the sentinel.
         *
         * SOURCE AND MEDIUM RESOLVE; THE OTHER THREE COPY, so only the first
         * two can be NOT NULL. acq_source and acq_medium are classified from
         * the stored referring host and always produce a value -- a visit with
         * no referrer is `direct`, not nothing. A campaign, an ad and a search
         * term are transcribed as collected, and most first visits carry none
         * of them: a visitor who arrived untagged has a store row with NULL in
         * all three.
         *
         * Declaring those NOT NULL made one untagged visitor fail the build for
         * a WHOLE PARTITION with "Column 'acq_campaign' cannot be null", and it
         * asked the copy step for something it deliberately does not do --
         * CopyStep writes the sentinel where there is NO ROW, which is a
         * different statement from a row holding NULL in one column. That
         * distinction needs somewhere to live, so it lives in NULL.
         */
        $this->setProperty( $this->resolved( 'acq_source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->resolved( 'acq_medium', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'acq_campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'acq_ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'acq_search_terms', OWA_DTD_VARCHAR255 ) );

        // When a build last wrote this partition, in microseconds. Carries the
        // as-of a report envelope reports, and the provenance of a bad rebuild.
        $built_at = $this->column( 'built_at', OWA_DTD_BIGINT, false );
        $built_at->setNotNull();
        $this->setProperty( $built_at );

        /*
         * Whether the session this event belongs to was the visitor's first,
         * as the label a report groups by -- see Classes\Cube\NewVsReturningStep
         * for why the label and not a flag.
         *
         * LAST, AFTER built_at, on purpose. ADD COLUMN appends
         * (OWA_SQL_ADD_COLUMN_REBUILD), so a cube that got this column from
         * Update044 carries it at the end; declaring it anywhere else would
         * leave an upgraded install and a fresh one with the same columns in a
         * different order. Nothing reads the cube positionally -- the build
         * names its columns and a staging table is cut from the live DDL -- but
         * two shapes for one release is a difference somebody eventually has to
         * explain.
         *
         * NOT NULL and no default, like source and medium: a build always
         * produces a value, the sentinel included. Measured on this server
         * under STRICT_ALL_TABLES, ADD COLUMN ... NOT NULL on a populated cube
         * backfills '' rather than failing, so rows written before the next
         * rebuild read as `(not set)` until one runs.
         */
        $this->setProperty( $this->resolved( 'new_vs_returning', OWA_DTD_VARCHAR16 ) );
    }

    /**
     * A column a build always fills: NOT NULL, and no default.
     *
     * A default would let a row be written without the value. Nothing writes
     * these rows but a build, so an insert missing one should fail.
     *
     * @param string $name
     * @param string $type an OWA_DTD_* value
     * @return \OWA\Module\Base\Classes\DbColumn
     */
    private function resolved( $name, $type ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );
        $column->setNotNull();

        return $column;
    }

    /**
     * How much of this table's lead has to be daily.
     *
     * THE ONE PLACE THIS IS DECLARED. Three things shape a table's partitions --
     * Db::createTable() at creation, partition-rotate as it maintains the lead,
     * partition-reorganize when an operator changes granularity -- and each of
     * them asks here. Two of the three had already been written as if every fact
     * table were alike, and both were wrong in the same way: reorganize merged
     * the daily front away, and createTable built a monthly lead that left every
     * rebuild rewriting a month until the first rotate ran.
     *
     * It lives on the ENTITY because Db::createTable() sits below the
     * controllers and cannot reach them. A description kept anywhere else is one
     * the creation path cannot consult, and the two would drift apart again.
     *
     * Only the cube answers. Raw is never rebuilt -- its retention is a DROP
     * PARTITION -- so it would pay the merge for nothing, and v1's fact tables
     * have no rebuild to make cheaper.
     *
     * @return int  months, or 0 for a table of one granularity throughout
     */
    public function getDailyLeadMonths() {

        return \OWA\Core\Db::CUBE_DAILY_MONTHS;
    }

    /**
     * Point this shape at one Property's cube.
     *
     * The columns are the same for every Property -- a release adds one to all
     * of them -- so there is one class and N tables rather than N classes.
     * Custom dimensions are what make two cubes differ, and they are registered
     * per Property precisely because the namespace is the Property's.
     *
     * @param int|string $property_id
     * @return $this
     */
    public function bindToProperty( $property_id ) {

        $this->property_id = (string) $property_id;

        return $this->bindToTable(
            \OWA\Module\Base\Classes\Cube\Cubes::PREFIX . $this->property_id );
    }

    /**
     * Point this shape at a table by name, without a Property.
     *
     * For the pre-split cube: `owa_event` existed between schema 35 and 41, and
     * the updates that created it, added columns to it and finally dropped it
     * have to be able to name it. It has no Property, so bindToProperty()
     * cannot express it. A test probing the creation path is the other caller.
     *
     * @param string $alias  the name without the namespace prefix
     * @return $this
     */
    public function bindToTable( $alias ) {

        $this->setTableName( $alias, \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) );

        return $this;
    }

    /**
     * Naming a table is what binds this shape to one.
     *
     * Overridden only to record that it happened. The constructor removes the
     * name it inherits -- EventRaw's -- so an instance has a table only if
     * something gave it one, and this is the single way through.
     *
     * @param string $name
     * @param string $namespace
     * @return void
     */
    public function setTableName( $name, $namespace = 'owa_' ) {

        parent::setTableName( $name, $namespace );

        /*
         * parent::setTableName() sets the alias to the table's own name, which
         * for a cube is per-Property. Put it back: see the constructor for why
         * the alias must not vary.
         */
        $this->_tableProperties['alias'] = self::ALIAS;

        $this->bound = true;
    }

    /** @return string  the Property's id, or '' while unbound */
    public function getPropertyId() {

        return $this->property_id;
    }

    /**
     * The cube's table, once it has a Property.
     *
     * Refuses rather than answering 'owa_event'. That table does not exist, and
     * a caller that has not said which Property it means is asking a question
     * with no answer -- so the failure belongs here, naming the fix, rather
     * than several layers down as "table doesn't exist" against a name nothing
     * ever created.
     *
     * @return string
     * @throws \RuntimeException while unbound
     */
    public function getTableName() {

        if ( ! $this->bound ) {

            throw new \RuntimeException(
                'There is no owa_event: a reporting cube belongs to a Property. '
              . 'Bind the entity with bindToProperty(), or go through '
              . 'Classes\\Cube\\Cubes, which names and creates them.' );
        }

        return parent::getTableName();
    }

    /**
     * As EventRaw::column(), which is private to it.
     *
     * @param string $name
     * @param string $type
     * @param bool   $nullable
     * @return \OWA\Module\Base\Classes\DbColumn
     */
    private function column( $name, $type, $nullable = true ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );

        if ( $nullable ) {

            $column->setNullable();
        }

        return $column;
    }
}

?>
