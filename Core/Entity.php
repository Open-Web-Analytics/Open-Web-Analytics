<?php
namespace OWA\Core;

/**
 * Abstract Entity Class
 */

// Entities set their columns as undeclared properties. PHP 8.2 deprecates that
// except on a class carrying this attribute, which is PHP's supported way to
// keep it.
/**
 * @phpstan-type TableProperties array{
 *     name?: string,
 *     alias?: string,
 *     cacheable?: bool,
 *     primary_key?: string,
 *     partition_column?: string,
 *     composite_indexes?: array<string, list<string>>,
 *     unique_indexes?: array<string, list<string>>,
 *     composite_primary_key?: list<string>,
 *     character_encoding?: string|null,
 *     table_type?: string
 * }
 * @phpstan-type ColumnConstraints array<string, array{value: mixed, operator: string}>
 *         column => the value it must hold and the comparison
 */
#[\AllowDynamicProperties]
class Entity {

    /**
     * The name entityFactory() made it under, e.g. base.site; null before.
     *
     * @var string|null
     */
    public $name;

    /** @var array<string, \OWA\Module\Base\Classes\DbColumn> column name => column */
    public $properties = [];

    /** @var TableProperties */
    public $_tableProperties = [];

    /**
     * Whether the table encoding has been handed down to the columns yet.
     *
     * @var bool
     */
    protected $_character_encoding_applied = false;

    /**
     * True once the row has been read or hydrated; null before.
     *
     * @var bool|null
     */
    public $wasPersisted;

    /**
     * Not read or written here.
     *
     * @var mixed nothing in OWA sets it; a subclass may
     */
    public $cache;

    /**
     * Columns changed since the last write, column => the value set.
     *
     * @var array<string, mixed>
     */
    public $dirty = [];

    /**
     * Every column's value.
     *
     * @return array<string, mixed> column => value as set, any type
     */
    public function _getProperties() {
        
        $properties = [];
        
        foreach ($this->properties as $k => $v) {
            
            $properties[$k] = $v->getValue();
                
        }

        return $properties;
    }
    
    /**
     * Properties that must never leave the server.
     *
     * Declared on the entity rather than at each call site so a field is named
     * once, next to the column it protects, and every route that serializes the
     * entity inherits it. Subclasses holding secrets override this.
     *
     * @var list<string>
     */
    protected $private_properties = [];

    /**
     * The entity as a plain array, safe to serialize into a response.
     *
     * This is what REST responses are built from -- see View\RestApi::setResponseData().
     *
     * @return array<string, mixed> column => value
     */
    public function getPublicProperties() {

        return $this->getProperties( $this->private_properties );
    }

    /**
     * @param  list<string> $drop_keys columns to leave out
     * @return array<string, mixed> column => value
     */
    public function getProperties( $drop_keys = [] ) {
        
        $properties = $this->_getProperties();
        
        if ( $drop_keys ) {
            
            foreach ($drop_keys as $key) {
                
                if (array_key_exists($key, $properties)) {
                    
                    unset($properties[$key]);
                }
            }
        }
        
        return $properties;
    }
    
    /**
     * Return Array or string of column names used for SQL queries - e.g. like " tablename.fieldname as namespace.fieldname"
     *
     * @param  bool   $return_as_string  If false array is returned
     * @param  string $as_namespace  Optional namespace for fields
     * @param  bool   $table_namespace
     * @return ($return_as_string is true ? string : list<string>)
     */
    public function getColumns($return_as_string = false, $as_namespace = '', $table_namespace = false) {
        
        $all_cols = array_flip(array_keys($this->properties));
        
        $new_cols = [];
        $ns = '';
        $as = '';
        
        // Asked for only when it is wanted. A cube entity is a shape until it
        // is bound to a Property (Entity\Event), and answering "what are this
        // shape's columns" must not depend on which table it happens to be
        // pointed at -- which is the question a build asks before it has one.
        if (!empty($table_namespace)):
            $ns = $this->getTableName().'.';
        endif;
                
        foreach ($all_cols as $k => $v) {
            
            if (!empty($as_namespace)):
                $as =  ' AS '.$as_namespace.$k;
            endif;
            
            $new_cols[] = $ns.$k.$as;
        }
        
        // add implode as string here
        
        if ($return_as_string == true):
            $new_cols = implode(', ', $new_cols);
        endif;
        
        return $new_cols;
        
    }
    
    /**
     * Sets object attributes
     *
     * @param  array<string, mixed> $array column => value; keys that are not columns are skipped
     * @param  bool $apply_filters
     * @return void
     */
    public function setProperties($array, $apply_filters = false) {
        
        $properties = $this->getColumns();
        
        foreach ($properties as $k => $v) {
            
            if ( array_key_exists( $v, $array ) ) {
                if ( ! empty( $this->properties ) ) {
                    $this->set($v, $this->applyStorageDefault( $v, $array[$v] ), $apply_filters, false);
                }
            }
        }
    }
    
    /**
     * Normalise a value that is really absence, on the way into a column.
     *
     * '   ' is three bytes that look like a value, sort like one and group as their
     * own row in a report, so a whitespace-only value is stored as NULL. The
     * pipeline trims before this is reached; this is the entity's own guard for a
     * caller handing setProperties() a value directly.
     *
     * @param  string $column
     * @param  mixed  $value
     * @return mixed  the value, or null where it was whitespace only
     */
    protected function applyStorageDefault( $column, $value ) {
        
        /*
         * false counts as absence here. Event::get() answers false for a key it
         * does not hold, so that is the shape an unset property actually arrives
         * in -- checking only '' and null left every column NULL and looked, from
         * the tests, exactly like success.
         */
        if ( $value !== '' && $value !== null && $value !== false
             && ! ( is_string( $value ) && trim( $value ) === '' ) ) {
            
            return $value;
        }
        
        if ( ! isset( $this->properties[ $column ] ) ) {
            
            return $value;
        }
        
        // Every other flavour of absence -- null, '', false -- is stored as
        // itself, and a column holding '' or NULL reads as absence everywhere.
        if ( is_string( $value ) && $value !== '' && trim( $value ) === '' ) {
            
            return null;
        }
        
        return $value;
    }
    
    /**
     * @param  string $name
     * @param  mixed  $value any type: a <name>SetFilter() method or the column decides what it keeps
     * @param  bool   $filter
     * @param  bool   $mark_dirty
     * @return void
     */
    public function set($name, $value, $filter = true, $mark_dirty = true ) {

        // Columns heal values as they arrive, and one of the things they heal
        // depends on the table's encoding -- so they have to know it before the
        // first value lands. Done here rather than in setCharacterEncoding()
        // because an entity may name its encoding either side of defining its
        // columns, and only this is guaranteed to run after both.
        $this->applyCharacterEncodingToColumns();

        if ( array_key_exists( $name, $this->properties ) ) {
            
            $existing_value = $this->get( $name );
            
            $method = $name.'SetFilter';
            
            if ( $filter && method_exists( $this, $method ) ) {
                
                $value = $this->$method( $value );
            }
            
            // A falsy value is stored when the COLUMN can legitimately hold one.
            //
            // By TYPE, because the two cases are not alike. On a numeric column 0 is a
            // value like any other. On a string column, '' is what a caller passes when it
            // has nothing -- several handlers rely on `set('medium', $maybeEmpty)` leaving
            // the existing value alone -- so storing empties there would wipe data.
            //
            // Numeric columns therefore accept 0/false; everything else ignores a falsy
            // value.
            if ( $value || $this->columnAcceptsFalsy( $name, $value ) ) {

                $this->properties[$name]->setValue( $value );

                // Loose, so '1' over 1 is not a change. Except across NULL: 0
                // over NULL compares equal and is a change all the same.
                $changed = ( $existing_value === null ) !== ( $value === null )
                    || $existing_value != $value;

                if ( $mark_dirty && $changed ) {
                    
                    $this->markDirty( $name, $value );
                }
            }
        }
    }
    
    /**
     * Empty a column, deliberately.
     *
     * set() discards '' on a string column, on purpose: several handlers rely
     * on `set('medium', $maybeEmpty)` leaving the existing value alone, and
     * blanket-storing empties there would wipe data that is currently kept.
     * This is how a caller says "this is now blank" -- an edit form whose
     * textarea the user emptied. It marks the column dirty so update() writes it, which
     * is the half set() cannot reach: its falsy guard wraps the markDirty call
     * as well as the assignment.
     *
     * The column then holds what create() writes for a column nobody set
     * (writeValue()): NULL where the column is nullable, 0 on a numeric
     * column, '' on a text one.
     *
     * @param  string $name
     * @return void
     */
    public function clear( $name ) {

        if ( ! array_key_exists( $name, $this->properties ) ) {

            return;
        }

        $this->properties[ $name ]->setValue( null );

        $empty = $this->writeValue( $name );

        $this->properties[ $name ]->setValue( $empty );
        $this->markDirty( $name, $empty );
    }

    /**
     * Whether a falsy value is a real value for this column.
     *
     * Reads the type the entity already declares -- DbColumn's second
     * constructor argument, e.g. new DbColumn('priority', OWA_DTD_INT).
     *
     * Numeric columns only. 0 and false are values there; on a VARCHAR, '' is
     * how callers say "I have nothing", and treating that as a value would blank
     * columns that are deliberately left alone.
     *
     * PORTABILITY
     * The comparison is against the OWA_DTD_* constants, never against the SQL
     * spellings they hold. That distinction is the point: OWA_DTD_BIGINT means
     * "a big integer" in every dialect, while its VALUE is whatever the loaded
     * dialect calls one -- 'BIGINT' here, something else elsewhere. The
     * constants are OWA's portable vocabulary; the strings are MySQL's.
     *
     * Matching the strings instead would break silently on a non-MySQL driver:
     * the type would fail to look numeric, falsy values would be dropped, and
     * nothing would report it.
     *
     * A dialect that introduces a numeric type of its own adds its constant to
     * numericColumnTypes(). Anything unrecognised is treated as non-numeric
     * rather than guessed.
     *
     * @param string $name
     * @param mixed  $value any type
     * @return bool
     */
    protected function columnAcceptsFalsy( $name, $value ) {

        if ( $value === null || $value === '' ) {

            return false;
        }

        if ( ! isset( $this->properties[ $name ] ) ) {

            return false;
        }

        $type = (string) $this->properties[ $name ]->get( 'data_type' );

        // The STRING '0' is falsy to PHP and a value to everyone else: a goal
        // condition "revenue greater than 0" stored its 0 as nothing, and
        // matched no event.
        if ( $value === '0' ) {

            return true;
        }

        if ( ! in_array( $type, $this->numericColumnTypes(), true ) ) {

            return false;
        }

        return is_int( $value ) || is_float( $value ) || is_bool( $value )
            || ( is_string( $value ) && is_numeric( $value ) );
    }

    /**
     * The declared column types that hold numbers, as the loaded dialect spells
     * them.
     *
     * Built from the constants rather than listing spellings, so this stays
     * correct for any dialect: each entry is whatever that dialect defined the
     * type as. Names a dialect does not define are skipped rather than assumed.
     *
     * @return list<string>
     */
    protected function numericColumnTypes() {

        $numeric = [];

        foreach ( [
            'OWA_DTD_BIGINT',
            'OWA_DTD_INT',
            'OWA_DTD_SMALLINT',
            'OWA_DTD_TINYINT',
            'OWA_DTD_TINYINT2',
            'OWA_DTD_TINYINT4',
            'OWA_DTD_SERIAL',
            'OWA_DTD_DECIMAL6_1',
            // Spelled TINYINT(1) in MySQL, so already covered there -- named
            // anyway, because a dialect with a real boolean type spells it
            // differently and 0/false must stay storable in it.
            'OWA_DTD_BOOLEAN',
        ] as $constant ) {

            if ( defined( $constant ) ) {

                $numeric[] = (string) constant( $constant );
            }
        }

        return array_values( array_unique( $numeric ) );
    }

    /**
     * @param  string $name
     * @param  mixed  $value the value set, any type
     * @return void
     */
    public function markDirty( $name, $value ) {
        
        $this->dirty[$name] = $value;
    }
    
    /**
     * @return true|null true when a column changed since the last write
     */
    public function isDirty() {
        
        if ( ! empty( $this->dirty ) ) {
            
            return true;
        }

        return null;
    }
    
    /**
     * @deprecated setProperties()
     *
     * @param  array<string, mixed> $values column => value
     * @return void
     */
    public function setValues($values) {
        
        $this->setProperties($values);
    }
    
    /**
     * @param  string $name
     * @param  bool   $filter
     * @return mixed the column's value, any type, through <name>GetFilter() when there is one; null for an undeclared column
     */
    public function get($name, $filter = true) {
        
        if (array_key_exists($name, $this->properties)) {
            $method = $name.'GetFilter';
            if ( $filter && method_exists($this, $method) ) {
                return $this->$method( $this->properties[$name]->getValue() );
            } else {
                return $this->properties[$name]->getValue();
            }
        }
    }
    
    /**
     * The options Db::createTable() builds the table clause from.
     *
     * Returns the whole set, which is what the caller indexes into. An entity
     * naming its own encoding (setCharacterEncoding()) is how a v2 table can be
     * created as utf8mb4 alongside v1 tables that stay utf8, in one database,
     * without converting anything.
     *
     * @return array{table_type: string, character_encoding?: string}
     */
    public function getTableOptions() {

        $options = [ 'table_type' => 'disk' ];

        if ( ! $this->_tableProperties ) {

            return $options;
        }

        if ( array_key_exists( 'table_type', $this->_tableProperties ) ) {

            $options['table_type'] = $this->_tableProperties['table_type'];
        }

        // Absent means "whatever this installation's default is", which is not
        // the same as a value -- Db::createTable() fills it in only when the key
        // is missing, so it must stay missing rather than arrive as null.
        if ( array_key_exists( 'character_encoding', $this->_tableProperties )
            && $this->_tableProperties['character_encoding'] ) {

            $options['character_encoding'] = $this->_tableProperties['character_encoding'];
        }

        return $options;
    }
    
    /**
     * The value to WRITE for a column, resolving an unset one by its declared type.
     *
     * create() sets EVERY column, so a column nobody assigned goes into the INSERT
     * explicitly. PDO binds a PHP null as a real NULL, while the rows written by
     * the interpolating driver hold '' or 0 for the same absence -- and each
     * distinct value is its own GROUP BY bucket, so a two-state fact would report
     * as three. This writes the stored representation those rows have, so old rows
     * and new rows agree.
     *
     * TIMESTAMP and anything unrecognised are deliberately left NULL: the
     * interpolating driver turned those into '0000-00-00', which is refused under
     * STRICT_ALL_TABLES.
     *
     * @param string $col column name
     * @return mixed the value to write
     */
    protected function writeValue( $col ) {

        $value = $this->get( $col, false );

        if ( $value !== null ) {

            return $value;
        }

        if ( ! isset( $this->properties[ $col ] ) ) {

            return $value;
        }

        $type = (string) $this->properties[ $col ]->get( 'data_type' );

        // Numeric: 0, as the interpolating driver stored ''.
        //
        // Unless the column declares itself nullable, as in the text branch below: a
        // nullable column has no rows of that shape to match, and 0 is a REAL number,
        // so a numeric column that cannot store NULL has no way to say "absent". A
        // beacon carrying no client clock is not reporting zero skew
        // (owa_event_raw.clock_offset_usec).
        if ( in_array( $type, $this->numericColumnTypes(), true ) ) {

            return ! empty( $this->properties[ $col ]->nullable ) ? null : 0;
        }

        // Character and binary: '', as the interpolating driver stored it.
        //
        // Unless the column declares itself nullable. Those store NULL for absence,
        // which is the form v2 uses and the one the reporting layer renders as
        // "(not set)".
        if ( in_array( $type, $this->textColumnTypes(), true ) ) {

            return ! empty( $this->properties[ $col ]->nullable ) ? null : '';
        }

        return $value;
    }

    /**
     * The declared column types that hold characters or bytes, as the loaded
     * dialect spells them.
     *
     * Derived from the constants for the same reason as numericColumnTypes():
     * a literal list of MySQL spellings would be a DDL grammar sitting in the
     * entity layer, and would stop matching -- silently -- on the first dialect
     * that names its string types differently.
     *
     * OWA_DTD_VARCHAR is deliberately excluded: its value is a sprintf template
     * ('VARCHAR(%s)'), never a type a column actually carries.
     *
     * @return list<string>
     */
    protected function textColumnTypes() {

        $text = [];

        foreach ( [
            'OWA_DTD_VARCHAR10',
            'OWA_DTD_VARCHAR16',
            'OWA_DTD_VARCHAR24',
            'OWA_DTD_VARCHAR32',
            'OWA_DTD_VARCHAR45',
            'OWA_DTD_VARCHAR64',
            'OWA_DTD_VARCHAR128',
            'OWA_DTD_VARCHAR255',
            'OWA_DTD_VARCHAR512',
            'OWA_DTD_VARCHAR1024',
            'OWA_DTD_CHAR2',
            'OWA_DTD_CHAR3',
            'OWA_DTD_TEXT',
            'OWA_DTD_BLOB',
            // A text type with validation on both MySQL and MariaDB, so an
            // unset value is written the same way as any other text column.
            'OWA_DTD_JSON',
        ] as $constant ) {

            if ( defined( $constant ) ) {

                $text[] = (string) constant( $constant );
            }
        }

        return array_values( array_unique( $text ) );
    }

    /**
     * Persist new object
     *
     * @return bool
     */
    public function create() {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $all_cols = $this->getColumns();

        $db->insertInto($this->getTableName());

        // Control loop
        foreach ($all_cols as $k => $v){

            // drop column is it is marked as auto-incement as DB will take care of that.
            if ($this->properties[$v]->auto_increment === true) {
                ;
            } else {

                $db->set($v, $this->writeValue($v));
            }

        }
    
        // Persist object
        $status = $db->executeQuery();
        
        // Add to Cache
        if ($status == true) {
            $this->addToCache();
            $this->dirty = [];
        }
        
        return $status;
    }
    
    /**
     * update() once persisted, create() before.
     *
     * @return bool
     */
    public function save() {
        
        if ( $this->wasPersisted ) {
            return $this->update();
        } else {
            return $this->create();
        }
        
    }
    
    /**
     * @param  string $col the column the row is cached under
     * @return void
     */
    public function addToCache($col = 'id') {
        
        if($this->isCachable()) {
            $cache = \OWA\Core\CoreAPI::cacheSingleton();
            // The row's values, not this object: a caller goes on changing
            // the object, and what it has not saved is not the row.
            $cache->set($this->getTableName(), $col.$this->get( $col ), $this->_getProperties());
        }
    }
    
    /**
     * Update all properties of an Existing object
     *
     * @param  string $where the column naming the row; '' for id
     * @return bool
     */
    public function update($where = '') {

        $key   = empty( $where ) ? 'id' : $where;
        $value = $this->get( $key );

        // Read before the UPDATE is built: the Db object builds one statement at a time.
        $before = $this->storedRowsIfCached( $key, $value );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->updateTable($this->getTableName());
        
        // get column list
        $all_cols = $this->getColumns();
        
        
        // Control loop
        foreach ($all_cols as $k => $v){
        
            // drop column is it is marked as auto-increment as DB will take care of that.
            
            // Truthy OR explicitly changed, so a legitimate falsy value set through
            // set() is written.
            //
            // The truthy test stays: some callers change a property without going through
            // set(), so those values are not marked dirty, and a dirty-only update would
            // stop persisting them. Same type resolution as create(): a column that IS
            // written must not land as NULL where the column has only a zero meaning. See
            // writeValue().
            if ($this->get($v, false) || array_key_exists($v, $this->dirty)) {
                $db->set($v, $this->writeValue($v));
            }
        }
        
        $db->where( $key, $value );

        // Persist object
        $status = $db->executeQuery();
        // Add to Cache
        if ($status === true) {
            $this->forgetCached( $before );
            $this->addToCache();
            $this->dirty = [];
        }
        
        return $status;
        
    }
    
    /**
     * The rows as stored that a write by $col = $value is about to change, or
     * none when this entity is not cached.
     *
     * @param  string $col
     * @param  mixed  $value as given to update() or delete(), any type
     * @return list<array<string, scalar|null>> column => value
     */
    protected function storedRowsIfCached( $col, $value ) {

        // Db::where() drops an empty value, which would read the whole table.
        if ( ! $this->isCachable() || \OWA\Core\Lib::isEmpty( $value ) ) {

            return [];
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $this->getTableName() );
        $db->selectColumn( '*' );
        $db->where( $col, $value );

        return (array) $db->getAllRows();
    }

    /**
     * Drop every cached copy of these rows.
     *
     * getByColumn() caches a row under the column it was looked up by, as
     * column . value: a site loaded by site_id and by id is cached twice. Every
     * column of each row is dropped, since any of them can have been the one a
     * lookup used.
     *
     * @param  list<array<string, scalar|null>> $rows as stored, before the write
     * @return void
     */
    protected function forgetCached( array $rows ) {

        if ( ! $rows || ! $this->isCachable() ) {

            return;
        }

        $cache = \OWA\Core\CoreAPI::cacheSingleton();

        foreach ( $rows as $row ) {

            foreach ( (array) $row as $col => $value ) {

                if ( is_scalar( $value ) && (string) $value !== '' ) {

                    $cache->remove( $this->getTableName(), $col . $value );
                }
            }
        }
    }
    
    
    /**
     * Delete Object
     *
     * @param  int|string $value the value $col holds; empty for this entity's id
     * @param  string     $col
     * @return bool
     */
    public function delete($value = '', $col = 'id') {

        if (empty($value)) {
            $value = $this->get('id');
        }

        // Nothing to name the row by. Db refuses the unfiltered DELETE this
        // would build too; refusing here says which entity asked.
        if ( \OWA\Core\Lib::isEmpty( $value ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Refusing to delete from %s: no %s given, from %s.',
                $this->getTableName(), $col, \OWA\Core\Lib::callSiteOutside( [ 'Entity.php' ] ) ) );

            return false;
        }

        $before = $this->storedRowsIfCached( $col, $value );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom($this->getTableName());
        
        $db->where($col, $value);

        $status = $db->executeQuery();
    
        // Delete from Cache
        if ( $status ){
            if ($this->isCachable()) {
                $cache = \OWA\Core\CoreAPI::cacheSingleton();
                $cache->remove($this->getTableName(), $col.$value);
                $this->forgetCached( $before );
            }
        }
        
        return $status;
        
    }
    
    /**
     * This entity from a row already fetched, as load() would have left it.
     *
     * For a caller that reads many rows in one query, rather than one query per
     * row to load each again by id.
     *
     * @param  array<string, scalar|null> $row column => value
     * @return $this
     */
    public function hydrate( array $row ) {

        $this->setProperties( $row );
        $this->wasPersisted = true;

        return $this;
    }

    /**
     * getByColumn() with the arguments in the order a lookup by id reads.
     *
     * @param  int|string        $value
     * @param  string            $col
     * @param  ColumnConstraints $constraints
     * @return void
     */
    public function load($value, $col = 'id', $constraints = []) {

        $this->getByColumn($col, $value, $constraints);
        
    }
    
    /**
     * @param  string            $col
     * @param  int|string        $value
     * @param  ColumnConstraints $constraints
     * @return void
     */
    public function getByPk($col, $value, $constraints = []) {
        
        $this->getByColumn($col, $value, $constraints);
        
    }
    
    /**
     * Fetch one row by a column value.
     *
     * $constraints narrows the query without changing which row is being asked
     * for -- it exists so that a partitioned table can be pruned, since pruning
     * reads only the partitioning column and a lookup by id names no date. It
     * is a hint: if nothing is found with it, the query is repeated without it
     * before reporting a miss.
     *
     * That retry is here rather than at the call sites on purpose: a caller
     * whose constraint is only a guess cannot be relied on to fall back.
     * existsWithin() is the lookup without the retry, for a constraint that
     * is exact.
     *
     * The cache key deliberately ignores $constraints: the answer is the same
     * row either way, so a bounded and an unbounded lookup share an entry.
     *
     * @param  string            $col
     * @param  int|string        $value
     * @param  ColumnConstraints $constraints
     * @return void
     * @throws \Exception when $col or $value is empty
     */
    public function getByColumn($col, $value, $constraints = []) {
                
        if ( ! $col ) {
            throw new \Exception("No column name passed.");
        }
        
        if ( ! $value ) {
            throw new \Exception("No value passed.");
        }
        
        $cache_obj = '';
        
        if ($this->isCachable()) {
            $cache = \OWA\Core\CoreAPI::cacheSingleton();
            $cache_obj = $cache->get($this->getTableName(), $col.$value);
        }
            
        // A row's values. Anything else is a miss, and the row is read again.
        if ( is_array( $cache_obj ) && $cache_obj ) {

            $this->setProperties( $cache_obj );
            $this->wasPersisted = true;
                    
        } else {
        
            $properties = $this->fetchOneRow($col, $value, $constraints);

            // The constraints only narrow where to look. Not finding a row
            // under them says nothing about whether one exists.
            if (empty($properties) && $constraints) {

                $properties = $this->fetchOneRow($col, $value, []);
            }

            if (!empty($properties)) {
                
                $this->setProperties($properties);
                $this->wasPersisted = true;
                // add to cache
                $this->addToCache($col);
            }
        }
    }

    /**
     * Whether a row with this column value exists within the constraints.
     *
     * One query, and the constraints bound it: unlike getByColumn(), a miss
     * under them is not repeated without them. For a caller whose constraint
     * is derived from the same input as the value, such as a raw row's
     * yyyymmdd and its id, where a miss is the usual answer and the repeat
     * would read every partition of the table.
     *
     * Not answered from the entity cache, and adds nothing to it.
     *
     * @param  string            $col
     * @param  int|string        $value
     * @param  ColumnConstraints $constraints
     * @return bool
     * @throws \Exception when $col or $value is empty
     */
    public function existsWithin( $col, $value, $constraints = [] ) {

        if ( ! $col ) {
            throw new \Exception( 'No column name passed.' );
        }

        if ( ! $value ) {
            throw new \Exception( 'No value passed.' );
        }

        return (bool) $this->fetchColumnWithin( $col, $value, $constraints );
    }

    /**
     * $col of one row by column value, narrowed. See existsWithin().
     *
     * @param  string            $col
     * @param  int|string        $value
     * @param  ColumnConstraints $constraints
     * @return array<string, scalar|null> $col => value; empty when no row
     */
    protected function fetchColumnWithin( $col, $value, $constraints = [] ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $this->getTableName() );
        $db->selectColumn( $col );
        $db->where( $col, $value );

        foreach ( $constraints as $name => $constraint ) {

            $db->where( $name, $constraint['value'], $constraint['operator'] );
        }

        return (array) $db->getOneRow();
    }

    /**
     * One row by column value, optionally narrowed. See getByColumn().
     *
     * @param  string            $col
     * @param  int|string        $value
     * @param  ColumnConstraints $constraints
     * @return array<string, scalar|null> column => value; empty when no row
     */
    protected function fetchOneRow($col, $value, $constraints = []) {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom($this->getTableName());
        $db->selectColumn('*');
        $db->where($col, $value);

        foreach ($constraints as $name => $constraint) {

            $db->where($name, $constraint['value'], $constraint['operator']);
        }

        return (array) $db->getOneRow();
    }

    /**
     * @return string the table's name; the class name before setTableName()
     */
    public function getTableName() {
        
        if ($this->_tableProperties) {
            return $this->_tableProperties['name'];
        } else {
            return get_class($this);
        }
        
    }
    
    /**
     * @return string|null null before setTableName()
     */
    public function getTableAlias() {
        
        if ($this->_tableProperties) {
            return $this->_tableProperties['alias'];
        }

        return null;
    }
    
    /**
     * @param  string $alias
     * @return void
     */
    public function setTableAlias( $alias ) {
    
        $this->_tableProperties['alias'] = $alias;
    }
    
    /**
     * The prefix of every OWA table. Not the `ns` setting, which prefixes
     * cookies and URL parameters: cubes and a few updates built table names
     * from it, so an install with another ns looked for its cubes under one
     * prefix and its raw table under this one.
     */
    const TABLE_PREFIX = 'owa_';

    /**
     * @param  string $name
     * @param  string $namespace the table name's prefix
     * @return void
     */
    public function setTableName($name, $namespace = self::TABLE_PREFIX) {

        $this->_tableProperties['alias'] = $name;
        $this->_tableProperties['name'] = $namespace.$name;
    }
    
    /**
     * Keep rows of this entity for the rest of the request once looked up.
     * See \OWA\Module\Base\Classes\Cache: there is no cache beyond the request.
     *
     * @return void
     */
    public function setCachable() {

        $this->_tableProperties['cacheable'] = true;
    }

    /**
     * @return bool
     */
    public function isCachable() {

        return ! empty( $this->_tableProperties['cacheable'] );
    }
    
    /**
     * @param  string $col
     * @return void
     */
    public function setPrimaryKey($col) {
        //backwards compatability
        $this->properties[$col]->setPrimaryKey();
        $this->_tableProperties['primary_key'] = $col;
        
    }
        
    /**
     * Create Table
     *
     * Handled by DB abstraction layer because the SQL associated with this is way too DB specific
     *
     * @return bool
     */
    public function createTable() {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        // Persist table
        $status = $db->createTable($this);
        
        if ($status == true):
            \OWA\Core\CoreAPI::notice(sprintf("%s Table Created.", $this->getTableName()));
            return true;
        else:
            \OWA\Core\CoreAPI::notice(sprintf("%s Table Creation Failed.", $this->getTableName()));
            return false;
        endif;
    
    }
    
    /**
     * DROP TABLE IF EXISTS: a table that is not there is not an error.
     *
     * @return bool
     */
    public function dropTable() {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        // Persist table
        $status = $db->dropTable($this->getTableName());
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
    
    }
    
    /**
     * Adds a declared column to the table this entity is already persisted as.
     *
     * The index, when the column declares one, is a SECOND statement. MySQL
     * will not take `ALTER TABLE t ADD col VARCHAR(255), INDEX (col)` -- the
     * index clause is legal only inside a CREATE TABLE's column list.
     *
     * @param  string $column_name
     * @return bool
     */
    public function addColumn($column_name) {
        
        $def = $this->getColumnDefinition($column_name);
        // Persist table
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->addColumn($this->getTableName(), $column_name, $def);
        
        if (! $status) {
            return false;
        }
        
        if ($this->isColumnIndexed($column_name)) {
            // addIndex() is itself a no-op when the index is already there, so
            // a re-run after a half-finished ALTER finishes the job.
            return (bool) $db->addIndex($this->getTableName(), $column_name);
        }
        
        return true;
    }
    
    /**
     * @param  string $column_name
     * @return bool
     */
    public function dropColumn($column_name) {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->dropColumn($this->getTableName(), $column_name);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
        
    }
    
    /**
     * @param  string $column_name
     * @return bool
     */
    public function modifyColumn($column_name) {
    
        $def = $this->getColumnDefinition($column_name);
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->modifyColumn($this->getTableName(), $column_name, $def);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
    
    
    }
    
    /**
     * @param  string $old_column_name
     * @param  string $column_name
     * @param  bool   $use_old_column_for_defs define it from the column declared under the old name
     * @return bool
     */
    public function renameColumn($old_column_name, $column_name, $use_old_column_for_defs = false) {
    
        if ($use_old_column_for_defs) {
            $def = $this->getColumnDefinition($old_column_name);
        } else {
            $def = $this->getColumnDefinition($column_name);
        }
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->renameColumn($this->getTableName(), $old_column_name, $column_name, $def);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
        
    }
    
    /**
     * @param  string $new_table_name
     * @return bool
     */
    public function renameTable($new_table_name) {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->renameTable($this->getTableName(), $new_table_name);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
    }
    
    /**
     * @param  string $column_name
     * @param  bool   $omit_primary_key
     * @return string
     */
    public function getColumnDefinition($column_name, $omit_primary_key = false) {
    
        return $this->getColumn($column_name)->getDefinition($omit_primary_key);
    }
    
    /**
     * Whether a column declared an index.
     *
     * Separate from its definition because an index is a table-level clause:
     * createTable() may write it inline, an ALTER may not. See
     * DbColumn::getDefinition().
     *
     * @param  string $column_name
     * @return bool
     */
    public function isColumnIndexed($column_name) {
    
        return $this->getColumn($column_name)->isIndexed();
    }
    
    /**
     * The DbColumn object behind a column name.
     *
     * REFUSES A COLUMN THE ENTITY DOES NOT DECLARE, by name, because the two
     * callers that reach here with one are both migrations and both mean the
     * same mistake.
     *
     * An update that DROPS a column has a down() which must put it back -- and
     * by then the entity no longer declares it, because removing it is what
     * up() did. So addColumn()/addColumnIfMissing() ask here for a definition
     * that cannot exist, and the upgrade cycle is the only job that runs it.
     *
     * The fix is always the same and the message says it: a down() restoring a
     * removed column spells out its own type, the way Update039 and Update046
     * do.
     *
     * @param string $column_name
     * @return \OWA\Module\Base\Classes\DbColumn
     * @throws \InvalidArgumentException
     */
    public function getColumn($column_name) {
    
        if (empty($this->properties)) {
            return $this->$column_name;
        }

        if ( ! array_key_exists( $column_name, $this->properties ) ) {

            throw new \InvalidArgumentException( sprintf(
                '%s does not declare a column called "%s". If this is an update\'s '
              . 'down() restoring a column its up() removed, the entity cannot '
              . 'describe it any more -- spell the type out in the update itself.',
                get_class( $this ), $column_name ) );
        }

        return $this->properties[$column_name];
    }
    
    /**
     * The column declared as the primary key, if any.
     *
     * @return string|null
     */
    public function getPrimaryKeyColumn() {
        
        if (isset($this->_tableProperties['primary_key'])) {
            return $this->_tableProperties['primary_key'];
        }
        
        // Entities declare the key on the column rather than the table, so ask
        // the columns which one claims it.
        foreach ((array) $this->properties as $name => $col) {
            
            if (is_object($col) && $col->get('is_primary_key')) {
                return $name;
            }
        }
        
        return null;
    }
    
    /**
     * Partition this entity's table by range on a column.
     *
     * The column has to be part of every unique key, so declaring it also makes
     * the primary key composite -- see Db::createTable().
     *
     * @param  string $column
     * @return void
     */
    public function setPartitionColumn($column) {
        
        $this->_tableProperties['partition_column'] = $column;
    }

    /**
     * Declare a multi-column index on this entity's table.
     *
     * DbColumn::setIndex() covers the single-column case and cannot express
     * this one: an index over (site_id, yyyymmdd) is one object belonging to the
     * table, not a property of either column. Two single-column indexes are not
     * a substitute -- MySQL uses one of them and then filters, where the
     * composite seeks straight to the range.
     *
     * The name is explicit rather than generated because dropping an index
     * needs it: Db::dropIndex() takes the INDEX name, not a column list, so a
     * generated name would have to be regenerated identically by every update
     * that ever removes one.
     *
     * @param  string       $name    index name, unique within the table
     * @param  list<string> $columns column names, in key order -- order is the index
     * @return void
     */
    public function addCompositeIndex( $name, array $columns ) {
        
        $this->_tableProperties['composite_indexes'][ $name ] = $columns;
    }
    
    /**
     * Declare a UNIQUE index, by name.
     *
     * For a table whose clustered key is not the column it is unique on: the
     * primary key orders the rows on disk, so a random natural key belongs in
     * a secondary index and an ascending surrogate in the primary one.
     *
     * @param  string       $name
     * @param  list<string> $columns
     * @return void
     */
    public function addUniqueIndex( $name, array $columns ) {

        $this->_tableProperties['unique_indexes'][ $name ] = $columns;
    }

    /**
     * Declare a primary key over several columns, in key order.
     *
     * For a table whose rows are identified by what they count, such as
     * owa_spam_block's (day, Profile, rule), where a derived id column would
     * be a second spelling of the same three values. Db::createTable() emits
     * it at table level; no column may also call DbColumn::setPrimaryKey().
     * Not for a partitioned table, whose key createTable() builds itself.
     *
     * @param  list<string> $columns
     * @return void
     */
    public function setCompositePrimaryKey( array $columns ) {

        $this->_tableProperties['composite_primary_key'] = array_values( $columns );
    }

    /**
     * The columns of a composite primary key, or none.
     *
     * @return list<string>
     */
    public function getCompositePrimaryKey() {

        return isset( $this->_tableProperties['composite_primary_key'] )
            ? $this->_tableProperties['composite_primary_key']
            : [];
    }

    /**
     * The UNIQUE indexes declared on this entity.
     *
     * @return array<string, list<string>> name => columns
     */
    public function getUniqueIndexes() {

        return isset( $this->_tableProperties['unique_indexes'] )
            ? $this->_tableProperties['unique_indexes']
            : [];
    }

    /**
     * The multi-column indexes declared on this entity.
     *
     * @return array<string, list<string>> name => columns
     */
    public function getCompositeIndexes() {
        
        return isset( $this->_tableProperties['composite_indexes'] )
            ? $this->_tableProperties['composite_indexes']
            : [];
    }
    
    /**
     * The column this entity's table is partitioned by, if any.
     *
     * @return string|null
     */
    public function getPartitionColumn() {
        
        return isset($this->_tableProperties['partition_column']) ? $this->_tableProperties['partition_column'] : null;
    }

    /**
     * How much of this table's lead is DAILY, where that differs from the rest.
     *
     * Zero for every ordinary table: a fact table holds one granularity across
     * the whole of its lead. A reporting cube holds a mix -- the front daily so
     * a rebuild is a day rather than a month -- and overrides this.
     *
     * Declared here beside the partition column rather than discovered with
     * method_exists(), so "is this a cube" is a question every partitioned
     * entity answers rather than one that depends on which class happens to
     * define a method.
     *
     * @return int months
     */
    public function getDailyLeadMonths() {

        return 0;
    }

    /**
     * How many partitions this table's lead needs.
     *
     * ASKED OF THE TABLE, because the answer differs by kind and the caller
     * cannot know it: a cube wants about 73 -- two months of daily plus the
     * coarse remainder -- where an ordinary fact table wants a dozen. Dividing
     * one allowance equally between them gives the small tables far more than
     * they use and the cube less than it needs, and refuses configurations the
     * server can hold.
     *
     * The lead only. History is compaction's business, and what it leaves
     * depends on how much there is.
     *
     * @param string $granularity  what the rest of the lead is cut at
     * @return int  partitions, including the catch-all
     */
    public function partitionsNeeded($granularity = 'monthly') {

        if (!$this->getPartitionColumn()) {

            return 0;
        }

        $daily = (int) $this->getDailyLeadMonths();

        // +1 for pmax, which every partitioned table carries.
        return count(\OWA\Core\Db::makeLeadRanges($daily, $granularity)) + 1;
    }
    
    /**
     * Declare a column.
     *
     * @param  \OWA\Module\Base\Classes\DbColumn $obj
     * @return void
     */
    public function setProperty($obj) {
        
        $this->properties[$obj->get('name')] = $obj;
    }
    
    /**
     * @param  string $name
     * @return \OWA\Module\Base\Classes\DbColumn|null null for an undeclared column
     */
    public function getProperty($name) {
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }

        return null;
    }
    
    /**
     * Create guid from string
     *
     * @param     string $string
     * @return     int|string|null see Lib::setStringGuid()
     */
    public function generateId($string) {
        return \OWA\Core\Lib::setStringGuid($string);
    }

    
    /**
     * @return string|null see $name
     */
    public function getName() {
        
        return $this->name;
    }
    
    /**
     * @param  string|null $encoding null for the installation's default
     * @return void
     */
    public function setCharacterEncoding($encoding) {

        $this->_tableProperties['character_encoding'] = $encoding;

        // Any column already defined is now stale; re-run the propagation.
        $this->_character_encoding_applied = false;
    }

    /**
     * The encoding this entity's table is in, or null for the default.
     *
     * @return string|null
     */
    public function getCharacterEncoding() {

        return isset( $this->_tableProperties['character_encoding'] )
            ? $this->_tableProperties['character_encoding']
            : null;
    }

    /**
     * Tell this entity's columns what encoding they are being stored in.
     *
     * Only matters for an entity that names one: a utf8mb4 table's columns must
     * not have four-byte characters stripped out of them because the
     * INSTALLATION default is still utf8. Without this the healing would read
     * the wrong answer for exactly the tables that do not need it.
     *
     * Runs once per change rather than on every set().
     *
     * @return void
     */
    protected function applyCharacterEncodingToColumns() {

        if ( $this->_character_encoding_applied ) {

            return;
        }

        $this->_character_encoding_applied = true;

        $encoding = $this->getCharacterEncoding();

        if ( ! $encoding ) {

            return;
        }

        foreach ( $this->properties as $column ) {

            if ( is_object( $column ) && method_exists( $column, 'setCharacterEncoding' ) ) {

                $column->setCharacterEncoding( $encoding );
            }
        }
    }
    
    /**
     * @return bool|null see $wasPersisted
     */
    public function wasPersisted() {
        return $this->wasPersisted;
    }
}
