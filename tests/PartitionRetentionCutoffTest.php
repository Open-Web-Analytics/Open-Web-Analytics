<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Db;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * No merge of old partitions crosses a retention cutoff (Db::splitAtCutoff()).
 *
 * A partition is dropped whole, so one merged across the cutoff would keep the
 * days before it until its last day aged out. PartitionOperationsTest runs the
 * plan against a real table; these are the decisions, without a database.
 */
final class PartitionRetentionCutoffTest extends TestCase
{
    private static function span(string $start, string $less_than, ?string $name = null): array
    {
        return ['name' => $name ?? 'p' . $start, 'start' => $start, 'less_than' => $less_than];
    }

    /** Whole years before the cutoff, a year straddling it, and years after. */
    public function testAYearStraddlingTheCutoffIsSplitAtItAndThenByMonth(): void
    {
        $old = [
            self::span('20200101', '20210101'),
            self::span('20210101', '20220101'),
            self::span('20220101', '20230101'),
            self::span('20230101', '20240101'),
        ];

        $out = Db::splitAtCutoff($old, '20210915');

        $this->assertSame([$old[2], $old[3]], $out['tail'], 'whole years from the next January are merged as before');
        $this->assertCount(1, $out['operations']);
        $this->assertSame(['p20210101'], $out['operations'][0]['names']);
        $this->assertSame([
            'p20210101' => '20210915',
            'p20210915' => '20211001',
            'p20211001' => '20211101',
            'p20211101' => '20211201',
            'p20211201' => '20220101',
        ], $out['operations'][0]['ranges'], 'split at the cutoff, then by month to the next January');

        $this->assertSame(2, $out['doomed'], 'the year before, and the part of the straddler before the cutoff');
        $this->assertSame(4, $out['added']);
    }

    /** A block reaching past the next January keeps that part whole, for a later run to plan as years. */
    public function testABlockOfYearsKeepsWhatFollowsTheNextJanuaryInOnePartition(): void
    {
        $out = Db::splitAtCutoff([self::span('20200101', '20250101')], '20211115');

        $this->assertSame([
            'p20200101' => '20211115',
            'p20211115' => '20211201',
            'p20211201' => '20220101',
            'p20220101' => '20250101',
        ], $out['operations'][0]['ranges']);
        $this->assertSame([], $out['tail']);
    }

    /**
     * The rest of the cutoff's year is left as it is, and so is a month that
     * straddles the cutoff: the cutoff moves daily, and a split each day would
     * rewrite a partition each day. A monthly table is not split either.
     */
    public function testMonthsAreNotSplitAndTheCutoffsYearIsNotMerged(): void
    {
        $old = [
            self::span('20210801', '20210901'),
            self::span('20210901', '20211001'),
            self::span('20211001', '20211101'),
            self::span('20220101', '20230101'),
        ];

        $out = Db::splitAtCutoff($old, '20210915');

        $this->assertSame([], $out['operations']);
        $this->assertSame([$old[3]], $out['tail']);
        $this->assertSame(1, $out['doomed']);
        $this->assertSame(0, $out['added']);

        // The next day: still nothing to do.
        $this->assertSame([], Db::splitAtCutoff($old, '20210916')['operations']);
    }

    /** Applying a split leaves nothing to split with the same cutoff, or the next day's. */
    public function testASplitIsDoneOnce(): void
    {
        $out = Db::splitAtCutoff([self::span('20210101', '20220101')], '20210915');

        $after = [];
        $start = '20210101';

        foreach ($out['operations'][0]['ranges'] as $name => $less_than) {
            $after[] = self::span($start, $less_than, $name);
            $start   = $less_than;
        }

        $this->assertSame([], Db::splitAtCutoff($after, '20210915')['operations']);
        $this->assertSame([], Db::splitAtCutoff($after, '20210916')['operations']);
    }

    /** A cutoff on a January 1st needs no split: the years either side are already apart. */
    public function testACutoffOnANewYearSplitsNothing(): void
    {
        $old = [self::span('20200101', '20210101'), self::span('20210101', '20220101')];

        $out = Db::splitAtCutoff($old, '20210101');

        $this->assertSame([], $out['operations']);
        $this->assertSame([$old[1]], $out['tail']);
        $this->assertSame(1, $out['doomed']);
    }

    /** A cutoff before every partition leaves the tier to the ordinary merge. */
    public function testACutoffBeforeTheDataChangesNothing(): void
    {
        $old = [self::span('20200101', '20210101'), self::span('20210101', '20220101')];

        $this->assertSame(['tail' => $old, 'operations' => [], 'doomed' => 0, 'added' => 0],
            Db::splitAtCutoff($old, '20150601'));
    }
}
