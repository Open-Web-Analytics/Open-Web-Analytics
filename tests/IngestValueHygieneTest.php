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

    /**
     * A form's destination is stored as page_location is: without the
     * query_string_filters parameters, OWA's control parameters and the
     * fragment.
     */
    public function testAFormDestinationIsFilteredAsThePageLocationIs(): void
    {
        $before = \OWA\Core\CoreAPI::getSetting('base', 'query_string_filters');
        \OWA\Core\CoreAPI::setSetting('base', 'query_string_filters', 'email');

        try {
            $row = $this->fire('form_submit', [
                'form_id'          => 'signup',
                'form_destination' => 'https://owa-test-site/v2/send?email=a%40b.test&owa_state=v.1.s.2&keep=1#done',
            ])['form_submit'] ?? null;
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'query_string_filters', $before);
        }

        $this->assertNotNull($row);
        $params = (array) json_decode((string) $row['params'], true);
        $this->assertSame('https://owa-test-site/v2/send?keep=1', $params['form_destination'] ?? null);
    }

    /** A page URL's fragment is never stored, and the page path is the same with or without one. */
    public function testAFragmentIsNeverStored(): void
    {
        $rows = $this->fire('page_view', [
            'page_url'      => 'https://owa-test-site/app?x=1#/route/a',
            'page_location' => 'https://owa-test-site/app?x=1#/route/a',
        ]);

        $this->assertArrayHasKey('page_view', $rows);
        $this->assertSame('https://owa-test-site/app?x=1', $rows['page_view']['page_location']);
        $this->assertSame('/app', $rows['page_view']['page_path']);
    }

    /** An order id sent as the text undefined is absent, so one such purchase does not block the next. */
    public function testPurchasesWithAnUndefinedOrderIdAreAllKept(): void
    {
        $visitor = $this->uniqueGuid();

        foreach (['undefined', 'null'] as $sent) {
            $row = $this->fire('ecommerce.transaction', [
                'ct_order_id' => $sent,
                'ct_total'    => '10',
                'currency'    => 'USD',
            ], $visitor)['purchase'] ?? null;

            $this->assertNotNull($row, "$sent: the purchase is stored");
            $this->assertNull($row['transaction_id'], $sent);
        }

        $again = $this->fire('ecommerce.transaction', ['ct_order_id' => 'undefined', 'ct_total' => '10', 'currency' => 'USD'], $visitor);
        $this->assertArrayHasKey('purchase', $again, 'not a duplicate of the first');
    }

    /** AB12 and ab12 are two orders, though the column compares case-blind. */
    public function testOrderIdsThatDifferInCaseAreTwoOrders(): void
    {
        $visitor = $this->uniqueGuid();
        $order   = 'Hygiene-AB12-' . $visitor;

        foreach ([$order, strtolower($order)] as $id) {
            $row = $this->fire('ecommerce.transaction', ['ct_order_id' => $id, 'ct_total' => '10', 'currency' => 'USD'], $visitor)['purchase'] ?? null;

            $this->assertNotNull($row, "$id is stored");
            $this->assertSame($id, $row['transaction_id']);
        }

        $this->assertArrayNotHasKey('purchase',
            $this->fire('ecommerce.transaction', ['ct_order_id' => $order, 'ct_total' => '10', 'currency' => 'USD'], $visitor),
            'the same id again is a duplicate');
    }

    /** setCurrency() is on every event the page sends; a page view stores no currency. */
    public function testAPageViewStoresNoCurrency(): void
    {
        $row = $this->fire('page_view', ['currency' => 'EUR'])['page_view'] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row['currency']);
    }

    /** A refund that names no currency is in its purchase's, not the Property's. */
    public function testARefundTakesItsPurchasesCurrency(): void
    {
        $visitor = $this->uniqueGuid();
        $order   = 'hygiene-refund-' . $visitor;

        $this->fire('ecommerce.transaction', ['ct_order_id' => $order, 'ct_value' => '20', 'currency' => 'JPY'], $visitor);

        $row = $this->fire('refund', ['ct_order_id' => $order, 'ct_value' => '5'], $visitor)['refund'] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('JPY', $row['currency']);
        $this->assertSame('5', (string) $row['revenue'], 'converted by the purchase\'s currency: yen have no minor unit');

        $whole = $this->fire('refund', ['ct_order_id' => strtoupper($order)], $visitor)['refund'] ?? null;

        $this->assertNotNull($whole);
        $this->assertNull($whole['revenue'], 'a case variant of the id names no stored purchase');
    }

    /** A click on a mailto: link is stored, with no target. */
    public function testAClickOnANonHttpLinkHasNoTarget(): void
    {
        foreach (['mailto:someone@example.test', 'javascript:void(0)', 'tel:+15550100'] as $target) {
            $row = $this->fire('click', ['target_url' => $target])['click'] ?? null;

            $this->assertNotNull($row, "$target: the click is stored");
            $this->assertNull($row['target_url'], $target);
            $this->assertNull($row['target_host'], $target);
        }
    }

    /** A current beacon's download path is stored decoded once. */
    public function testADownloadsPathIsStoredDecoded(): void
    {
        $row = $this->fire('file_download', [
            'beacon_version' => '2',
            'target_url'     => 'https://owa-test-site/docs/caf%C3%A9.pdf',
            'file_name'      => '/docs/caf%C3%A9.pdf',
            'file_extension' => 'pdf',
        ])['file_download'] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('/docs/café.pdf', $row['file_name']);
    }

    /** The language is the header's first language as ll-RR. */
    public function testTheLanguageIsTheFirstTag(): void
    {
        $rc     = \OWA\Core\CoreAPI::requestContainerSingleton();
        $before = $rc->server['HTTP_ACCEPT_LANGUAGE'] ?? null;

        try {
            foreach (['de,en-US;q=0.7' => 'de', 'zh-Hant-TW,zh;q=0.8' => 'zh-TW', 'en-us' => 'en-US'] as $header => $stored) {
                $rc->server['HTTP_ACCEPT_LANGUAGE'] = $header;

                $row = $this->fire('page_view', [])['page_view'] ?? null;

                $this->assertNotNull($row, $header);
                $this->assertSame($stored, $row['language'], $header);
            }
        } finally {
            if ($before === null) {
                unset($rc->server['HTTP_ACCEPT_LANGUAGE']);
            } else {
                $rc->server['HTTP_ACCEPT_LANGUAGE'] = $before;
            }
        }
    }

    /** A custom event property named as one of OWA's params is not stored. */
    public function testACustomPropertyCannotTakeOneOfOwasParams(): void
    {
        $row = $this->fire('form_submit', [
            'form_id'    => 'signup',
            'eps_form_id' => 'site-value',
            'eps_plan'   => 'pro',
        ])['form_submit'] ?? null;

        $this->assertNotNull($row);
        $params = (array) json_decode((string) $row['params'], true);
        $this->assertSame('signup', $params['form_id'] ?? null);
        $this->assertSame('pro', $params['plan'] ?? null);
    }
}
