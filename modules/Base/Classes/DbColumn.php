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
// $Id$
//

/**
 * Database Column Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
 
class DbColumn {

     var $name;

     var $value;

     var $data_type;

     /**
      * Whether an over-long value may be trimmed to fit this column.
      *
      * True by default because that is what the database was already doing.
      * Set it false on a column whose value is IDENTITY-BEARING -- one that
      * something derives a key from -- where a trimmed value and the full one
      * are not the same thing. Those need a real answer (normalise before
      * hashing, hash the full value, or widen the column), and a flag here is
      * how they stay visible instead of being silently trimmed like the rest.
      *
      * Nothing sets it false yet. owa_document.url is the known candidate: the
      * document id is derived from the event's full page_url while the column
      * stores a trimmed copy, so ~15% of rows already hold a url that does not
      * derive to the id naming them. That divergence predates this flag -- the
      * database was trimming them regardless -- but it is the case the flag
      * exists to make addressable.
      *
      * @var bool
      */
     var $truncatable = true;

     /**
      * The encoding of the TABLE this column belongs to, when it differs from
      * the installation default.
      *
      * Null means "the default", which is what almost every column is. It
      * matters once entities can name their own encoding: a utf8mb4 table's
      * columns must NOT have four-byte characters stripped out of them just
      * because the installation default is still utf8.
      *
      * @var string|null
      */
     var $character_encoding = null;

     var $foreign_key;

     var $is_primary_key = false;

     var $auto_increment = false;

     var $is_unique = false;

     var $is_not_null = false;

     var $label;

     var $index;

     var $default_value;

     function __construct($name ='', $data_type = '') {

         if ($name) {
             $this->setName($name);
         }

         if ($data_type) {
             $this->setDataType($data_type);
         }

     }

     function get($name) {

         return $this->$name;
     }

     function set($name, $value) {

         $this->$name = $value;

         return;
     }

     function getValue() {

         return $this->value;
     }

     function setValue($value) {

         $this->value = $this->fitToColumn( $value );

         return;
     }

    /**
     * Cut an over-long string down to what the column can actually hold.
     *
     * MySQL used to do this silently, and OWA depended on it without saying so.
     * It is not a rare path: on a live install ~15% of document urls and ~9% of
     * referer urls sit at exactly the column limit, which is the fingerprint of
     * a value that arrived longer and got trimmed on the way in.
     *
     * Under a permissive sql_mode that trim is a warning nobody reads. Under
     * STRICT_ALL_TABLES it is an ERROR, and the whole INSERT is refused -- so
     * the page view is not truncated, it is LOST, and silently, because the
     * write path does not surface the failure. Healing the value here is what
     * makes strict mode safe to turn on: the row still lands, holding what it
     * always held.
     *
     * Measured in CHARACTERS, not bytes, because the column is declared that
     * way -- and cutting a UTF-8 sequence in half would produce a value that
     * strict mode rejects for a different reason, turning one silent loss into
     * another.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function fitToColumn( $value ) {

        if ( ! $this->truncatable || ! is_string( $value ) || $value === '' ) {

            return $value;
        }

        // Before the length check, not after: what gets stored is what is left
        // once characters the encoding cannot hold are gone, so that is what
        // the length has to be measured against.
        $value = $this->dropUnstorableCharacters( $value );

        $max = $this->maxLength();

        if ( ! $max || mb_strlen( $value, 'UTF-8' ) <= $max ) {

            return $value;
        }

        return mb_substr( $value, 0, $max, 'UTF-8' );
    }

    /**
     * Remove characters the declared encoding cannot store.
     *
     * OWA declares utf8, which in MySQL is utf8mb3 and holds no character above
     * U+FFFF. An emoji in a page title is therefore unstorable -- and what
     * MySQL does with it is not drop the character, it drops THE REST OF THE
     * STRING. Measured: a 35-character title containing one emoji stores as 13
     * characters. Everything from the emoji onward is gone, silently, under the
     * permissive mode. Under a strict mode the whole row is refused instead.
     *
     * Dropping the offending character costs one character rather than the
     * remainder, and leaves something storable either way. It is a floor, not a
     * fix -- the fix is migrating the schema to utf8mb4, which needs the tables
     * still on ROW_FORMAT=Compact converted first, since a varchar(255) utf8mb4
     * index key is 1020 bytes against Compact's 767-byte limit.
     *
     * Conditional on the DECLARED encoding, so it stops doing anything once
     * that migration lands, and does nothing on a dialect whose UTF-8 is not
     * width-limited.
     *
     * @param string $value
     * @return string
     */
    protected function dropUnstorableCharacters( $value ) {

        // Every 4-byte UTF-8 sequence starts F0-F4. No such byte, nothing to
        // do -- which is the overwhelming majority of values, so the regex
        // below is never reached for them.
        if ( strpbrk( $value, "\xF0\xF1\xF2\xF3\xF4" ) === false ) {

            return $value;
        }

        if ( ! $this->encodingIsWidthLimited() ) {

            return $value;
        }

        $stripped = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $value );

        // preg_replace returns null on malformed UTF-8. Keeping the original is
        // the safer of the two: it is what is stored today.
        return $stripped === null ? $value : $stripped;
    }

    /**
     * Whether the declared encoding tops out below U+10000.
     *
     * Named encodings rather than a pattern: 'utf8' and 'utf8mb3' are MySQL's
     * three-byte encoding, while PostgreSQL's 'UTF8' is the full range and must
     * not match. There is no way to tell those apart except by knowing them, so
     * the list is explicit and the default is to leave values alone.
     *
     * @return bool
     */
    protected function encodingIsWidthLimited() {

        // This column's table first, the installation default second. Once an
        // entity can name its own encoding those two genuinely differ, and
        // reading the default would strip four-byte characters out of a
        // utf8mb4 table for no reason -- the exact loss this is meant to limit.
        if ( $this->character_encoding !== null ) {

            $declared = $this->character_encoding;

        } elseif ( defined( 'OWA_DTD_CHARACTER_ENCODING_UTF8' ) ) {

            $declared = (string) constant( 'OWA_DTD_CHARACTER_ENCODING_UTF8' );

        } else {

            return false;
        }

        return in_array( strtolower( $declared ), array( 'utf8', 'utf8mb3' ), true );
    }

    /**
     * Tell this column what encoding its table is in.
     *
     * @param string|null $encoding
     * @return void
     */
    function setCharacterEncoding( $encoding ) {

        $this->character_encoding = $encoding ? (string) $encoding : null;
    }

    /**
     * Mark whether this column's value may be trimmed to fit.
     *
     * @param bool $truncatable
     * @return void
     */
    function setTruncatable( $truncatable ) {

        $this->truncatable = (bool) $truncatable;
    }

    /**
     * The declared length of this column, or 0 if it does not have one.
     *
     * Resolved through the OWA_DTD_* constants rather than by reading a number
     * out of the type string. The constant NAMES are OWA's vocabulary and the
     * VALUES are one dialect's spelling, so comparing against the constants
     * keeps this working wherever a dialect spells its types differently.
     *
     * @return int
     */
    protected function maxLength() {

        $lengths = array();

        foreach ( array( 'OWA_DTD_VARCHAR255' => 255, 'OWA_DTD_VARCHAR10' => 10 ) as $constant => $length ) {

            if ( defined( $constant ) ) {

                $lengths[ (string) constant( $constant ) ] = $length;
            }
        }

        $type = (string) $this->get( 'data_type' );

        return isset( $lengths[ $type ] ) ? $lengths[ $type ] : 0;
    }

     /**
      * THE COLUMN'S OWN DEFINITION, AND NOTHING ELSE.
      *
      * An index is a TABLE-level clause, not part of a column definition. This
      * used to append `, INDEX (col)` here, which reads fine inside a CREATE
      * TABLE's parenthesised list and is a syntax error everywhere else -- so
      * `ALTER TABLE t ADD col VARCHAR(255), INDEX (col)` was rejected outright
      * and addColumn() had never once worked for an indexed column.
      *
      * It went unnoticed because a fresh install CREATEs its tables from the
      * current entity, indexes and all; only an upgrade adding an indexed
      * column to an existing table takes this path, and that is what stopped a
      * live upgrade dead at Update026.
      *
      * createTable() asks isIndexed() and writes the table-level clause
      * itself; Entity::addColumn() adds the index as its own statement.
      *
      * @param bool $omit_primary_key  leave the inline PRIMARY KEY off, for a
      *                                table that declares a composite one
      */
     function getDefinition( $omit_primary_key = false ) {

         $definition = '';

         $definition .= $this->get('data_type');

        // Check for auto increment
        if ($this->get('auto_increment') == true):
            $definition .= ' '.OWA_DTD_AUTO_INCREMENT;
        endif;

        // Check for auto Not null
        if ($this->get('is_not_null') == true):
            $definition .= ' '.OWA_DTD_NOT_NULL;
        endif;

        // Check for unique
        if ($this->get('is_unique') == true):
            $definition .= ' '.OWA_DTD_UNIQUE;
        endif;

        // check for primary key. A partitioned table declares a composite key
        // at table level instead, since the partitioning column has to be part
        // of it, so the caller can ask for the inline one to be left off.
        if ($this->get('is_primary_key') == true && ! $omit_primary_key):
            $definition .= ' '.OWA_DTD_PRIMARY_KEY;
            //$definition .= sprintf(", INDEX (%s)", $this->get('name'));
        endif;

         return $definition;

     }

     /**
      * Whether this column asked for an index.
      *
      * The clause itself belongs to whoever is writing the statement, because
      * where it may legally go depends on the statement. See getDefinition().
      */
     function isIndexed() {

         return (bool) $this->get('index');
     }

     function setDataType($type) {

         $this->data_type = $type;
     }

     function setDefaultValue($value) {

         $this->default_value = $value;
     }

     function setPrimaryKey() {

         $this->is_primary_key = true;
     }

     function setIndex() {

         $this->index = true;
     }

     function setNotNull() {

         $this->is_not_null = true;
     }

    function setUnique() {

         $this->is_unique = true;
     }

     function setLabel($label) {

         $this->label = $label;
     }

     function setForeignKey($entity, $column = 'id') {

         $this->foreign_key = array($entity, $column);
     }

     function getForeignKey() {

         return $this->foreign_key;
     }

     function isForeignKey() {

         if (!empty($this->foreign_key)) {
             return true;
         } else {
             return false;
         }
     }

     function setAutoIncrement() {

         $this->auto_increment = true;
     }

     function setName($name) {

         $this->name = $name;
     }

     function getName() {

         return $this->name;
     }

 }

?>