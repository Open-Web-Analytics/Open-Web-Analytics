<?php

/*
 * Partition fixtures for the tests that build partitioned tables by hand.
 * Kept out of bootstrap_owa.php so a test can use them without the bootstrap's
 * side effects (it holds every scheduled job's lease for the run).
 */

/**
 * Partition a fixture table by range with a plain ALTER TABLE.
 *
 * The table's primary key has to carry the column already.
 *
 * @param string $table
 * @param array<string, string> $ranges partition name => exclusive upper bound
 * @param string $column
 */
function owa_test_partition_table(string $table, array $ranges, string $column = 'yyyymmdd'): void
{
    $db = \OWA\Core\CoreAPI::dbSingleton();

    if (!$db->query(sprintf('ALTER TABLE %s%s', $table, $db->makePartitionClause($column, $ranges)))) {
        throw new \RuntimeException('partitioning ' . $table . ' failed: ' . $db->lastQueryError());
    }
}

/**
 * Ranges for a tiered fixture: blocks of whole years over old history, the
 * granularity over the detail window and the lead.
 *
 * The tail blocks are widened exactly as Db::planPartitionCompaction() widens
 * them, so a rotate over the result has nothing to merge.
 *
 * @param string   $min_yyyymmdd  oldest data present
 * @param string   $through       upper boundary to reach (exclusive)
 * @param string   $granularity   granularity for the detail window
 * @param int      $detail_months how much recent history stays fine
 * @param int|null $limit         the partition ceiling; null for none
 * @return array<string, string>
 */
function owa_test_tiered_ranges($min_yyyymmdd, $through, $granularity, $detail_months, $limit = null): array
{
    $boundary = date('Ymd', strtotime(date('Ym') . '01 -' . (int) $detail_months . ' months'));
    $last_day = date('Ymd', strtotime($through . ' -1 day'));

    if ((string) $min_yyyymmdd >= $boundary) {
        return \OWA\Core\Db::makePartitionRanges($min_yyyymmdd, $last_day, $granularity);
    }

    $first_year = (int) substr($min_yyyymmdd, 0, 4);
    $last_year  = (int) substr($boundary, 0, 4);
    $fine       = \OWA\Core\Db::makePartitionRanges($boundary, $last_day, $granularity);
    $years      = max(1, $last_year - $first_year + ($boundary > $last_year . '0101' ? 1 : 0));
    $block      = 1;

    if ($limit !== null) {
        for (; $block < \OWA\Core\Db::PARTITION_MAX_YEARS_PER_BLOCK; $block++) {
            if (count($fine) + (int) ceil($years / $block) <= $limit) {
                break;
            }
        }
    }

    return array_merge(\OWA\Core\Db::yearBlocks($first_year, $boundary, $block), $fine);
}
