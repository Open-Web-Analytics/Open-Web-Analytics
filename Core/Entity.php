<?php
namespace OWA\Core;


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
// $Id$
//


/**
 * Abstract Entity Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

// TODO: replace with explicit property declarations; entities set columns as
// dynamic properties (deprecated in PHP 8.2).
#[\AllowDynamicProperties]
class Entity {

    var $name;
    var $properties = array();
    var $_tableProperties = array();

    /**
     * Whether the table encoding has been handed down to the columns yet.
     *
     * @var bool
     */
    protected $_character_encoding_applied = false;
    var $wasPersisted;
    var $cache;
    var $dirty = [];
    
    function init() {
        
        // get the full property list
        $properties = $this->getEntityPropertyList();
        
        foreach ( $properties as $col_name => $col_props ) {
            
            // create the column obj with the proper name and data type
            $col = new \OWA\Module\Base\Classes\DbColumn( $col_name, $col_props['dtd'] );
            
            // Evaluate the type of column that needs to be created
            if ( array_key_exists( 'type', $col_props) ) {
                
                switch ( $col_props['type'] ) {
                    
                    case 'primary_key':
                        
                        $col->setPrimaryKey();
                        
                        break;
                        
                    case 'foreign_key':
                        
                        if ( array_key_exists( 'linked_entity', $col_props ) && ! empty( $col_props['linked_entity'] ) ) {
                            
                            $col->setForeignKey( $col_props['linked_entity'] );
                        }
                        
                        break;
                }
            }
            
            // should an index be created for the column?
            if ( array_key_exists('index', $col_props ) ) {
                
                switch ( $col_props['index'] ) {
                    
                    case true:
                        
                        $col->setindex();
                        
                        break;
                }
            }
            
            // add the full configured col to entity property list
            $this->setProperty( $col );
        }
    }
    
    
    function _getProperties() {
        
        $properties = array();
        
        if (!empty($this->properties)) {
            $vars = $this->properties;
        }
        
        foreach ($vars as $k => $v) {
            
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
     * @var array
     */
    protected $private_properties = [];

    /**
     * The entity as a plain array, safe to serialize into a response.
     *
     * This is what REST responses are built from -- see View\RestApi::setResponseData().
     *
     * @return array
     */
    public function getPublicProperties() {

	    return $this->getProperties( $this->private_properties );
    }

    function getProperties( $drop_keys = [] ) {
	    
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
     * @param boolean $return_as_string  If false array is returned
     * @param string $as_namespace  Optional namespace for fields
     * @param boolean $table_namespace
     */
    public function getColumns($return_as_string = false, $as_namespace = '', $table_namespace = false) {
        
        if (!empty($this->properties)) {
            $all_cols = array_keys($this->properties);
            $all_cols = array_flip($all_cols);
        }
        
        //print_r($all_cols);
        
        $table = $this->getTableName();
        $new_cols = array();
        $ns = '';
        $as = '';
        
        if (!empty($table_namespace)):
            $ns = $table.'.';
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
        
        //print_r($new_cols);
        return $new_cols;
        
    }
    
    /**
     * Sets object attributes
     *
     * @param mixed $array
     */
    function setProperties($array, $apply_filters = false) {
        
        $properties = $this->getColumns();
        
        foreach ($properties as $k => $v) {
                
            //if ( ! empty( $array[$v] ) ) {
            if ( array_key_exists( $v, $array ) ) {
                if ( ! empty( $this->properties ) ) {
                    $this->set($v, $array[$v], $apply_filters, false);
                }
            }
        }
    }
    
    function setGuid($string) {
        
        return \OWA\Core\Lib::setStringGuid($string);
        
    }
    
    function set($name, $value, $filter = true, $mark_dirty = true ) {

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
            // This guard used to be a bare `if ( $value )`, so 0, false and ''
            // were all discarded silently -- setting a numeric column to 0 did
            // nothing at all, and the caller had no way to tell. That is why
            // SessionHandlers once wrote the STRING 'false' into is_bounce: a
            // truthy value was the only kind that survived, and MySQL coerced it
            // to 0 on the way in.
            //
            // Widened by TYPE rather than removed, because the two cases are not
            // alike. On a numeric column 0 is a value like any other. On a string
            // column, '' is what a caller passes when it has nothing -- several
            // handlers rely on `set('medium', $maybeEmpty)` leaving the existing
            // value alone -- so blanket-storing empties there would start wiping
            // data that is currently preserved.
            //
            // Numeric columns therefore accept 0/false; everything else keeps
            // the old behaviour exactly.
            if ( $value || $this->columnAcceptsFalsy( $name, $value ) ) {

	            $this->properties[$name]->setValue( $value );
	            
	            if ( $mark_dirty && $existing_value != $value ) {
		            
		            $this->markDirty( $name, $value );
	            }
	        }
        }
    }
    
    /**
     * Whether a falsy value is a real value for this column.
     *
     * Reads the type the entity already declares -- DbColumn's second
     * constructor argument, e.g. new DbColumn('priority', OWA_DTD_INT) -- so no
     * entity needs changing to get this. Every column in the codebase already
     * carries one.
     *
     * Numeric columns only. 0 and false are values there; on a VARCHAR, '' is
     * how callers say "I have nothing", and treating that as a value would blank
     * columns that are deliberately left alone today.
     *
     * PORTABILITY
     * The comparison is against the OWA_DTD_* constants, never against the SQL
     * spellings they hold. That distinction is the point: OWA_DTD_BIGINT means
     * "a big integer" in every dialect, while its VALUE is whatever the loaded
     * dialect calls one -- 'BIGINT' here, something else elsewhere. The
     * constants are OWA's portable vocabulary; the strings are MySQL's.
     *
     * Matching the strings instead would have re-broken this silently on the
     * first non-MySQL driver: the type would fail to look numeric, falsy values
     * would go back to being dropped, and nothing would report it.
     *
     * A dialect that introduces a numeric type of its own adds its constant to
     * numericColumnTypes(). Anything unrecognised keeps the old conservative
     * behaviour rather than guessing.
     *
     * @param string $name
     * @param mixed  $value
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
     * @return array
     */
    protected function numericColumnTypes() {

        $numeric = array();

        foreach ( array(
            'OWA_DTD_BIGINT',
            'OWA_DTD_INT',
            'OWA_DTD_TINYINT',
            'OWA_DTD_TINYINT2',
            'OWA_DTD_TINYINT4',
            'OWA_DTD_SERIAL',
            // Spelled TINYINT(1) in MySQL, so already covered there -- named
            // anyway, because a dialect with a real boolean type spells it
            // differently and 0/false must stay storable in it.
            'OWA_DTD_BOOLEAN',
        ) as $constant ) {

            if ( defined( $constant ) ) {

                $numeric[] = (string) constant( $constant );
            }
        }

        return array_values( array_unique( $numeric ) );
    }

    function markDirty( $name, $value ) {
	    
	    $this->dirty[$name] = $value;
    }
    
    function isDirty() {
	    
	    if ( ! empty( $this->dirty ) ) {
		    
		    return true;
	    }
    }
    
    // depricated
    function setValues($values) {
        
        return $this->setProperties($values);
    }
    
    function get($name, $filter = true) {
        
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
     * Returns the whole set, which is what the caller indexes into. It used to
     * return the VALUE of table_type whenever one was set -- a bare string
     * where an array was expected -- so any second option was unreachable by
     * construction, and setCharacterEncoding() has been inert since it was
     * written: it stores an encoding that nothing ever reads back.
     *
     * That matters now rather than as tidying. An entity naming its own
     * encoding is how a v2 table can be created as utf8mb4 alongside v1 tables
     * that stay utf8, in one database, without converting anything. The
     * connection is already the wider encoding (see MysqlDialect), so the only
     * thing standing in the way was this.
     *
     * @return array
     */
    function getTableOptions() {

        $options = array( 'table_type' => 'disk' );

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
     * Persist new object
     *
     */
    /**
     * The value to WRITE for a column, resolving an unset one by its declared type.
     *
     * create() sets EVERY column, so a column nobody assigned goes into the
     * INSERT explicitly. Until the PDO driver landed (#1028) that did not
     * matter: the old driver interpolated values as quoted literals, so a PHP
     * null became '' and MySQL -- with sql_mode empty -- coerced '' to 0 for a
     * numeric column. Eleven years of rows were written that way.
     *
     * PDO binds the null instead, so the same code now stores a real NULL. On
     * demo that flipped ~70 session columns and ~14 request columns from 0 to
     * NULL overnight (2026-08-21), including is_repeat_visitor, every goal_N,
     * and every commerce_*. Each distinct value is its own GROUP BY bucket, so
     * a two-state fact started reporting as three.
     *
     * This restores the pre-#1028 STORED REPRESENTATION rather than inventing a
     * new one -- the point is that old rows and new rows agree, which is what
     * anything reading across the boundary depends on.
     *
     * TIMESTAMP and anything unrecognised are deliberately left NULL: the old
     * path turned those into '0000-00-00', which is not a value worth
     * restoring and is refused outright under STRICT_ALL_TABLES.
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

        // Numeric: '' coerced to 0 under the old driver.
        if ( in_array( $type, $this->numericColumnTypes(), true ) ) {

            return 0;
        }

        // Character and binary: '' stayed '' under the old driver.
        if ( in_array( $type, $this->textColumnTypes(), true ) ) {

            return '';
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
     * @return array
     */
    protected function textColumnTypes() {

        $text = array();

        foreach ( array(
            'OWA_DTD_VARCHAR10',
            'OWA_DTD_VARCHAR255',
            'OWA_DTD_TEXT',
            'OWA_DTD_BLOB',
        ) as $constant ) {

            if ( defined( $constant ) ) {

                $text[] = (string) constant( $constant );
            }
        }

        return array_values( array_unique( $text ) );
    }

    function create() {

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
    
    function save() {
        
        if ( $this->wasPersisted ) {
            return $this->update();
        } else {
            return $this->create();
        }
        
    }
    
    function addToCache($col = 'id') {
        
        if($this->isCachable()) {
            $cache = \OWA\Core\CoreAPI::cacheSingleton();
            $cache->setCollectionExpirationPeriod($this->getTableName(), $this->getCacheExpirationPeriod());
            $cache->set($this->getTableName(), $col.$this->get( $col ), $this, $this->getCacheExpirationPeriod());
        }
    }
    
    /**
     * Update all properties of an Existing object
     *
     */
    function update($where = '') {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->updateTable($this->getTableName());
        
        // get column list
        $all_cols = $this->getColumns();
        
        
        // Control loop
        foreach ($all_cols as $k => $v){
        
            // drop column is it is marked as auto-increment as DB will take care of that.
            
            // Truthy OR explicitly changed.
            //
            // The truthy test alone could never write a 0, which is why a
            // legitimate falsy value could not be persisted through an entity at
            // all. Dirty tracking already existed (markDirty, populated by set())
            // and was simply never consulted here.
            //
            // ADDED to the truthy test rather than replacing it: some callers
            // change a property without going through set(), so those values are
            // not marked dirty, and a dirty-only update would silently stop
            // persisting them. Every column written before is still written.
            // Same type resolution as create(): a column that IS written must
            // not land as NULL where the column has only a zero meaning. See
            // writeValue().
            if ($this->get($v, false) || array_key_exists($v, $this->dirty)) {
                $db->set($v, $this->writeValue($v));
            }
        }
        
        if(empty($where)):
            $id = $this->get('id');
            $db->where('id', $id);
            
        else:
            $db->where($where, $this->get($where));
        endif;
        
        // Persist object
        $status = $db->executeQuery();
        // Add to Cache
        if ($status === true) {
            $this->addToCache();
            $this->dirty = [];
        }
        
        return $status;
        
    }
    
    /**
     * Update named list of properties of an existing object
     *
     * @param array $named_properties
     * @param array $where
     * @return boolean
     */
    function partialUpdate($named_properties, $where) {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->updateTable($this->getTableName());
        
        foreach ($named_properties as $v) {
            
            if ($this->get($v)){
                $db->set($v, $this->get($v));
            }
        }
        
        if(empty($where)):
            $db->where('id', $this->get('id'));
        else:
            $db->where($where, $this->get($where));
        endif;
        
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
     * Delete Object
     *
     */
    function delete($value = '', $col = 'id') {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom($this->getTableName());
        
        if (empty($value)) {
            $value = $this->get('id');
        }
        
        $db->where($col, $value);

        $status = $db->executeQuery();
    
        // Delete from Cache
        if ( $status ){
            if ($this->isCachable()) {
                \OWA\Core\CoreAPI::debug('about to remove from cache');
                $cache = \OWA\Core\CoreAPI::cacheSingleton();
                $cache->remove($this->getTableName(), $col.$value);
            }
        }
        
        return $status;
        
    }
    
    function load($value, $col = 'id', $constraints = array()) {

        return $this->getByColumn($col, $value, $constraints);
        
    }
    
    function getByPk($col, $value, $constraints = array()) {
        
        return $this->getByColumn($col, $value, $constraints);
        
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
     * That retry is here rather than at the call sites on purpose. A miss on a
     * session lookup is not a slow path, it is a duplicate session, so the
     * safety cannot depend on every caller remembering to fall back.
     *
     * The cache key deliberately ignores $constraints: the answer is the same
     * row either way, so a bounded and an unbounded lookup share an entry.
     *
     * @param string $col
     * @param mixed  $value
     * @param array  $constraints  name => ['value' => mixed, 'operator' => string]
     */
    function getByColumn($col, $value, $constraints = array()) {
                
        if ( ! $col ) {
            throw new \Exception("No column name passed.");
        }
        
        if ( ! $value ) {
            throw new \Exception("No value passed.");
        }
        
        $cache_obj = '';
        
        if ($this->isCachable()) {
            $cache = \OWA\Core\CoreAPI::cacheSingleton();
            $cache->setCollectionExpirationPeriod($this->getTableName(), $this->getCacheExpirationPeriod());
            $cache_obj = $cache->get($this->getTableName(), $col.$value);
        }
            
        if (!empty($cache_obj)) {
        
            $cache_obj_properties = $cache_obj->_getProperties();
            $this->setProperties($cache_obj_properties);
            $this->wasPersisted = true;
                    
        } else {
        
            $properties = $this->fetchOneRow($col, $value, $constraints);

            // The constraints only narrow where to look. Not finding a row
            // under them says nothing about whether one exists.
            if (empty($properties) && $constraints) {

                $properties = $this->fetchOneRow($col, $value, array());
            }

            if (!empty($properties)) {
                
                $this->setProperties($properties);
                $this->wasPersisted = true;
                // add to cache
                $this->addToCache($col);
                \OWA\Core\CoreAPI::debug('entity loaded from db');
            }
        }
    }

    /**
     * One row by column value, optionally narrowed. See getByColumn().
     *
     * @param string $col
     * @param mixed  $value
     * @param array  $constraints
     * @return array
     */
    protected function fetchOneRow($col, $value, $constraints = array()) {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom($this->getTableName());
        $db->selectColumn('*');
        \OWA\Core\CoreAPI::debug("Col: $col, value: $value");
        $db->where($col, $value);

        foreach ($constraints as $name => $constraint) {

            $db->where($name, $constraint['value'], $constraint['operator']);
        }

        return (array) $db->getOneRow();
    }

    function getTableName() {
        
        if ($this->_tableProperties) {
            return $this->_tableProperties['name'];
        } else {
            return get_class($this);
        }
        
    }
    
    function getTableAlias() {
        
        if ($this->_tableProperties) {
            return $this->_tableProperties['alias'];
        }
    }
    
    function setTableAlias( $alias ) {
    
        $this->_tableProperties['alias'] = $alias;
    }
    
    function setTableName($name, $namespace = 'owa_') {

        $this->_tableProperties['alias'] = $name;
        $this->_tableProperties['name'] = $namespace.$name;
    }
    
    /**
     * Sets the entity as cachable for some period of time
     *
     * @todo    make this use the getSetting method but that requires a refactoring of
     *            the entity abstract class to not use an entity in it's constructor
     */
    function setCachable($seconds = '') {
    
        $this->_tableProperties['cacheable'] = true;
        
        // set cache expiration period
        if (!$seconds) {
            // remove hard coded value. fix this see note above.
            //$seconds = owa_coreAPI::getSetting('base', 'default_cache_expiration_period');
            $seconds = 604800;
        }
        
        $this->setCacheExpirationPeriod($seconds);
    }
    
    function isCachable() {
        
        //if (owa_coreAPI::getSetting('base', 'cache_objects')) {
            if (array_key_exists('cacheable', $this->_tableProperties)) {
                return $this->_tableProperties['cacheable'];
            //}
        } else {
            return false;
        }
        
    }
    
    function setPrimaryKey($col) {
        //backwards compatability
        $this->properties[$col]->setPrimaryKey();
        $this->_tableProperties['primary_key'] = $col;
        
    }
        
    function getForeignKeyColumn($entity) {
        if (array_key_exists('relatedEntities', $this->_tableProperties)) {
            if (array_key_exists($entity, $this->_tableProperties['relatedEntities'])) {
                return $this->_tableProperties['relatedEntities'][$entity];
            }
        }
    }
    
    function isForeignKeyColumn($col) {
    
        if (array_key_exists($col, $this->properties)) {
            return $this->properties[$col]->isForeignKey();
        }
    }
    
    function getAllForeignKeys() {
        
        return;
    }
    
    /**
     * Create Table
     *
     * Handled by DB abstraction layer because the SQL associated with this is way too DB specific
     */
    function createTable() {
        
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
     * DROP Table
     *
     * Drops a table. will throw error is table does not exist
     */
    function dropTable() {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        // Persist table
        $status = $db->dropTable($this->getTableName());
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
    
    }
    
    function addColumn($column_name) {
        
        $def = $this->getColumnDefinition($column_name);
        // Persist table
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->addColumn($this->getTableName(), $column_name, $def);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
        
    }
    
    function dropColumn($column_name) {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->dropColumn($this->getTableName(), $column_name);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
        
    }
    
    function modifyColumn($column_name) {
    
        $def = $this->getColumnDefinition($column_name);
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->modifyColumn($this->getTableName(), $column_name, $def);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
    
    
    }
    
    function renameColumn($old_column_name, $column_name, $use_old_column_for_defs = false) {
    
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
    
    function renameTable($new_table_name) {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $status = $db->renameTable($this->getTableName(), $new_table_name);
        
        if ($status == true):
            return true;
        else:
            return false;
        endif;
        return;
    }
    
    function getColumnDefinition($column_name, $omit_primary_key = false) {
    
        if (empty($this->properties)) {
            return $this->$column_name->getDefinition($omit_primary_key);
        } else {
            return $this->properties[$column_name]->getDefinition($omit_primary_key);
        }
    }
    
    /**
     * The column declared as the primary key, if any.
     *
     * @return string|null
     */
    function getPrimaryKeyColumn() {
        
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
     * @param string $column
     */
    function setPartitionColumn($column) {
        
        $this->_tableProperties['partition_column'] = $column;
    }
    
    /**
     * The column this entity's table is partitioned by, if any.
     *
     * @return string|null
     */
    function getPartitionColumn() {
        
        return isset($this->_tableProperties['partition_column']) ? $this->_tableProperties['partition_column'] : null;
    }
    
    function setProperty($obj) {
        
        $this->properties[$obj->get('name')] = $obj;
        
        if ($obj->isForeignKey()) {
            $fk = $obj->getForeignKey();
            
            $this->_tableProperties['relatedEntities'][$fk[0]] = $obj->getName();
            $this->_tableProperties['foreign_keys'][$obj->getName()] = $fk[0];
        }
        
    }
    
    function getProperty($name) {
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }
    }
    
    function generateRandomUid($seed = '') {
        
        return \OWA\Core\Lib::generateRandomUid();
         
        //return crc32($_SERVER['SERVER_ADDR'].$_SERVER['SERVER_NAME'].getmypid().$this->getTableName().microtime().$seed.rand());
    }
    
    /**
     * Create guid from string
     *
     * @param     string $string
     * @return     integer
     */
    function generateId($string) {
        //require_once(OWA_DIR.'owa_lib.php');
        return \OWA\Core\Lib::setStringGuid($string);
    }

    /**
     * Report a dimension row that was found by a content-derived id but does
     * NOT hold that content.
     *
     * Dimension handlers all share one shape: derive an id from the content,
     * load the row at that id, and if a row comes back, reuse it. That last
     * step silently assumes the row IS the content it was derived from, which
     * is true right up until two different values hash to the same id. Then the
     * fact row's foreign key points at somebody else's dimension, and the two
     * are merged in every report that touches them, permanently and invisibly.
     *
     * Widening the hash makes this rare rather than impossible: at 63 bits, a
     * table of ten million dimension rows carries roughly a 0.0005% chance of
     * one collision. Rare and silent is a bad combination, so it is worth one
     * comparison to turn it into something that leaves a trace.
     *
     * Deliberately does NOT refuse or alter the row. There is no correct
     * recovery to perform here -- both values legitimately own that id under
     * this scheme -- and dropping the event would lose data over an event this
     * rare. Reporting is the whole job.
     *
     * @param string $column  the property holding the content the id came from
     * @param string $source  the content the id was just derived from
     * @return bool  true when a collision was detected
     */
    public function detectIdCollision( $column, $source ) {

        $stored = $this->get( $column );

        // A row that was not found, or content we cannot compare, tells us
        // nothing. Only a row that exists AND disagrees is evidence.
        if ( ! $this->wasPersisted() || $stored === null || $stored === '' || $source === null || $source === '' ) {

            return false;
        }

        if ( (string) $stored === (string) $source ) {

            return false;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            'ID COLLISION on %s id %s: stored %s = "%s" but this event derived the same id from "%s". '
          . 'Both are now recorded against one dimension row and reports will merge them.',
            $this->getTableName(),
            (string) $this->get( 'id' ),
            $column,
            self::truncateForLog( $stored ),
            self::truncateForLog( $source )
        ) );

        return true;
    }

    /**
     * @param string $value
     * @return string
     */
    protected static function truncateForLog( $value ) {

        $value = (string) $value;

        return strlen( $value ) > 120 ? substr( $value, 0, 120 ) . '...' : $value;
    }
    
    function setCacheExpirationPeriod($seconds) {
        
        $this->_tableProperties['cache_expiration_period'] = $seconds;
    }
    
    function getCacheExpirationPeriod() {
        
        if (array_key_exists('cache_expiration_period', $this->_tableProperties)) {
            return $this->_tableProperties['cache_expiration_period'];
        } else {
            // default of thirty days
            return (3600);
        }
    }
    
    function getName() {
        
        return $this->name;
    }
    
    function setSummaryLevel($num) {
        
        $this->_tableProperties['summary_level'] = $num;
    }
    
    function getSummaryLevel() {
        
        if (array_key_exists('summary_level', $this->_tableProperties)) {
            
            return $this->_tableProperties['summary_level'];
        
        } else {
        
            return 0;
        }
    }
    
    function setCharacterEncoding($encoding) {

        $this->_tableProperties['character_encoding'] = $encoding;

        // Any column already defined is now stale; re-run the propagation.
        $this->_character_encoding_applied = false;
    }

    /**
     * The encoding this entity's table is in, or null for the default.
     *
     * @return string|null
     */
    function getCharacterEncoding() {

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
    
    function wasPersisted() {
        return $this->wasPersisted;
    }
}

?>