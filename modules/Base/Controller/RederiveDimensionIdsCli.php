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
 *   php cli.php cmd=rederive-dimension-ids --force
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

        // Not a reporting dimension, but its id is content-derived exactly like
        // one: owa_site.id is generateId( site_id ), and nine call sites load a
        // site that way -- makeUrlCanonical among them. Leaving it behind breaks
        // every one of them silently, and makeUrlCanonical failing means URLs
        // stop being canonicalised at all: no query-string filters, no default
        // page, no domain aliases. Every document id derived after that differs
        // from the ones derived before, which splits the dimension on the very
        // installation that just migrated.
        'base.site'            => array( 'column' => 'site_id',       'trim' => false ),

        // A FACT table with a content-derived primary key:
        // CommerceTransactionHandlers sets it to generateId( ct_order_id ), so a
        // repeat or corrected transaction finds the existing row and updates it.
        // Leave it behind and the same order derives a different id after the
        // migration, finds nothing, and writes a DUPLICATE transaction. Line
        // items reference the order_id string rather than this id, so nothing
        // else has to follow.
        'base.commerce_transaction_fact' => array( 'column' => 'order_id', 'trim' => false ),

        // Read by job_name and never by id, so a stale value here is inert --
        // but converting it costs a handful of rows and keeps one invariant
        // instead of an invariant plus an exception nobody will remember.
        'base.scheduled_job'   => array( 'column' => 'job_name',      'trim' => false ),
    );

    /**
     * Content-derived dimension keys that are NOT declared foreign keys.
     *
     * owa_click.target_id holds setStringGuid( target_url ), which is a document
     * id whenever the click went to a page of this site and meaningless when it
     * went anywhere else -- 59,083 of 266,498 resolved on one installation. That
     * is a conditional reference, not a referential guarantee, so the entity does
     * not declare it and key enumeration cannot see it.
     *
     * It still has to be rewritten. Leaving it behind would break every one of
     * those 59,083 working references, silently, at the moment the documents
     * they point at move.
     *
     * @var array  table => array( column => dimension entity )
     */
    const UNDECLARED_KEYS = array(
        'click'     => array( 'target_id' => 'base.document' ),

        // owa_site_user.site_id is a BIGINT holding owa_site.id, not the site_id
        // string the fact tables carry. Undeclared, and the only other place a
        // derived site id is stored.
        'site_user' => array( 'site_id'   => 'base.site' ),

        // FeedRequestHandlers writes four derived ids straight onto the row
        // (lines 64-73) and the entity declares none of them, so nothing that
        // reads key metadata can see them.
        'feed_request' => array(
            'ua_id'       => 'base.ua',
            'os_id'       => 'base.os',
            'document_id' => 'base.document',
            'host_id'     => 'base.host',
        ),
    );

    /** Rows sampled per table when checking for ids that were never converted. */
    const VERIFY_SAMPLE = 25;

    /**
     * Map rows per INSERT.
     *
     * One statement per row is what this replaced, and it is the whole cost of
     * the planning phase: measured at roughly 145 rows a second on a real
     * installation, which is about fifteen minutes to plan 136,462 ids and would
     * be hours on an installation with millions of dimension rows.
     *
     * 500 keeps each statement comfortably inside any sane max_allowed_packet --
     * four integers and a short entity name per row, so on the order of 30KB --
     * while removing 499 round trips out of every 500.
     */
    const INSERT_BATCH = 500;

    /** Anything at or below this is a crc32 id and still needs converting. */
    const NARROW_CEILING = 4294967296;   // 2^32

    function action() {

        $dry_run = (bool) $this->getParam( 'dry-run' );
        $db      = \OWA\Core\CoreAPI::dbSingleton();

        $force = (bool) $this->getParam( 'force' );

        // TWO GATES, AND THEY ANSWER DIFFERENT QUESTIONS.
        //
        // The flag answers "should this installation be converting at all", and
        // it is the cheap one: an ordinary rerun stops here without touching a
        // fact table. That is what makes running this repeatedly harmless.
        //
        // --force skips it and lets the DATA decide instead, for the case the
        // flag cannot describe: an installation whose flag was cleared while keys
        // were still unconverted, by hand or by a mishap, which would otherwise
        // be refused help precisely when it needs it.
        if ( ! $force && ! \OWA\Core\CoreAPI::getSetting( 'base', 'use_32bit_hash' ) ) {

            if ( \OWA\Core\Lib::useNarrowGuid() ) {

                return $this->refuse(
                    'This installation still derives 32-bit ids because its schema updates have '
                  . 'not been applied. Run cli.php cmd=update first: it records what needs '
                  . 'converting, and this command clears it again at the end.'
                );
            }

            return $this->refuse(
                'This installation already derives 63-bit ids, so there is nothing to convert. '
              . 'A new installation is born this way and never needs this command. Use --force '
              . 'to check the data anyway.'
            );
        }

        // The second gate reads the rows, so --force and a resumed run both get
        // a truthful answer about what is actually left.
        if ( ! $this->workRemains() ) {

            // Nothing PLANNED is not the same as nothing WRONG. An entity left
            // out of this command's list has no work to plan and would sail
            // straight through here, which is how owa_site was missed. Check the
            // data before believing it.
            $stale = $this->findStaleDerivedIds();

            if ( $stale ) {

                return $this->fail( sprintf(
                    'Nothing left to plan, but %d table(s) still hold rows stored at a 32-bit '
                  . 'derived id -- see the notices above. Those entities are not covered by this '
                  . 'command, so it cannot convert them and will not pretend the job is done.',
                    $stale
                ) );
            }

            // If the flag is still set the work finished without it being
            // cleared, which is exactly the killed-run case, so finish the job
            // rather than leaving the installation pinned.
            if ( ! $dry_run && \OWA\Core\CoreAPI::getSetting( 'base', 'use_32bit_hash' ) ) {

                // workRemains() said no and findStaleDerivedIds() found nothing,
                // but both read queries that return nothing when they fail.
                if ( ! $this->databaseIsAnswering() ) {

                    return $this->fail(
                        'The database stopped answering, so "nothing left to convert" cannot be '
                      . 'trusted. This installation keeps deriving 32-bit ids until a run finishes '
                      . 'cleanly. Run the command again once the database is healthy.'
                    );
                }

                $this->dropMap();
                \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', false );

                $this->write( 'Nothing left to convert. This installation now derives 63-bit ids.' );

                return;
            }

            return $this->refuse(
                'Nothing to convert: no dimension row and no convertible key is still on a '
              . '32-bit id. A new installation is born this way and never needs this command.'
            );
        }

        if ( ! $dry_run ) {

            $this->createMap();
        }

        $planned = $this->buildMap( $dry_run );

        $this->write( sprintf( '%s %s dimension id(s).',
            $dry_run ? 'Would convert' : 'Converting', number_format( $planned ) ) );

        if ( $dry_run ) {

            $this->reportFactWork();

            $this->write( '' );
            $left = $this->countNarrowKeys();

            $this->write( $left === null
                ? 'Could not count what is left: the database stopped answering.'
                : sprintf( '%s key(s) still on a narrow id.', number_format( $left ) ) );
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

        if ( $remaining === null ) {

            return $this->fail(
                'Could not confirm what is left to convert -- the database stopped answering. '
              . 'Nothing is inconsistent: this installation keeps deriving 32-bit ids until a run '
              . 'finishes cleanly, so run this command again once the database is healthy.'
            );
        }

        if ( $remaining ) {

            return $this->fail( sprintf(
                '%s key(s) are still on a narrow id, so this installation stays on 32-bit ids '
              . 'for now. Nothing is inconsistent: run this command again to finish.',
                number_format( $remaining )
            ) );
        }

        // Everything above concluded "nothing left" from queries that return
        // nothing when they fail. Prove the connection is alive before acting on
        // that, or a database that died mid-run reads as a finished migration.
        if ( ! $this->databaseIsAnswering() ) {

            return $this->fail(
                'The database stopped answering before this could be confirmed. Run the command '
              . 'again once it is healthy; nothing is inconsistent in the meantime.'
            );
        }

        // A STRONGER CHECK THAN "NOTHING IS STILL NARROW".
        //
        // Counting narrow keys proves the rewrite ran. It does not prove the
        // result is USABLE: an entity left out of the migration entirely has no
        // narrow keys to count, because nothing ever planned any for it. That is
        // exactly how owa_site was missed -- its id is generateId( site_id ),
        // nine call sites load a site by it, and the only symptom was that they
        // silently stopped finding the row.
        //
        // So before declaring victory, re-derive each row's id from its own
        // content and confirm it is the id the row is actually stored at. That
        // is the property every lookup in the codebase depends on, and it is
        // checked against whatever this installation really contains rather than
        // against a fixture someone imagined.
        $stale = $this->findStaleDerivedIds();

        if ( $stale ) {

            return $this->fail( sprintf(
                'Converted, but %d table(s) still hold rows stored at a 32-bit derived id. Every '
              . 'lookup that computes those ids will miss. This installation stays on 32-bit ids '
              . 'until that is understood -- see the notices above.',
                $stale
            ) );
        }

        $dangling = $this->countDanglingKeys();

        // Completion verified, so the map has done its job. It is only needed
        // BETWEEN runs -- once nothing is left to resume and the flag is about to
        // clear, keeping it would leave one row per converted dimension behind
        // for good. Counted before dropping, since countDanglingKeys() reads it.
        $this->dropMap();

        \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', false );

        $this->write( '' );

        $unconvertible = $this->countUnconvertibleDimensionRows();

        if ( $unconvertible ) {

            $this->write( sprintf(
                '%s dimension row(s) hold no content to derive an id from -- an owa_host row with '
              . 'only an IP, for instance -- so nothing can convert them. Nothing will ever derive '
              . 'those ids again either, so they are left where they are.',
                number_format( $unconvertible )
            ) );
        }

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

    /**
     * Run a COUNT query, refusing to read failure as zero.
     *
     * A COUNT(*) ALWAYS returns exactly one row. An empty result therefore means
     * the query did not run -- and Db::query() swallows errors, so a dropped
     * connection looks identical to a table with nothing in it.
     *
     * That is not hypothetical. On a real migration the database became briefly
     * unreachable near the end of the run: the drop phase silently did nothing,
     * every count came back "empty" and was read as zero, the completion check
     * concluded there was nothing left, the flag failed to clear -- silently --
     * and the command printed "Done. This installation now derives 63-bit ids."
     * over a half-converted database.
     *
     * @param string $sql
     * @return int|null  null when the query did not run
     */
    protected function countOrNull( $sql ) {

        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results( $sql );

        if ( ! $rows || ! isset( $rows[0] ) ) {

            return null;
        }

        $row = (array) $rows[0];

        return array_key_exists( 'n', $row ) ? (int) $row['n'] : null;
    }

    /**
     * Is the database still answering?
     *
     * Checked before believing any "there is nothing left to do" conclusion,
     * because every one of those conclusions is drawn from a query that returns
     * nothing when it fails.
     *
     * @return bool
     */
    protected function databaseIsAnswering() {

        return $this->countOrNull( 'SELECT COUNT(*) AS n FROM DUAL' ) !== null;
    }

    /** @return string  the map table's real name */
    protected function mapTable() {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . 'guid_map';
    }

    /**
     * Build the scratch table the fact-side rewrite joins against.
     *
     * Raw DDL rather than an entity. It is not part of anyone's schema: a new
     * installation never runs this command, so registering it as an entity only
     * created an empty table on every install that would never be used. It is
     * also not portable in any meaningful sense -- the rewrite around it uses
     * UPDATE ... PARTITION, INSERT IGNORE and multi-table DELETE, and the
     * partitioning it walks is MySQL-only to begin with.
     *
     * @return void
     */
    protected function createMap() {

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
          . ' id BIGINT NOT NULL,'
          . ' entity VARCHAR(255) NOT NULL,'
          . ' old_id BIGINT NOT NULL,'
          . ' new_id BIGINT NOT NULL,'
          . ' PRIMARY KEY (id),'
          . ' KEY entity_old_id (entity, old_id)'
          . ')',
            $this->mapTable()
        ) );
    }

    /**
     * Is there anything left for this command to do?
     *
     * Two sources, and both are read from the data. A dimension row still on a
     * narrow id has content to re-derive from. A map with rows means a previous
     * run got as far as planning and may have died before finishing the rewrite,
     * and the map is the only record of what those keys should become.
     *
     * @return bool
     */
    protected function workRemains() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->dimensionNames() as $entity_name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $entity_name )->getTableName();

            $n = $this->countOrNull( sprintf(
                'SELECT COUNT(*) AS n FROM %s WHERE id > 0 AND id < %d',
                $table, self::NARROW_CEILING
            ) );

            // A query that did not run tells us nothing, so assume there IS work
            // rather than concluding there is none.
            if ( $n === null || $n > 0 ) {

                return true;
            }
        }

        if ( ! $db->get_results( sprintf( 'SHOW TABLES LIKE "%s"', $this->mapTable() ) ) ) {

            return false;
        }

        $n = $this->countOrNull( sprintf( 'SELECT COUNT(*) AS n FROM %s', $this->mapTable() ) );

        return $n === null ? true : $n > 0;
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

            $n      = 0;
            $values = array();

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

                $values[] = sprintf(
                    "(%d, '%s', %d, %s)",
                    \OWA\Core\Lib::wideStringGuid( $entity_name . '|' . $row['id'] ),
                    $db->prepare( $entity_name ),
                    (int) $row['id'],
                    $new_id
                );

                if ( count( $values ) >= self::INSERT_BATCH ) {

                    $this->insertMapRows( $values );

                    $values = array();
                }
            }

            if ( $values ) {

                $this->insertMapRows( $values );

                $values = array();
            }

            if ( $n ) {

                $this->write( sprintf( '  %-22s %s row(s)', $entity->getTableName(), number_format( $n ) ) );
            }

            $planned += $n;
        }

        return $planned;
    }

    /**
     * Write one batch of map rows.
     *
     * ON DUPLICATE KEY UPDATE keeps this re-runnable: a resumed run re-plans
     * rows it already planned, and rewriting them with the same value is a
     * no-op rather than a duplicate-key failure.
     *
     * @param string[] $values  pre-formatted VALUES tuples
     * @return void
     */
    protected function insertMapRows( array $values ) {

        if ( ! $values ) {

            return;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, entity, old_id, new_id) VALUES %s '
          . 'ON DUPLICATE KEY UPDATE new_id = VALUES(new_id)',
            $this->mapTable(),
            implode( ', ', $values )
        ) );
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

        // Columns that are dimension keys in practice but carry no declaration.
        $ns = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );

        foreach ( self::UNDECLARED_KEYS as $suffix => $cols ) {

            $table = $ns . $suffix;

            if ( $only_table && $table !== $only_table ) {

                continue;
            }

            foreach ( $cols as $column => $entity_name ) {

                $out[ $table ][ $column ] = $entity_name;
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
     * Returns NULL when a count could not be run at all, which must never be
     * confused with "nothing left": a dropped connection produced exactly that
     * confusion on a real migration and the command declared success over a
     * half-converted database.
     *
     * @return int|null
     */
    protected function countNarrowKeys() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $map   = $this->mapTable();
        $total = 0;

        // No map means nothing can be converted -- every fact key below is
        // counted through a join to it. That is a legitimate zero, not a failed
        // query, and the difference matters: the map is dropped once a migration
        // completes, so treating its absence as an error would make every
        // subsequent run refuse on a perfectly healthy database.
        $have_map = (bool) $db->get_results( sprintf( 'SHOW TABLES LIKE "%s"', $map ) );

        // A dimension row only counts if its content can still produce an id.
        //
        // Some cannot: 5,198 owa_host rows on a real installation carry an empty
        // host and nothing else but an IP, so deriveFor() has nothing to hash and
        // the row can never be planned. Counting those as outstanding work makes
        // completion unreachable -- the migration converts everything it can,
        // refuses to clear the flag because the count is non-zero, and the
        // installation is stuck part-converted for good. That is not theoretical:
        // it is what happened, and it left site lookups broken because owa_site
        // HAD converted while the flag still said 32-bit.
        //
        // Nothing will ever derive those ids again either, since hashing empty
        // content yields no id at all, so leaving them where they are costs
        // nothing.
        foreach ( $this->dimensionNames() as $entity_name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $entity_name )->getTableName();

            $rows = $db->get_results( sprintf(
                'SELECT * FROM %s WHERE id > 0 AND id < %d', $table, self::NARROW_CEILING
            ) );

            // Distinguish "no rows" from "the query did not run".
            if ( $rows === null && $this->countOrNull( sprintf( 'SELECT COUNT(*) AS n FROM %s', $table ) ) === null ) {

                return null;
            }

            foreach ( (array) $rows as $row ) {

                if ( $this->deriveFor( $entity_name, (array) $row ) !== null ) {

                    $total++;
                }
            }
        }

        if ( ! $have_map ) {

            return $total;
        }

        foreach ( $this->factKeyColumns() as $table => $cols ) {

            foreach ( $cols as $column => $entity_name ) {

                $n = $this->countOrNull( sprintf(
                    "SELECT COUNT(*) AS n FROM %s f JOIN %s m ON m.entity = '%s' AND m.old_id = f.%s "
                  . 'WHERE f.%s > 0 AND f.%s < %d',
                    $table, $map, $db->prepare( $entity_name ), $column,
                    $column, $column, self::NARROW_CEILING
                ) );

                if ( $n === null ) {

                    return null;
                }

                $total += $n;
            }
        }

        return $total;
    }

    /**
     * Dimension rows still on a narrow id that have nothing to re-derive from.
     *
     * Reported so the number is visible rather than silently skipped.
     *
     * @return int
     */
    protected function countUnconvertibleDimensionRows() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $total = 0;

        foreach ( $this->dimensionNames() as $entity_name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $entity_name )->getTableName();

            $rows = $db->get_results( sprintf(
                'SELECT * FROM %s WHERE id > 0 AND id < %d', $table, self::NARROW_CEILING
            ) );

            foreach ( (array) $rows as $row ) {

                if ( $this->deriveFor( $entity_name, (array) $row ) === null ) {

                    $total++;
                }
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

                $n = $this->countOrNull( sprintf(
                    "SELECT COUNT(*) AS n FROM %s f LEFT JOIN %s m ON m.entity = '%s' AND m.old_id = f.%s "
                  . 'WHERE f.%s > 0 AND f.%s < %d AND m.old_id IS NULL',
                    $table, $map, $db->prepare( $entity_name ), $column,
                    $column, $column, self::NARROW_CEILING
                ) );

                // Reported for information only, so an unrunnable count is
                // simply not counted rather than aborting the run.
                $total += (int) $n;
            }
        }

        return $total;
    }

    /**
     * Find rows still stored at a 32-bit derived id, WITHOUT consulting the list
     * of entities this command knows about.
     *
     * This is the point. A check that walks DIMENSIONS cannot notice an entity
     * missing from DIMENSIONS -- and an entity left out entirely also has no
     * narrow keys to count, because nothing ever planned any for it. That is
     * precisely how owa_site was missed: its id is generateId( site_id ), nine
     * call sites load a site by it, and the only symptom was that they silently
     * stopped finding the row.
     *
     * So this asks the data instead. For a sample of every entity's rows it
     * tries to reproduce the stored id by hashing that row's own columns with
     * the OLD scheme. Anything it reproduces is a content-derived id that did
     * not get converted, whatever anyone believed about which entities count.
     *
     * Sampled rather than exhaustive: a fact table's id is an event guid and
     * will not reproduce from any column, so it costs a few rows to rule out,
     * and one surviving stale row in a dimension is enough to stop the run.
     *
     * @return int  tables holding stale derived ids
     */
    protected function findStaleDerivedIds() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $tables  = 0;

        foreach ( $service->modules['base']->getEntities() as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.' . $name );
            $table  = $entity->getTableName();

            $rows = $db->get_results( sprintf( 'SELECT * FROM %s LIMIT %d', $table, self::VERIFY_SAMPLE ) );

            $stale  = 0;
            $column = null;

            foreach ( (array) $rows as $row ) {

                $row = (array) $row;

                if ( ! isset( $row['id'] ) ) {

                    continue;
                }

                foreach ( $row as $col => $value ) {

                    if ( $col === 'id' || ! is_string( $value ) || $value === '' ) {

                        continue;
                    }

                    // Both forms ingestion uses. Case is irrelevant: the hash
                    // lowercases internally.
                    foreach ( array( $value, trim( strtolower( $value ) ) ) as $candidate ) {

                        if ( (string) crc32( strtolower( $candidate ) ) === (string) $row['id'] ) {

                            $stale++;
                            $column = $col;

                            continue 3;
                        }
                    }
                }
            }

            if ( $stale ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: at least %d sampled row(s) are still stored at a 32-bit id derived from '
                  . 'their own %s column. This entity was not converted. Every lookup that computes '
                  . 'that id will miss.',
                    $table, $stale, $column
                ) );

                $tables++;
            }
        }

        return $tables;
    }

    /**
     * Remove the map, once there is nothing left to resume.
     *
     * Deliberately NOT called on any other path. While a migration is
     * incomplete this table is the only thing linking an old id to its new one,
     * and it cannot be rebuilt after the old dimension rows are gone.
     *
     * @return void
     */
    protected function dropMap() {

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DROP TABLE IF EXISTS %s', $this->mapTable() ) );
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
