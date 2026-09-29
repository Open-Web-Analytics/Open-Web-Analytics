<?php
namespace OWA\Module\Base\Update;

/**
 * Migrate v1's history into v2 (PLAN.html 2.21).
 *
 * FORCED AND BLOCKING. CLI-only, and until it succeeds the schema stays behind
 * and every action refuses -- an installation does not run on v2 with its
 * history left in tables v2 does not read.
 *
 * HOW MUCH HISTORY IS AN EXPLICIT CHOICE, passed to the update command:
 *
 *   php cli.php cmd=update since=2years     what is newer than a cutoff
 *   php cli.php cmd=update since=20240101
 *   php cli.php cmd=update --all            everything
 *
 * Without one it prints v1's volume per site and year and fails, which leaves
 * the update pending. Rows older than the cutoff stay in v1's tables until the
 * optional drop removes them; v1's tables are never written.
 *
 * Resumable: an interrupted run continues where it stopped, and a finished site
 * is not read again. A later run must use the same cutoff.
 *
 * On an installation with no v1 tables -- one that was never 1.x -- there is
 * nothing to do.
 */
class Update061 extends \OWA\Core\Update {

    var $schema_version = 61;

    var $is_cli_mode_required = true;

    /** The table prefix v1 is read under; a test reads a fixture's. */
    var $prefix = 'owa_';

    function up( $force = false ) {

        $migrator = $this->migrator();

        if ( ! $migrator ) {

            $this->e->notice( 'No v1 tables: nothing to migrate.' );

            return true;
        }

        foreach ( $this->preflight( $migrator ) as $line ) {

            $this->e->notice( $line );
        }

        $raw_since = \OWA\Core\CoreAPI::getRequestParam( 'since' );
        $all       = (bool) \OWA\Core\CoreAPI::getRequestParam( 'all' );

        if ( (bool) $raw_since === $all ) {

            $this->e->notice( 'Choose how much history to migrate: cmd=update since=<yyyymmdd, or a period'
                . ' such as 2years or 18m>, or cmd=update --all. Rows older than the cutoff stay in the v1'
                . ' tables until they are dropped.' );

            return false;
        }

        $since = null;

        if ( $raw_since ) {

            $since = \OWA\Module\Base\Controller\PartitionsCli::resolveCutoff( $raw_since );

            if ( ! $since ) {

                $this->e->notice( sprintf(
                    'Could not read since="%s". Use yyyymmdd, or a period such as 2years, 18m, 90days.', $raw_since ) );

                return false;
            }
        }

        $migrator = $this->migrator( $since );

        foreach ( $migrator->sites() as $site_id ) {

            try {

                $progress = $migrator->migrateSite( $site_id );

            } catch ( \RuntimeException $e ) {

                $this->e->notice( $e->getMessage() );

                return false;
            }

            $this->e->notice( sprintf( 'v1 migration, site %s: read %d, wrote %d, refused %d%s',
                $site_id, $progress['rows_read'], $progress['rows_written'], $progress['rows_refused'],
                $progress['refusals'] ? ' ' . json_encode( $progress['refusals'] ) : '' ) );

            if ( empty( $progress['completed_at'] ) ) {

                return false;
            }
        }

        return true;
    }

    /**
     * Delete exactly the rows the migration wrote, site by site.
     *
     * The ids are derived from v1's rows, which the migration never writes, so
     * reading them again derives the same ids. After v1 is dropped there is
     * nothing to roll back to.
     */
    function down() {

        $migrator = $this->migrator();

        if ( ! $migrator ) {

            $this->e->notice( 'No v1 tables: nothing to revert.' );

            return true;
        }

        try {

            foreach ( $migrator->sites() as $site_id ) {

                $this->e->notice( sprintf( 'v1 migration, site %s: deleted %d rows.',
                    $site_id, $migrator->revertSite( $site_id ) ) );
            }

        } catch ( \RuntimeException $e ) {

            $this->e->notice( $e->getMessage() );

            return false;
        }

        return true;
    }

    /**
     * v1's volume per site and year, and what will be left behind.
     *
     * @return string[]
     */
    public function preflight( $migrator ) {

        $lines   = array( 'v1 page views, per site and year:' );
        $total   = 0;
        $orphans = 0;
        $unknown = array();

        foreach ( $migrator->volume() as $row ) {

            if ( ! $row['known'] ) {

                $unknown[ $row['site_id'] ] = true;
                $orphans += $row['rows'];

                continue;
            }

            $lines[] = sprintf( '  %-40s %4d  %10d', $row['site_id'], $row['year'], $row['rows'] );
            $total  += $row['rows'];
        }

        $lines[] = sprintf( '  %-40s %4s  %10d', 'total', '', $total );

        if ( $unknown ) {

            $lines[] = sprintf( '  Not migrated: %d rows for %d site ids no site carries any more.',
                $orphans, count( $unknown ) );
        }

        return $lines;
    }

    /** @return \OWA\Module\Base\Classes\Migration\RequestMigrator|null null without v1 tables */
    private function migrator( $since = null ) {

        $source = \OWA\Module\Base\Classes\Migration\V1Tables::name(
            \OWA\Module\Base\Classes\Migration\RequestMigrator::SOURCE, $this->prefix );

        if ( ! \OWA\Core\CoreAPI::dbSingleton()->tableExists( $source ) ) {

            return null;
        }

        return new \OWA\Module\Base\Classes\Migration\RequestMigrator(
            $this->prefix, \OWA\Module\Base\Classes\Migration\RequestMigrator::BATCH, $since );
    }
}

?>
