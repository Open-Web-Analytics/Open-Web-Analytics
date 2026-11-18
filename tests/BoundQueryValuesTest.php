<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Queries that bind their values find the same rows as before, including for a
 * value holding a quote or a question mark: the value is never part of the
 * statement's text.
 */
final class BoundQueryValuesTest extends TestCase
{
    private const JOB = "phpunit-bound-'job?";

    private const OWNER = "o'wner?";

    private const USER = "phpunit-bound-'user?";

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database');
        }

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query(sprintf('DELETE FROM %s WHERE job_name = ?',
            \OWA\Core\CoreAPI::entityFactory('base.job_lock')->getTableName()), [self::JOB]);
        $db->query(sprintf('DELETE FROM %s WHERE user_id = ?',
            \OWA\Core\CoreAPI::entityFactory('base.custom_report_favorite')->getTableName()), [self::USER]);
    }

    public function testAJobLockIsTakenReadExtendedAndReleasedByItsOwner(): void
    {
        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $now = time();

        $this->assertTrue($db->insertJobLock(self::JOB, self::OWNER, $now, $now + 60));
        $this->assertFalse($db->insertJobLock(self::JOB, 'someone else', $now, $now + 60), 'one holder at a time');

        $lock = $db->getJobLock(self::JOB);
        $this->assertSame(self::OWNER, $lock['owner']);
        $this->assertSame((string) ($now + 60), (string) $lock['expires_at']);

        $this->assertTrue($db->refreshJobLock(self::JOB, self::OWNER, $now + 120));
        $this->assertSame((string) ($now + 120), (string) $db->getJobLock(self::JOB)['expires_at']);

        $this->assertTrue($db->deleteJobLock(self::JOB, $now), 'not expired: the statement runs and removes nothing');
        $this->assertNotNull($db->getJobLock(self::JOB));

        $db->releaseJobLock(self::JOB, 'someone else');
        $this->assertNotNull($db->getJobLock(self::JOB), 'only the owner releases');

        $db->releaseJobLock(self::JOB, self::OWNER);
        $this->assertNull($db->getJobLock(self::JOB));
    }

    public function testFavoritesAreFoundForAUserIdWithAQuote(): void
    {
        $this->assertSame([], \OWA\Module\Base\Classes\CustomReportFavorites::idsFor(self::USER));

        \OWA\Module\Base\Classes\CustomReportFavorites::toggle('987654321', self::USER);

        $this->assertTrue(\OWA\Module\Base\Classes\CustomReportFavorites::isFavorite('987654321', self::USER));
        $this->assertSame(['987654321' => true], \OWA\Module\Base\Classes\CustomReportFavorites::idsFor(self::USER));

        \OWA\Module\Base\Classes\CustomReportFavorites::toggle('987654321', self::USER);

        $this->assertFalse(\OWA\Module\Base\Classes\CustomReportFavorites::isFavorite('987654321', self::USER));
    }

    public function testAnUnknownSiteHasNoLastEventAndNoProperty(): void
    {
        $this->assertNull(\OWA\Module\Base\Controller\SitesInvocation::lastEventReceived("no-such-'site?"));
        $this->assertSame('', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite("no-such-'site?"));
    }
}
