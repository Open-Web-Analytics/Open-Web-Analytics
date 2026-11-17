<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * ResultSetManager::getSiteId() answers the Profile the query is narrowed to.
 *
 * It read $params['siteId'], which nothing sets: setSiteId() writes a siteId
 * constraint, so getSiteId() answered nothing after it.
 */
final class ResultSetSiteIdTest extends TestCase
{
    public function testItAnswersTheSiteIdSet(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setSiteId('zz-site-id-probe');

        $this->assertSame('zz-site-id-probe', $rsm->getSiteId());
    }

    /** A siteId constraint the request carried narrows the query the same way. */
    public function testItAnswersASiteIdConstraint(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setConstraint('siteId', 'zz-constraint-probe');

        $this->assertSame('zz-constraint-probe', $rsm->getSiteId());
    }

    public function testNothingWithoutOne(): void
    {
        $this->assertNull((new \OWA\Module\Base\Classes\ResultSetManager)->getSiteId());
    }
}
