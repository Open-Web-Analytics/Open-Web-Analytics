<?php
namespace OWA\Module\Base\Classes;

/**
 * A SQL dump of OWA's tables, written by OWA itself (cmd=backup).
 *
 * TWO GROUPS. Data is what tracking writes: the tables ingest fills on every
 * visit (DATA_TABLES) and, while an upgraded installation keeps them, the 1.x
 * tracking tables (Migration\V1Tables). Application is every other OWA
 * table: configuration, users, Properties and Profiles, goals, settings,
 * notices, the job queue. The cubes are in neither; they are rebuilt from
 * owa_event_raw (cmd=cube-rebuild), and so are their build tables, which are
 * named after them.
 *
 * A table no group names is application. A module's own tracking table has
 * to be added to DATA_TABLES; BackupTest lists every entity's table so a new
 * one is classified when it is added.
 *
 * THE FILE is plain SQL, gzipped: a header saying what it holds and how to
 * restore it, then each table's SHOW CREATE TABLE and batched INSERTs, then a
 * last line saying it is complete. There is no DROP TABLE: it is for loading
 * into an empty database, and loaded into one that has these tables it stops
 * at the first CREATE TABLE. It is written as <name>.part, mode 0600 (it holds
 * users and their credentials), and renamed when complete, so a file with the
 * final name is a whole one.
 *
 * READS. Application tables are read in one consistent snapshot, so they agree
 * with each other. Data tables are read outside a transaction, a page at a
 * time in primary key order: a snapshot open for the length of an
 * owa_event_raw dump would hold its metadata lock that long, and a partition
 * change queued behind it would stop ingest. So the data dump holds each row
 * once, as it was when its page was read, and rows tracked while it runs may
 * or may not be in it.
 */
class Backup {

    const APPLICATION = 'application';
    const DATA        = 'data';
    const ALL         = 'all';

    /** The scope= values, and the groups each dumps. */
    const SCOPES = [
        self::APPLICATION => [ self::APPLICATION ],
        self::DATA        => [ self::DATA ],
        self::ALL         => [ self::APPLICATION, self::DATA ],
    ];

    /** The tables tracking writes on every visit, unprefixed. */
    const DATA_TABLES = [
        'event_raw',
        'visitor_acquisition',
        'domstream_chunk',
        'domstream_payload',
    ];

    /** The file's format, in its header. */
    const FORMAT = 1;

    /** Rows read per SELECT. */
    const PAGE = 1000;

    /** An INSERT is ended once its text is this long. */
    const INSERT_BYTES = 1048576;

    /** The last line of a complete file. */
    const COMPLETE = '-- Backup complete.';

    /** Column types written as hexadecimal literals. */
    const BINARY_TYPES = [ 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit' ];

    /** @var \OWA\Core\Db */
    private $db;

    /** @var string */
    private $prefix;

    /** @var callable(string): void|null told each table as it is done */
    public $progress = null;

    /**
     * @param \OWA\Core\Db $db
     * @param string       $prefix the tables' prefix; a test names its own
     */
    public function __construct( $db, $prefix = \OWA\Core\Entity::TABLE_PREFIX ) {

        $this->db     = $db;
        $this->prefix = (string) $prefix;
    }

    /**
     * Whether an unprefixed table is in the data group.
     *
     * @param  string $table
     * @return bool
     */
    public static function isDataTable( $table ) {

        return in_array( $table, self::DATA_TABLES, true )
            || in_array( $table, Migration\V1Tables::all(), true );
    }

    /**
     * The tables present, by group, with their prefix, sorted. Cubes and their
     * build tables are left out.
     *
     * @return array{application: list<string>, data: list<string>}
     */
    public function tables() {

        $groups = [ self::APPLICATION => [], self::DATA => [] ];
        $like   = str_replace( [ '\\', '_', '%' ], [ '\\\\', '\\_', '\\%' ], $this->prefix ) . '%';
        $cube   = '/^' . preg_quote( $this->prefix . Cube\Cubes::PREFIX, '/' ) . '[0-9]+/';

        foreach ( (array) $this->db->get_results( "SELECT TABLE_NAME AS t FROM information_schema.TABLES"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ?"
                . " ORDER BY TABLE_NAME", [ $like ] ) as $row ) {

            $name = (string) array_change_key_case( (array) $row )['t'];

            if ( preg_match( $cube, $name ) ) {

                continue;
            }

            $groups[ self::isDataTable( substr( $name, strlen( $this->prefix ) ) ) ? self::DATA : self::APPLICATION ][] = $name;
        }

        return $groups;
    }

    /**
     * Why a directory cannot hold backups, or null when it can.
     *
     * It must exist, be writable and lie outside every directory the web
     * server may serve: the OWA directory (owa-data/ included), public_path,
     * and the web root when there is one to ask (not in the CLI).
     *
     * @param  string       $dir
     * @param  list<string> $served   directories the web server may serve
     * @param  bool         $writable whether to require it writable by this process; System
     *                                Health does not, since the web server is not who writes
     * @return string|null
     */
    public static function dirProblem( $dir, array $served, $writable = true ) {

        $dir = (string) $dir;

        if ( $dir === '' ) {

            return 'No backup directory: pass dir=<path> or define OWA_BACKUP_DIR in owa-config.php.';
        }

        $real = realpath( $dir );

        if ( $real === false || ! is_dir( $real ) ) {

            return sprintf( 'The backup directory %s does not exist.', $dir );
        }

        foreach ( $served as $root ) {

            $root = $root !== '' ? realpath( $root ) : false;

            if ( $root !== false && ( $real === $root || str_starts_with( $real . '/', rtrim( $root, '/' ) . '/' ) ) ) {

                return sprintf( 'The backup directory %s is inside %s, which the web server can serve. Use a directory outside the web root.', $dir, $root );
            }
        }

        if ( $writable && ! is_writable( $real ) ) {

            return sprintf( 'The backup directory %s is not writable.', $dir );
        }

        return null;
    }

    /**
     * The directories the web server may serve, for dirProblem().
     *
     * @return list<string>
     */
    public static function servedDirs() {

        $public = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_path' );

        return array_values( array_filter( [
            \OWA\Core\Env::root(),
            $public !== '' ? $public : \OWA\Core\Env::root() . '/public',
            (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ),
        ] ) );
    }

    /**
     * Dump a scope's tables into a new file in $dir.
     *
     * @param  string               $scope  a SCOPES key
     * @param  string               $dir    checked with dirProblem() first
     * @param  array<string, mixed> $about  header lines: label => value
     * @return array{file: string, tables: array<string, int>}
     * @throws \RuntimeException when a table cannot be read or the file written; no file is left
     */
    public function write( $scope, $dir, array $about = [] ) {

        if ( ! isset( self::SCOPES[ $scope ] ) ) {

            throw new \InvalidArgumentException( sprintf( 'scope is one of %s', implode( ', ', array_keys( self::SCOPES ) ) ) );
        }

        $groups = $this->tables();
        $file   = rtrim( (string) realpath( $dir ), '/' ) . '/' . sprintf( 'owa-%s-%s-%s.sql.gz',
            preg_replace( '/[^A-Za-z0-9.]+/', '-', \OWA\Core\Env::version() ), gmdate( 'Ymd-His' ), $scope );
        $part   = $file . '.part';

        $umask = umask( 0077 );
        $gz    = @fopen( $part, 'xb' );
        umask( $umask );

        if ( $gz === false ) {

            throw new \RuntimeException( sprintf( 'Could not create %s.', $part ) );
        }

        chmod( $part, 0600 );

        // gzip: zlib's window 31 writes the gzip header and trailer.
        if ( ! stream_filter_append( $gz, 'zlib.deflate', STREAM_FILTER_WRITE, [ 'level' => 6, 'window' => 31 ] ) ) {

            fclose( $gz );
            @unlink( $part );

            throw new \RuntimeException( 'PHP\'s zlib extension is needed to write a backup.' );
        }

        $zone = (string) ( (array) $this->db->get_row( 'SELECT @@session.time_zone AS z' ) )['z'];
        $this->db->query( "SET time_zone = '+00:00'" );

        $counts = [];

        try {

            $tables = [];

            foreach ( self::SCOPES[ $scope ] as $group ) {

                $tables = array_merge( $tables, $groups[ $group ] );
            }

            $this->put( $gz, $this->header( $scope, $tables, $about ) );

            foreach ( self::SCOPES[ $scope ] as $group ) {

                $snapshot = $group === self::APPLICATION;

                if ( $snapshot ) {

                    $this->db->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
                    $this->db->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' );
                }

                try {

                    foreach ( $groups[ $group ] as $table ) {

                        $counts[ $table ] = $this->dumpTable( $gz, $table );

                        if ( $this->progress ) {

                            ( $this->progress )( sprintf( '%-40s %12s rows', $table, number_format( $counts[ $table ] ) ) );
                        }
                    }

                } finally {

                    if ( $snapshot ) {

                        $this->db->query( 'COMMIT' );
                    }
                }
            }

            $this->put( $gz, "SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n"
                . sprintf( "%s %d tables, %d rows.\n", self::COMPLETE, count( $counts ), array_sum( $counts ) ) );

            if ( ! fclose( $gz ) ) {

                $gz = false;

                throw new \RuntimeException( sprintf( 'Could not finish writing %s.', $part ) );
            }

            $gz = false;

            if ( ! self::isComplete( $part ) ) {

                throw new \RuntimeException( sprintf( '%s did not read back whole; the disk may be full.', $part ) );
            }

            if ( ! rename( $part, $file ) ) {

                throw new \RuntimeException( sprintf( 'Could not rename %s to %s.', $part, $file ) );
            }

        } catch ( \Throwable $e ) {

            if ( $gz !== false ) {

                fclose( $gz );
            }

            @unlink( $part );

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException( $e->getMessage(), 0, $e );

        } finally {

            if ( preg_match( '#^[A-Za-z0-9_+:/-]+$#', $zone ) ) {

                $this->db->query( sprintf( "SET time_zone = '%s'", $zone ) );
            }
        }

        return [ 'file' => $file, 'tables' => $counts ];
    }

    /**
     * Whether a backup file decompresses to its end and ends with the
     * COMPLETE line. A write into a full disk can be reported as whole by the
     * compressing stream, so the file is read back rather than trusted.
     *
     * @param  string $file
     * @return bool
     */
    public static function isComplete( $file ) {

        $gz = @gzopen( (string) $file, 'rb' );

        if ( $gz === false ) {

            return false;
        }

        $tail = '';

        while ( ! gzeof( $gz ) ) {

            $chunk = gzread( $gz, 1048576 );

            if ( $chunk === false ) {

                gzclose( $gz );

                return false;
            }

            $tail = substr( $tail . $chunk, -512 );
        }

        gzclose( $gz );

        return (bool) preg_match( '/(^|\n)' . preg_quote( self::COMPLETE, '/' ) . ' [^\n]*\n\z/', $tail );
    }

    /**
     * What the file holds and how it is restored, as SQL comments, then the
     * session settings the INSERTs need.
     *
     * @param  string               $scope
     * @param  list<string>         $tables
     * @param  array<string, mixed> $about
     * @return string
     */
    private function header( $scope, array $tables, array $about ) {

        $lines = [
            sprintf( 'Open Web Analytics backup, format %d: the %s tables.', self::FORMAT,
                $scope === self::ALL ? 'application and data' : $scope ),
            sprintf( 'OWA %s, written %s UTC.', \OWA\Core\Env::version(), gmdate( 'Y-m-d H:i:s' ) ),
        ];

        foreach ( $about as $label => $value ) {

            $lines[] = sprintf( '%s: %s', $label, is_scalar( $value ) ? (string) $value : (string) json_encode( $value ) );
        }

        $lines[] = 'Tables: ' . implode( ', ', $tables );
        $lines[] = '';
        $lines[] = 'OWA never applies this file. To restore:';
        $lines[] = '  1. Load it into an EMPTY database: gunzip -c <file> | mysql <database>';
        $lines[] = '     It has no DROP TABLE, so loaded where these tables exist it stops at the first.';
        $lines[] = '     All tables: point OWA_DB_NAME in owa-config.php at that database.';
        $lines[] = '     Application or data only: move its tables into OWA\'s database in one statement,';
        $lines[] = '     RENAME TABLE scratch.owa_x TO owa.owa_x, ... (rename OWA\'s own away first).';
        $lines[] = '  2. Run the OWA release this backup came from (above): the schema in it is that release\'s.';
        $lines[] = '  3. php cli.php cmd=cube-rebuild from=<first day of data>';
        $lines[] = '  4. php cli.php cmd=publish-trackers';

        return '-- ' . implode( "\n-- ", $lines ) . "\n\n"
            . sprintf( "SET NAMES %s;\n", $this->charset() )
            . "SET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n";
    }

    /** @return string the connection's character set, which the values were read in */
    private function charset() {

        $cs = (string) ( (array) $this->db->get_row( 'SELECT @@character_set_connection AS cs' ) )['cs'];

        return preg_match( '/^[a-z0-9_]+$/', $cs ) ? $cs : 'utf8mb4';
    }

    /**
     * One table's CREATE TABLE and rows.
     *
     * @param  resource $gz
     * @param  string   $table
     * @return int rows written
     */
    private function dumpTable( $gz, $table ) {

        $create = array_values( (array) $this->db->get_row( sprintf( 'SHOW CREATE TABLE `%s`', $table ) ) )[1] ?? null;

        if ( ! is_string( $create ) || $create === '' ) {

            throw new \RuntimeException( sprintf( 'Could not read the definition of %s.', $table ) );
        }

        $this->put( $gz, sprintf( "-- %s\n%s;\n", $table, $create ) );

        $columns = $this->columns( $table );
        $key     = $this->primaryKey( $table );
        $names   = implode( ', ', array_map( static fn ( $c ) => '`' . $c . '`', array_keys( $columns ) ) );
        $insert  = sprintf( 'INSERT INTO `%s` (%s) VALUES ', $table, $names );
        $rows    = 0;
        $after   = null;
        $offset  = 0;
        $tuples  = '';

        do {

            [ $where, $bindings ] = $key && $after !== null ? $this->after( $key, $after ) : [ '', [] ];

            $errors = $this->db->queryErrorCount();
            $page   = $this->db->get_results( sprintf( 'SELECT %s FROM `%s`%s ORDER BY %s LIMIT %d%s', $names, $table, $where,
                implode( ', ', array_map( static fn ( $c ) => '`' . $c . '`', $key ?: array_keys( $columns ) ) ),
                self::PAGE, $key ? '' : ' OFFSET ' . $offset ), $bindings );

            if ( $this->db->queryErrorCount() !== $errors ) {

                throw new \RuntimeException( sprintf( 'Reading %s failed: %s', $table, $this->db->lastQueryError() ) );
            }

            foreach ( (array) $page as $row ) {

                $row     = (array) $row;
                $tuples .= ( $tuples === '' ? '' : ',' ) . $this->tuple( $row, $columns );
                $rows++;

                if ( strlen( $tuples ) >= self::INSERT_BYTES ) {

                    $this->put( $gz, $insert . $tuples . ";\n" );
                    $tuples = '';
                }

                if ( $key ) {

                    $after = array_map( static fn ( $c ) => $row[ $c ], $key );
                }
            }

            $offset += self::PAGE;

        } while ( count( (array) $page ) === self::PAGE );

        if ( $tuples !== '' ) {

            $this->put( $gz, $insert . $tuples . ";\n" );
        }

        $this->put( $gz, "\n" );

        return $rows;
    }

    /**
     * The columns an INSERT writes, name => whether binary. A generated column
     * cannot be written and is left out; DEFAULT_GENERATED (a CURRENT_TIMESTAMP
     * default) is an ordinary column.
     *
     * @param  string $table
     * @return array<string, bool>
     */
    private function columns( $table ) {

        $columns = [];

        foreach ( (array) $this->db->get_results( 'SELECT COLUMN_NAME AS c, DATA_TYPE AS d, EXTRA AS e FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [ $table ] ) as $row ) {

            $row = array_change_key_case( (array) $row );

            if ( preg_match( '/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) $row['e'] ) ) {

                continue;
            }

            $columns[ (string) $row['c'] ] = in_array( strtolower( (string) $row['d'] ), self::BINARY_TYPES, true );
        }

        if ( ! $columns ) {

            throw new \RuntimeException( sprintf( 'Could not read the columns of %s.', $table ) );
        }

        return $columns;
    }

    /**
     * @param  string $table
     * @return list<string> the primary key's columns in order, [] when it has none
     */
    private function primaryKey( $table ) {

        return array_values( array_map( static fn ( $row ) => (string) array_change_key_case( (array) $row )['c'],
            (array) $this->db->get_results( "SELECT COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'"
                . ' ORDER BY ORDINAL_POSITION', [ $table ] ) ) );
    }

    /**
     * The rows after $last in key order, spelled out rather than as a row
     * comparison, which not every server reads as a range on the key.
     *
     * @param  list<string>      $key
     * @param  list<string|null> $last
     * @return array{0: string, 1: list<string|null>}
     */
    private function after( array $key, array $last ) {

        $or       = [];
        $bindings = [];

        foreach ( $key as $i => $column ) {

            $and = [];

            for ( $j = 0; $j < $i; $j++ ) {

                $and[]      = sprintf( '`%s` = ?', $key[ $j ] );
                $bindings[] = $last[ $j ];
            }

            $and[]      = sprintf( '`%s` > ?', $column );
            $bindings[] = $last[ $i ];
            $or[]       = '(' . implode( ' AND ', $and ) . ')';
        }

        return [ ' WHERE ' . implode( ' OR ', $or ), $bindings ];
    }

    /**
     * One row as a VALUES tuple.
     *
     * @param  array<string, mixed> $row
     * @param  array<string, bool>  $columns
     * @return string
     */
    private function tuple( array $row, array $columns ) {

        $values = [];

        foreach ( $columns as $column => $binary ) {

            $v = $row[ $column ] ?? null;

            if ( $v === null ) {

                $values[] = 'NULL';

            } elseif ( $binary ) {

                $values[] = $v === '' ? "''" : '0x' . bin2hex( (string) $v );

            } else {

                $values[] = "'" . $this->db->prepare( (string) $v ) . "'";
            }
        }

        return '(' . implode( ',', $values ) . ')';
    }

    /**
     * @param  resource $gz
     * @param  string   $text
     * @return void
     */
    private function put( $gz, $text ) {

        if ( fwrite( $gz, $text ) !== strlen( $text ) ) {

            throw new \RuntimeException( 'Writing the backup failed; the disk may be full.' );
        }
    }
}
