<?php

use PHPUnit\Framework\TestCase;

/**
 * Platform-specific SQL belongs in the dialect, not scattered through the app.
 *
 * OWA has a dialect layer -- the OWA_SQL_* and OWA_DTD_* constants -- whose job
 * is to be the one place a backend's spelling lives. It works, and the pattern
 * is followed almost everywhere. The failures are not architectural, they are
 * individual lines that slipped past, and every one found so far had the
 * correct pattern in view when it was written:
 *
 *   LOCATE(a, b)   sat two lines below =~ correctly using OWA_SQL_REGEXP
 *   SHOW TABLES    was written out twice while OWA_SQL_SHOW_TABLE existed and
 *                  InstallManager was already using it
 *   IFNULL()       was reached for where ANSI COALESCE means the same thing
 *
 * That is what makes a lint the right shape for this rather than a note in a
 * review. These are not caught by reading, because each one looks ordinary
 * where it sits, and none of them fail on MySQL -- which is the only backend
 * anyone runs today, so nothing else can catch them either. The cost of missing
 * one is a silent wrong answer on the first non-MySQL install, not an error.
 *
 * Scans STRING LITERALS ONLY, via token_get_all. A comment explaining that
 * PostgreSQL uses ON CONFLICT must not trip a check for ON DUPLICATE KEY, and
 * a grep cannot tell those apart -- this file's own docblock would fail it.
 */
final class SqlPortabilityLintTest extends TestCase {

    /**
     * MySQL-only constructs, with what a portable equivalent would be. Keep the
     * reason with the pattern: the message is the whole value of a lint.
     */
    private const MYSQL_ONLY = [
        'ON DUPLICATE KEY' => 'PostgreSQL spells this ON CONFLICT ... DO UPDATE',
        'INSERT IGNORE'    => 'PostgreSQL spells this ON CONFLICT DO NOTHING',
        'REPLACE INTO'     => 'not standard; an upsert belongs in the dialect',
        'SHOW TABLES'      => 'use OWA_SQL_SHOW_TABLE, which already exists',
        'SHOW INDEX'       => 'index introspection belongs in the dialect',
        'IFNULL('          => 'use COALESCE(), which is ANSI SQL and works in MySQL too',
        'UNIX_TIMESTAMP('  => 'date handling differs per dialect; put it behind a constant',
        'FROM_UNIXTIME('   => 'date handling differs per dialect; put it behind a constant',
        'DATE_FORMAT('     => 'date formatting differs per dialect',
        'GROUP_CONCAT('    => 'PostgreSQL spells this string_agg()',
        'SUBSTRING_INDEX(' => 'no standard equivalent; put it behind a constant',
        'LOCATE('          => 'use OWA_SQL_CONTAINS / OWA_SQL_NOT_CONTAINS',
        'SQL_CALC_FOUND_ROWS' => 'MySQL-only and removed in MySQL 8.0.17+',
        'STRAIGHT_JOIN'    => 'MySQL-only optimiser hint',
        'information_schema' => 'schema introspection belongs in the dialect',
    ];

    /**
     * Where platform-specific SQL is allowed to live, and why.
     *
     * The dialect files ARE the abstraction, so they are exempt by definition.
     * The two CLI commands are exempt by DECISION: they drive MySQL table
     * partitioning, which has no equivalent elsewhere, and they say so in their
     * own docblocks. Their SQL is not a cheat -- there is nothing to abstract
     * over. Anything added to this list should be an argument, not a shortcut.
     */
    private const EXEMPT = [
        'Core/Db/MysqlDialect.php',
        'Core/Db/Mysql.php',
        'Core/Db/PdoMysql.php',
        'Core/Db/Pdo.php',
        // The id rewrite and the partition commands: MySQL table partitioning
        // has no equivalent elsewhere, and these say so in their own docblocks.
        'modules/Base/Controller/RederiveDimensionIdsCli.php',
        'modules/Base/Controller/PartitionDropCli.php',
        'modules/Base/Controller/PartitionInitCli.php',
        'modules/Base/Controller/PartitionReorganizeCli.php',
        'modules/Base/Controller/PartitionRotateCli.php',
        'modules/Base/Controller/PartitionStatusCli.php',
        'modules/Base/Controller/PartitionsCli.php',
    ];

    /**
     * Exempt directories.
     *
     * Update scripts are historical: each one migrates a schema that existed at
     * one moment in the past, and every installation that has ever run one was
     * on MySQL, because MySQL is the only backend OWA has ever had. A new
     * install on another backend starts at the current schema and never
     * executes them. Their SQL is a record of what happened, not a statement
     * about what OWA supports, and rewriting it would be editing history to no
     * effect.
     */
    private const EXEMPT_DIRS = [
        'modules/Base/Update/',
    ];

    /**
     * @return array<string> absolute paths
     */
    private function sourceFiles(): array {

        $root = dirname( __DIR__ );
        $files = [];

        foreach ( [ 'Core', 'modules' ] as $dir ) {

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
            );

            foreach ( $it as $file ) {

                if ( $file->getExtension() !== 'php' ) {
                    continue;
                }

                $path = $file->getPathname();

                // Third-party code vendored inside a module is not ours to fix.
                if ( strpos( $path, '/includes/' ) !== false || strpos( $path, '/vendor/' ) !== false ) {
                    continue;
                }

                $relative = str_replace( $root . '/', '', $path );

                if ( in_array( $relative, self::EXEMPT, true ) ) {
                    continue;
                }

                foreach ( self::EXEMPT_DIRS as $dir ) {

                    if ( strpos( $relative, $dir ) === 0 ) {
                        continue 2;
                    }
                }

                $files[ $relative ] = $path;
            }
        }

        ksort( $files );

        return $files;
    }

    /**
     * @return array<string> the string literals in a file
     */
    private function stringLiterals( string $path ): array {

        $out = [];

        foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {

            if ( ! is_array( $token ) ) {
                continue;
            }

            if ( $token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE
                || $token[0] === T_INLINE_HTML ) {

                $out[] = $token[1];
            }
        }

        return $out;
    }

    public function testNoMysqlOnlySqlOutsideTheDialect(): void {

        $findings = [];

        foreach ( $this->sourceFiles() as $relative => $path ) {

            foreach ( $this->stringLiterals( $path ) as $literal ) {

                foreach ( self::MYSQL_ONLY as $needle => $why ) {

                    if ( stripos( $literal, $needle ) !== false ) {

                        $findings[] = sprintf( '%s: "%s" -- %s', $relative, $needle, $why );
                    }
                }
            }
        }

        $findings = array_values( array_unique( $findings ) );

        $this->assertSame(
            [],
            $findings,
            "MySQL-specific SQL found outside the dialect layer.\n\n"
          . "Each of these will keep working on MySQL and produce a wrong answer or an error on\n"
          . "any other backend, which is why nothing else catches them. Move the spelling into\n"
          . "Core/Db/MysqlDialect.php behind an OWA_SQL_* constant, or -- if the feature genuinely\n"
          . "has no equivalent elsewhere -- add the file to EXEMPT with the reason.\n\n"
          . implode( "\n", $findings )
        );
    }

    /**
     * A lint nobody can see the scope of is a lint nobody trusts. If the exempt
     * list grows a stale entry, this says so rather than quietly covering a file
     * that no longer exists.
     */
    public function testEveryExemptFileStillExists(): void {

        $root = dirname( __DIR__ );

        foreach ( self::EXEMPT as $relative ) {

            $this->assertFileExists(
                $root . '/' . $relative,
                sprintf( '%s is exempted from the SQL portability lint but no longer exists', $relative )
            );
        }

        foreach ( self::EXEMPT_DIRS as $relative ) {

            $this->assertDirectoryExists(
                $root . '/' . $relative,
                sprintf( '%s is exempted from the SQL portability lint but no longer exists', $relative )
            );
        }
    }
}
