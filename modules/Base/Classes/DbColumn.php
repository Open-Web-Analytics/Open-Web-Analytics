<?php
namespace OWA\Module\Base\Classes;

/**
 * Database Column Object
 */
 
class DbColumn {

     /** @var string|null */
     public $name;

     /** @var mixed whatever the entity was given, fitted to the column */
     public $value;

     /** @var string|null an OWA_DTD_* type */
     public $data_type;

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
      * owa_event_raw.event_type sets it false: the row id is derived from the
      * whole name, so a trimmed name would be a different event.
      *
      * @var bool
      */
     public $truncatable = true;

     /**
      * May this column store NULL, or is an unset value written as ''?
      *
      * Defaults to false: Entity::writeValue() writes a text column's NULL as ''
      * so that rows keep the shape the interpolating driver gave them. That
      * rule is about columns which ALREADY hold '', and must not change for
      * them: a two-state fact would report as three.
      *
      * A new column has no such history, and NULL is the storage form v2 uses
      * for absence: "(not set)" is a label applied when a
      * value is rendered, never a value that is stored. Declaring a new column
      * nullable opts it into that, one column at a time, without touching the
      * ones the rule exists to protect.
      *
      * @var bool
      */
     public $nullable = false;

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
     public $character_encoding = null;

     /** @var bool */
     public $is_primary_key = false;

     /** @var bool */
     public $auto_increment = false;

     /** @var bool */
     public $is_unique = false;

     /** @var bool */
     public $is_not_null = false;

     /** @var string|null */
     public $label;

     /**
      * What the column holds, in a sentence for an administrator. Documentation
      * only: no statement reads it. The generated wiki does.
      *
      * @var string
      */
     public $description = '';

     /** @var bool|null true once setIndex() asks for one */
     public $index;

     /** @var int|float|null only a number is written into the definition */
     public $default_value;

     /**
      * @param string $name
      * @param string $data_type an OWA_DTD_* type
      */
     public function __construct($name ='', $data_type = '') {

         if ($name) {
             $this->setName($name);
         }

         if ($data_type) {
             $this->setDataType($data_type);
         }

     }

     /**
      * @param  string $name a property
      * @return mixed that property, a different type for each
      */
     public function get($name) {

         return $this->$name;
     }

     /**
      * @param  string $name  a property
      * @param  mixed  $value of that property's type
      * @return void
      */
     public function set($name, $value) {

         $this->$name = $value;

         return;
     }

     /**
      * @return mixed
      */
     public function getValue() {

         return $this->value;
     }

     /**
      * @param  mixed $value
      * @return void
      */
     public function setValue($value) {

         $this->value = $this->fitToColumn( $value );

         return;
     }

    /**
     * Cut an over-long string down to what the column can actually hold.
     *
     * Under a permissive sql_mode MySQL trims an over-long value with a warning
     * nobody reads. Under STRICT_ALL_TABLES it is an ERROR, and the whole INSERT
     * is refused -- so the page view would not be truncated, it would be LOST,
     * silently. Long URLs are common. Healing the value here keeps strict mode
     * safe: the row still lands, holding what a permissive server would store.
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
     * STRING, silently, under the permissive mode. Under a strict mode the whole
     * row is refused instead.
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

        return in_array( strtolower( $declared ), [ 'utf8', 'utf8mb3' ], true );
    }

    /**
     * Tell this column what encoding its table is in.
     *
     * @param string|null $encoding
     * @return void
     */
    public function setCharacterEncoding( $encoding ) {

        $this->character_encoding = $encoding ? (string) $encoding : null;
    }

    /**
     * Mark whether this column's value may be trimmed to fit.
     *
     * @param bool $truncatable
     * @return void
     */
    public function setTruncatable( $truncatable ) {

        $this->truncatable = (bool) $truncatable;
    }

    /**
     * Let this column hold NULL rather than '' when its value is unset.
     *
     * For NEW columns only. Setting it on an existing text column changes what
     * every future write stores and leaves the table holding both spellings of
     * absence.
     *
     * @param bool $nullable
     * @return void
     */
    public function setNullable( $nullable = true ) {

        $this->nullable = (bool) $nullable;
    }

    /**
     * The values a signed integer column holds, as [ min, max ], or null for a
     * column of another type.
     *
     * Resolved through the OWA_DTD_* constants, as maxLength() is. BIGINT's
     * range is PHP's integer range.
     *
     * @return array{0: int, 1: int}|null
     */
    public function integerRange() {

        $declared = [
            'OWA_DTD_TINYINT'  => [ -128, 127 ],
            'OWA_DTD_TINYINT2' => [ -128, 127 ],
            'OWA_DTD_TINYINT4' => [ -128, 127 ],
            'OWA_DTD_SMALLINT' => [ -32768, 32767 ],
            'OWA_DTD_INT'      => [ -2147483648, 2147483647 ],
            'OWA_DTD_BIGINT'   => [ PHP_INT_MIN, PHP_INT_MAX ],
        ];

        $type = (string) $this->get( 'data_type' );

        foreach ( $declared as $constant => $range ) {

            if ( defined( $constant ) && (string) constant( $constant ) === $type ) {

                return $range;
            }
        }

        return null;
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
    public function maxLength() {

        $lengths = [];

        $declared = [
            'OWA_DTD_VARCHAR10'   => 10,
            'OWA_DTD_VARCHAR16'   => 16,
            'OWA_DTD_VARCHAR24'   => 24,
            'OWA_DTD_VARCHAR32'   => 32,
            'OWA_DTD_VARCHAR45'   => 45,
            'OWA_DTD_VARCHAR64'   => 64,
            'OWA_DTD_VARCHAR128'  => 128,
            'OWA_DTD_VARCHAR255'  => 255,
            'OWA_DTD_VARCHAR512'  => 512,
            'OWA_DTD_VARCHAR1024' => 1024,
            'OWA_DTD_CHAR2'       => 2,
            'OWA_DTD_CHAR3'       => 3,
        ];

        foreach ( $declared as $constant => $length ) {

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
      * An index is a TABLE-level clause, not part of a column definition: `, INDEX
      * (col)` reads fine inside a CREATE TABLE's parenthesised list and is a syntax
      * error in `ALTER TABLE t ADD col VARCHAR(255), INDEX (col)`.
      *
      * createTable() asks isIndexed() and writes the table-level clause itself;
      * Entity::addColumn() adds the index as its own statement.
      *
      * @param bool $omit_primary_key  leave the inline PRIMARY KEY off, for a
      *                                table that declares a composite one
      * @return string
      */
     public function getDefinition( $omit_primary_key = false ) {

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

        /*
         * A declared column default.
         *
         * NUMERIC ONLY, on purpose. A string default would have to be quoted
         * and escaped the way the dialect quotes literals, and DbColumn has no
         * connection to ask -- so it would either hard-code MySQL's quoting
         * here or produce a broken statement somewhere else. Nothing declares a
         * string default; when something needs to, the escaping belongs in the
         * dialect and this should call into it rather than guessing.
         *
         * What it is FOR is `NOT NULL DEFAULT 0` on a boolean. A boolean column
         * that permits NULL holds three values, and each one groups separately
         * -- which cost 1.x a pie chart drawing two slices with the same label.
         * NOT NULL alone is not enough: with no default, an INSERT that omits
         * the column is refused under a strict sql_mode and writes an implicit
         * 0 under a permissive one, so the guarantee depends on a server
         * setting. The default is what makes it a property of the schema.
         */
        $default = $this->get('default_value');

        if ( $default !== null && ( is_int( $default ) || is_float( $default ) ) ):
            $definition .= ' '.sprintf( OWA_DTD_DEFAULT, $default );
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
        endif;

         return $definition;

     }

     /**
      * Whether this column asked for an index.
      *
      * The clause itself belongs to whoever is writing the statement, because
      * where it may legally go depends on the statement. See getDefinition().
      *
      * @return bool
      */
     public function isIndexed() {

         return (bool) $this->get('index');
     }

     /**
      * @param  string $type an OWA_DTD_* type
      * @return void
      */
     public function setDataType($type) {

         $this->data_type = $type;
     }

     /**
      * @param  int|float $value
      * @return void
      */
     public function setDefaultValue($value) {

         $this->default_value = $value;
     }

     /**
      * @return void
      */
     public function setPrimaryKey() {

         $this->is_primary_key = true;
     }

     /**
      * A UNIQUE constraint on this column alone. A key over several columns is
      * the entity's: Entity::addUniqueIndex().
      *
      * @return void
      */
     public function setUnique() {

         $this->is_unique = true;
     }

     /**
      * @return void
      */
     public function setIndex() {

         $this->index = true;
     }

     /**
      * @return void
      */
     public function setNotNull() {

         $this->is_not_null = true;
     }

     /**
      * @param  string $label
      * @return void
      */
     public function setLabel($label) {

         $this->label = $label;
     }

     /**
      * @param  string $description
      * @return void
      */
     public function setDescription( $description ) {

         $this->description = (string) $description;
     }

     /**
      * @return void
      */
     public function setAutoIncrement() {

         $this->auto_increment = true;
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

 }
