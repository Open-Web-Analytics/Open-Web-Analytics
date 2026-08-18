<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Convert an installation's dimension ids from 32-bit crc32 to 63-bit.
 *
 *   php cli.php cmd=rederive-dimension-ids --dry-run
 *   php cli.php cmd=rederive-dimension-ids
 *
 * WHY THIS IS A COMMAND AND NOT A SCHEMA UPDATE
 * Same reason partition-init is not one. It rewrites foreign key columns across
 * every fact table, which on a real installation is minutes to hours of I/O.
 * That belongs in a window an administrator chooses, not in whatever moment they
 * happen to run cmd=update -- and an update that dies at 80% does not record its
 * version, so a rerun would start from nothing.
 *
 * THE ORDER MATTERS, AND IT IS NOT THE OBVIOUS ONE
 * Dimension rows are COPIED to their new ids first, then fact keys are pointed
 * at the copies, then the originals are dropped. Renaming the dimension row
 * first would leave every fact row referencing an id that does not exist yet;
 * rewriting facts first would point them at ids that do not exist either. With
 * the copy in place both ids resolve for the whole of the middle phase, so a
 * report run against a half-migrated table still resolves every dimension.
 *
 * IT IS SAFE TO RUN AGAIN
 * crc32 ids are below 2^32 and derived ids are 63-bit, so a derived id lands in
 * the old range with probability about 2^-31. The map's new values therefore
 * essentially never appear in its own old-value space, which makes applying it
 * twice a no-op. Progress needs no bookkeeping: work still to do is visible in
 * the data as key values still in the old range.
 *
 * NORMALISATION IS PER DIMENSION AND MUST MATCH INGESTION EXACTLY
 * See DIMENSIONS below. Getting one wrong does not fail, it silently derives an
 * id that ingestion will never derive again, and that dimension's history stops
 * accumulating.
 */
class RederiveDimensionIdsCli extends PartitionsCli {

    /**
     * Every dimension whose id is derived from its own content, the column that
     * content lives in, and whether ingestion trims before hashing.
     *
     * setStringGuid() lowercases internally, so CASE never matters here. Trim
     * does: CampaignHandlers and SourceHandlers hash trim(strtolower($value))
     * but store the RAW value, so re-deriving from the stored column without the
     * trim produces an id that ingestion will never produce again.
     *
     * Deliberately a hand-audited list rather than anything inferred. The cost
     * of an inferred mistake here is silent and permanent.
     *
     * Excluded on purpose: base.visitor and base.session (ids are event guids,
     * not content), base.site (site_id is a string key chosen by the operator),
     * and base.location_dim (a composite of three columns -- handled separately
     * by locationKey()).
     */
    const DIMENSIONS = array(
        'base.document'        => array( 'column' => 'url',           'trim' => false ),
        'base.ua'              => array( 'column' => 'ua',            'trim' => false ),
        'base.os'              => array( 'column' => 'name',          'trim' => false ),
        'base.host'            => array( 'column' => 'host',          'trim' => false ),
        'base.referer'         => array( 'column' => 'url',           'trim' => false ),
        'base.search_term_dim' => array( 'column' => 'terms',         'trim' => false ),
        'base.ad_dim'          => array( 'column' => 'name',          'trim' => true  ),
        'base.campaign_dim'    => array( 'column' => 'name',          'trim' => true  ),
        'base.source_dim'      => array( 'column' => 'source_domain', 'trim' => true  ),
    );

    /** Anything at or below this is a crc32 id and still needs converting. */
    const NARROW_CEILING = 4294967296;   // 2^32

    function action() {

        $dry_run = (bool) $this->getParam( 'dry-run' );
        $db      = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! \OWA\Core\Lib::useNarrowGuid() ) {

            return $this->refuse(
                'This installation already derives 63-bit ids, so there is nothing to convert. '
              . 'A new installation is born this way and never needs this command.'
            );
        }

        $map = \OWA\Core\CoreAPI::entityFactory( 'base.guid_map' );

        if ( ! $dry_run ) {

            // createTable() is CREATE TABLE IF NOT EXISTS, so a false here on a
            // rerun is not necessarily a failure. The insert below is what would
            // actually break, and Db::query() reports that.
            $map->createTable();
        }

        $planned = $this->buildMap( $dry_run );

        $this->write( sprintf( '%s %s dimension id(s).',
            $dry_run ? 'Would convert' : 'Converting', number_format( $planned ) ) );

        if ( $dry_run ) {

            $this->reportFactWork();

            $this->write( '' );
            $this->write( sprintf( '%s key(s) still on a narrow id.',
                number_format( $this->countNarrowKeys() ) ) );
            $this->write( 'Dry run: nothing was changed.' );

            return;
        }

        // EVERY PHASE RUNS EVERY TIME, and every phase is a no-op once its work
        // is done. That is what makes a killed run recoverable: there is no
        // resume point to record and none to get wrong. Note that "nothing left
        // to plan" is NOT a reason to stop -- a run that died after dropping the
        // old dimension rows has nothing to plan and may still have fact keys
        // to repoint, and the map table is the only remaining record of what
        // they should become.
        $this->copyDimensionRows();
        $this->rewriteFactKeys();
        $this->dropOldDimensionRows();

        // Completion is VERIFIED, not assumed from having reached this line. A
        // run that died before here leaves the flag set, and the flag is what
        // keeps ingestion deriving ids that match the data. Clearing it while
        // anything is still narrow would split every dimension it touched.
        $remaining = $this->countNarrowKeys();

        if ( $remaining ) {

            return $this->fail( sprintf(
                '%s key(s) are still on a narrow id, so this installation stays on 32-bit ids '
              . 'for now. Nothing is inconsistent: run this command again to finish.',
                number_format( $remaining )
            ) );
        }

        \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', false );

        $dangling = $this->countDanglingKeys();

        $this->write( '' );

        if ( $dangling ) {

            $this->write( sprintf(
                '%s fact key(s) reference a dimension row that does not exist. They were already '
              . 'unresolvable before this ran and are left alone: there is no content to derive '
              . 'an id from, and inventing a row would be inventing data.',
                number_format( $dangling )
            ) );
        }

        $this->write( 'Done. This installation now derives 63-bit ids.' );
    }

    /**
     * The id a row's content derives today, or null when the row cannot be
     * converted (no content to hash).
     *
     * @return string|null
     */
    protected function deriveFor( $entity_name, $row ) {

        if ( $entity_name === 'base.location_dim' ) {

            return $this->locationKey( $row );
        }

        $spec  = self::DIMENSIONS[ $entity_name ];
        $value = isset( $row[ $spec['column'] ] ) ? (string) $row[ $spec['column'] ] : '';

        if ( $spec['trim'] ) {

            $value = trim( strtolower( $value ) );
        }

        if ( $value === '' ) {

            return null;
        }

        return (string) \OWA\Core\Lib::wideStringGuid( $value );
    }

    /**
     * location_dim hashes country+state+city concatenated, each trimmed and
     * lowercased -- see Geolocation::generateId(). Reproduced rather than called
     * because that method reads from a populated geolocation object.
     *
     * @return string|null
     */
    protected function locationKey( $row ) {

        $key = trim( strtolower( (string) ( $row['country'] ?? '' ) ) )
             . trim( strtolower( (string) ( $row['state']   ?? '' ) ) )
             . trim( strtolower( (string) ( $row['city']    ?? '' ) ) );

        return $key === '' ? null : (string) \OWA\Core\Lib::wideStringGuid( $key );
    }

    /** @return string  the map table's real name */
    protected function mapTable() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.guid_map' )->getTableName();
    }

    /** Every dimension this command converts, including the composite one. */
    protected function dimensionNames() {

        return array_merge( array_keys( self::DIMENSIONS ), array( 'base.location_dim' ) );
    }

    /**
     * Record old -> new for every dimension row still on a narrow id.
     *
     * @return int  rows planned
     */
    protected function buildMap( $dry_run ) {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $planned = 0;

        foreach ( $this->dimensionNames() as $entity_name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entity_name );
            $table  = $entity->getTableName();

            $rows = $db->get_results( sprintf(
                'SELECT * FROM %s WHERE id < %d', $table, self::NARROW_CEILING
            ) );

            $n = 0;

            foreach ( (array) $rows as $row ) {

                $row    = (array) $row;
                $new_id = $this->deriveFor( $entity_name, $row );

                // Nothing to hash, or already converted.
                if ( $new_id === null || (string) $new_id === (string) $row['id'] ) {

                    continue;
                }

                $n++;

                if ( $dry_run ) {

                    continue;
                }

                $db->query( sprintf(
                    "INSERT INTO %s (id, entity, old_id, new_id) VALUES (%d, '%s', %d, %s) "
                  . "ON DUPLICATE KEY UPDATE new_id = VALUES(new_id)",
                    $this->mapTable(),
                    \OWA\Core\Lib::wideStringGuid( $entity_name . '|' . $row['id'] ),
                    $db->prepare( $entity_name ),
                    (int) $row['id'],
                    $new_id
                ) );
            }

            if ( $n ) {

                $this->write( sprintf( '  %-22s %s row(s)', $entity->getTableName(), number_format( $n ) ) );
            }

            $planned += $n;
        }

        return $planned;
    }

    /**
     * Every fact column that references a dimension this command converts.
     *
     * Read from the entity definitions rather than listed here: the FK metadata
     * is declared and complete (owa_request alone carries 14, including
     * prior_document_id, which no handler resolves), and a hand-written list
     * would rot the moment a column is added.
     *
     * @return array  table => array( column => entity_name )
     */
    protected function factKeyColumns( $only_table = null ) {

        $s     = \OWA\Core\CoreAPI::serviceSingleton();
        $ns    = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );
        $wanted = array_flip( $this->dimensionNames() );
        $out   = array();

        foreach ( $s->modules['base']->getEntities() as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.' . $name );

            if ( ! $entity instanceof \OWA\Core\Entity\FactTable ) {

                continue;
            }

            $table = $ns . $name;

            if ( $only_table && $table !== $only_table ) {

                continue;
            }

            foreach ( $entity->properties as $column => $property ) {

                if ( ! is_object( $property ) || ! $property->isForeignKey() ) {

                    continue;
                }

                $fk = (array) $property->getForeignKey();

                // Only keys that point at a converted dimension's id column.
                if ( count( $fk ) < 2 || $fk[1] !== 'id' || ! isset( $wanted[ $fk[0] ] ) ) {

                    continue;
                }

                $out[ $table ][ $column ] = $fk[0];
            }
        }

        return $out;
    }

    /** Copy each dimension row to its new id, so both resolve mid-migration. */
    protected function copyDimensionRows() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->dimensionNames() as $entity_name ) {

            $entity  = \OWA\Core\CoreAPI::entityFactory( $entity_name );
            $table   = $entity->getTableName();
            $columns = array_keys( $entity->properties );
            $select  = array();

            foreach ( $columns as $c ) {

                $select[] = ( $c === 'id' ) ? 'm.new_id' : ( 'd.' . $c );
            }

            $db->query( sprintf(
                'INSERT IGNORE INTO %s (%s) SELECT %s FROM %s d '
              . "JOIN %s m ON m.entity = '%s' AND m.old_id = d.id",
                $table, implode( ', ', $columns ), implode( ', ', $select ), $table,
                $this->mapTable(), $db->prepare( $entity_name )
            ) );
        }

        $this->write( 'Dimension rows copied to their new ids.' );
    }

    /** Point fact keys at the copies, one partition at a time. */
    protected function rewriteFactKeys() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $map     = $this->mapTable();
        $columns = $this->factKeyColumns( $this->getParam( 'table' ) ?: null );

        foreach ( $columns as $table => $cols ) {

            $partitions = $db->listPartitions( $table );

            // An unpartitioned fact table is one statement. A partitioned one is
            // a sequence of bounded statements: smaller locks, resumable, and
            // every partition except the current month is data nothing is
            // writing to.
            $units = $partitions ? $partitions : array( array( 'name' => null ) );

            foreach ( $units as $unit ) {

                $scope = $unit['name'] ? sprintf( ' PARTITION (%s)', $unit['name'] ) : '';

                foreach ( $cols as $column => $entity_name ) {

                    $db->query( sprintf(
                        "UPDATE %s%s f JOIN %s m ON m.entity = '%s' AND m.old_id = f.%s "
                      . 'SET f.%s = m.new_id',
                        $table, $scope, $map, $db->prepare( $entity_name ), $column, $column
                    ) );
                }
            }

            $this->write( sprintf( '  %-30s %d key column(s) over %d partition(s)',
                $table, count( $cols ), count( $units ) ) );
        }
    }

    /** Only once nothing references them. */
    protected function dropOldDimensionRows() {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $map = $this->mapTable();

        foreach ( $this->dimensionNames() as $entity_name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $entity_name )->getTableName();

            $db->query( sprintf(
                "DELETE d FROM %s d JOIN %s m ON m.entity = '%s' AND m.old_id = d.id",
                $table, $map, $db->prepare( $entity_name )
            ) );
        }

        $this->write( 'Old dimension rows removed.' );
    }

    /**
     * Narrow ids that this command can still do something about.
     *
     * The completion test, and it reads the DATA rather than any record of what
     * the command believes it did. A killed run, a partition added by rotation
     * mid-run, or rows ingestion wrote while the flag was still set are all
     * invisible to bookkeeping and all visible here.
     *
     * A fact key only counts if the map can convert it. Keys pointing at a
     * dimension row that does not exist cannot be converted by anything: there
     * is no content to derive an id from. They are pre-existing breakage that
     * this command neither caused nor can repair -- measured on one installation
     * at 14,097 of them, every single owa_session dimension key, referencing
     * 3,440 distinct document ids that never had a row. Counting those as
     * unfinished work would pin such an installation to 32-bit ids forever.
     *
     * @return int
     */
    protected function countNarrowKeys() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $map   = $this->mapTable();
        $total = 0;

        // A dimension row on a narrow id always has content to re-derive from,
        // so it always counts.
        foreach ( $this->dimensionNames() as $entity_name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $entity_name )->getTableName();

            $rows = $db->get_results( sprintf(
                'SELECT COUNT(*) AS n FROM %s WHERE id > 0 AND id < %d', $table, self::NARROW_CEILING
            ) );

            $total += (int) ( ( (array) ( $rows[0] ?? array() ) )['n'] ?? 0 );
        }

        foreach ( $this->factKeyColumns() as $table => $cols ) {

            foreach ( $cols as $column => $entity_name ) {

                $rows = $db->get_results( sprintf(
                    "SELECT COUNT(*) AS n FROM %s f JOIN %s m ON m.entity = '%s' AND m.old_id = f.%s "
                  . 'WHERE f.%s > 0 AND f.%s < %d',
                    $table, $map, $db->prepare( $entity_name ), $column,
                    $column, $column, self::NARROW_CEILING
                ) );

                $total += (int) ( ( (array) ( $rows[0] ?? array() ) )['n'] ?? 0 );
            }
        }

        return $total;
    }

    /**
     * Fact keys on a narrow id that reference a dimension row which does not
     * exist, so nothing can convert them.
     *
     * Reported rather than fixed. They are already broken -- a report following
     * one of these resolves nothing today, before any migration -- and inventing
     * a dimension row for an id whose content nobody recorded would be inventing
     * data.
     *
     * @return int
     */
    protected function countDanglingKeys() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $map     = $this->mapTable();
        $total   = 0;

        foreach ( $this->factKeyColumns() as $table => $cols ) {

            foreach ( $cols as $column => $entity_name ) {

                $rows = $db->get_results( sprintf(
                    "SELECT COUNT(*) AS n FROM %s f LEFT JOIN %s m ON m.entity = '%s' AND m.old_id = f.%s "
                  . 'WHERE f.%s > 0 AND f.%s < %d AND m.old_id IS NULL',
                    $table, $map, $db->prepare( $entity_name ), $column,
                    $column, $column, self::NARROW_CEILING
                ) );

                $total += (int) ( ( (array) ( $rows[0] ?? array() ) )['n'] ?? 0 );
            }
        }

        return $total;
    }

    /** What the fact side would do, for --dry-run. */
    protected function reportFactWork() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->factKeyColumns( $this->getParam( 'table' ) ?: null ) as $table => $cols ) {

            $parts = $db->listPartitions( $table );

            $this->write( sprintf( '  %-30s %d key column(s), %d partition(s)',
                $table, count( $cols ), $parts ? count( $parts ) : 1 ) );
        }
    }
}
