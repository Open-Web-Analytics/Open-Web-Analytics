<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * What a beacon's values are stored as, through the real pipeline: a value
 * its type refuses is absent, an integer its column cannot hold is NULL, and
 * the beacon's row is stored either way.
 *
 * Needs the database (IngestionTestCase).
 */
final class IngestValueHygieneTest extends IngestionTestCase
{
    /** @var string */
    private $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);
    }

    /**
     * Fire one event and read back its rows, keyed by event_type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fire(string $type, array $props, ?string $visitor = null): array
    {
        $visitor = $visitor ?? $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent($type, $props + [
            'site_id'            => $this->site,
            'visitor_id'         => $visitor,
            'session_id'         => $session,
            'page_url'           => 'https://owa-test-site/v2/hygiene',
            'page_location'      => 'https://owa-test-site/v2/hygiene',
            'sts'                => time(),
            'num_prior_sessions' => 1,
        ]);

        $rows = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf(
            "SELECT * FROM owa_event_raw WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $this->site, (int) $visitor, (int) $session)) as $row) {

            $row = (array) $row;
            $rows[$row['event_type']] = $row;
            $this->trackForCleanup('base.event_raw', (string) $row['id'], 'id');
        }

        return $rows;
    }

    /** "49,17" is not an amount: the purchase is stored, its revenue empty, not 49 less tax. */
    public function testAnAmountThatIsNotANumberIsAbsent(): void
    {
        $row = $this->fire('ecommerce.transaction', [
            'ct_order_id' => 'hygiene-comma-' . $this->uniqueGuid(),
            'ct_total'    => '49,17',
            'ct_tax'      => '8.20',
            'ct_shipping' => '4.90',
            'currency'    => 'EUR',
        ])['purchase'] ?? null;

        $this->assertNotNull($row, 'the purchase is stored');
        $this->assertNull($row['revenue']);
        $this->assertSame('820', (string) $row['tax']);
    }

    /** A value that is not a number gives way to the order total. */
    public function testAValueThatIsNotANumberFallsBackToTheTotal(): void
    {
        $row = $this->fire('ecommerce.transaction', [
            'ct_order_id' => 'hygiene-value-' . $this->uniqueGuid(),
            'ct_value'    => 'abc',
            'ct_total'    => '30.00',
            'currency'    => 'USD',
        ])['purchase'] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('3000', (string) $row['revenue']);
    }

    public function testAScrollDepthThatIsNotANumberIsNull(): void
    {
        $row = $this->fire('scroll', ['scroll_depth' => 'abc'])['scroll'] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row['scroll_depth']);
    }

    /** An integer its column cannot hold is NULL, and the rest of the beacon is stored. */
    public function testAnIntegerItsColumnCannotHoldStoresTheRowWithNull(): void
    {
        $rows = $this->fire('click', [
            'engagement_msec' => '99999999999',
            'click_x'         => (string) (2 ** 31),
            'click_y'         => '120',
            'target_url'      => 'https://owa-test-site/v2/next',
        ]);

        $this->assertArrayHasKey('click', $rows);
        $this->assertNull($rows['click']['engagement_msec']);
        $this->assertNull($rows['click']['click_x']);
        $this->assertSame('120', (string) $rows['click']['click_y']);

        $scroll = $this->fire('scroll', ['scroll_depth' => '200'])['scroll'] ?? null;

        $this->assertNotNull($scroll);
        $this->assertNull($scroll['scroll_depth']);
    }

    /** p_t[]=x: an array for a one-value property is absent, and the row is stored. */
    public function testAnArrayForAOneValuePropertyIsAbsent(): void
    {
        $row = $this->fire('page_view', ['page_title' => ['x'], 'target_url' => ['y']])['page_view'] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row['page_title']);
        $this->assertNull($row['target_url']);
    }
}
