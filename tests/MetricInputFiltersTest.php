<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\Currency;

/**
 * Core\Metric::getSelect() runs two filters on a metric's input -- the value
 * inside SUM or COUNT -- in this order:
 *
 *   currency_amount_sql            for a metric whose data type is currency
 *   metric_input_sql.<metricName>  for every metric
 *
 * Both are given Metric::inputContext(). Base's value for the first is the
 * amount as reported (Currency::asReportedSql()).
 */
final class MetricInputFiltersTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array}> filter name, value given, context, as heard */
    public static $heard = [];

    /** @var array<string, string> filter name => the value its listener returns instead */
    public static $replace = [];

    private const FILTERS = [
        'currency_amount_sql',
        'metric_input_sql.transactionRevenue',
        'metric_input_sql.taxRevenue',
        'metric_input_sql.pageViews',
        'metric_input_sql.eventCount',
    ];

    protected function setUp(): void
    {
        self::$heard   = [];
        self::$replace = [];

        foreach ( self::FILTERS as $i => $filter ) {

            \OWA\Core\CoreAPI::registerFilter( $filter, self::class . '::on' . $i, 99 );
        }
    }

    protected function tearDown(): void
    {
        self::$heard   = [];
        self::$replace = [];
    }

    /** One callback per filter (on<index>), recording what it was given. */
    public static function __callStatic( $name, $args )
    {
        $filter = self::FILTERS[ (int) substr( $name, 2 ) ];
        $value  = $args[0];

        self::$heard[] = [ $filter, $value, $args[1] ?? [] ];

        return self::$replace[ $filter ] ?? $value;
    }

    private function metric( string $name ): \OWA\Core\Metric
    {
        return \OWA\Core\CoreAPI::metricFactory( $name );
    }

    /** @return list<string> the filters heard, in order */
    private function names(): array
    {
        return array_column( self::$heard, 0 );
    }

    public function testACurrencyMetricRunsBothFiltersInOrder(): void
    {
        $this->metric( 'transactionRevenue' )->getSelect();

        $this->assertSame( [ 'currency_amount_sql', 'metric_input_sql.transactionRevenue' ], $this->names() );

        $this->assertSame(
            Currency::asReportedSql( 'event.revenue', 'event.currency', Currency::ofProperty( '' ) ),
            self::$heard[0][1], 'Base gives the amount as reported' );

        $this->assertSame( self::$heard[0][1], self::$heard[1][1],
            'the per-metric filter is given what the currency filter returned' );
    }

    public function testTheMetricFilterIsGivenTheCurrencyFiltersAnswer(): void
    {
        self::$replace['currency_amount_sql'] = 'event.revenue * 2';

        $this->metric( 'transactionRevenue' )->getSelect();

        $this->assertSame( 'event.revenue * 2', self::$heard[1][1] );
    }

    public function testAMetricThatIsNotMoneyRunsOnlyItsOwnFilter(): void
    {
        $this->metric( 'pageViews' )->getSelect();
        $this->metric( 'eventCount' )->getSelect();

        $this->assertSame( [ 'metric_input_sql.pageViews', 'metric_input_sql.eventCount' ], $this->names() );

        $this->assertSame( '1', self::$heard[0][1], 'a conditioned count sums 1 for each row' );
        $this->assertSame( 'event.id', self::$heard[1][1] );
    }

    public function testEachMetricRunsOnlyItsOwnMetricFilter(): void
    {
        $this->metric( 'taxRevenue' )->getSelect();

        $this->assertSame( [ 'currency_amount_sql', 'metric_input_sql.taxRevenue' ], $this->names() );
    }

    public function testTheContext(): void
    {
        $metric = $this->metric( 'transactionRevenue' );
        $metric->getSelect();

        $context = self::$heard[0][2];

        $this->assertSame( $context, self::$heard[1][2], 'both filters are given the same context' );

        $this->assertSame( 'transactionRevenue', $context['definition']['name'] );
        $this->assertSame( 'currency', $context['definition']['data_type'] );
        $this->assertSame( 'revenue', $context['definition']['column'] );
        $this->assertSame( [ 'column' => 'event_type', 'value' => 'purchase' ], $context['definition']['condition'] );

        $this->assertSame( 'transactionRevenue', $context['name'] );
        $this->assertSame( 'currency', $context['data_type'] );
        $this->assertSame( 'event', $context['table_alias'] );
        $this->assertSame( 'event.revenue', $context['column'] );
        $this->assertSame( 'event.currency', $context['currency_column'] );
        $this->assertSame( 'event.yyyymmdd', $context['date_column'] );
        $this->assertSame( '', $context['property_id'] );
        $this->assertSame( Currency::ofProperty( '' ), $context['property_currency'] );
    }

    public function testAListenerRewritingTheInputChangesTheSql(): void
    {
        $before = $this->metric( 'transactionRevenue' )->getSelect()[0];

        self::$replace['metric_input_sql.transactionRevenue'] = 'event.revenue * 3';

        $after = $this->metric( 'transactionRevenue' )->getSelect()[0];

        $this->assertNotSame( $before, $after );
        $this->assertSame(
            "COALESCE(sum(CASE WHEN event.event_type = 'purchase' THEN COALESCE(event.revenue * 3, 0) ELSE 0 END), 0)",
            $after, 'the aggregate, the condition and the COALESCE stay Base\'s' );

        self::$replace['metric_input_sql.pageViews'] = 'event.engagement_msec';

        $this->assertSame(
            "COALESCE(sum(CASE WHEN event.event_type = 'page_view' THEN event.engagement_msec ELSE 0 END), 0)",
            $this->metric( 'pageViews' )->getSelect()[0] );
    }

    public function testWithNoListenerAnswerTheSqlIsBases(): void
    {
        $this->assertSame(
            "COALESCE(sum(CASE WHEN event.event_type = 'page_view' THEN 1 ELSE 0 END), 0)",
            $this->metric( 'pageViews' )->getSelect()[0] );

        $this->assertSame(
            sprintf( "COALESCE(sum(CASE WHEN event.event_type = 'purchase' THEN COALESCE(%s, 0) ELSE 0 END), 0)",
                Currency::asReportedSql( 'event.revenue', 'event.currency', Currency::ofProperty( '' ) ) ),
            $this->metric( 'transactionRevenue' )->getSelect()[0] );
    }

    /** The factor each row is multiplied by, for each Property currency. */
    public function testTheAsReportedFactors(): void
    {
        $usd = Currency::asReportedSql( 'e.revenue', 'e.currency', 'USD' );

        $this->assertStringStartsWith( "round(e.revenue * CASE WHEN e.currency IS NULL OR e.currency = '' THEN 1 ", $usd,
            'a row with no currency is in the Property\'s' );
        $this->assertMatchesRegularExpression( "/WHEN e\\.currency IN \\([^)]*'JPY'[^)]*\\) THEN 100 /", $usd );
        $this->assertMatchesRegularExpression( "/WHEN e\\.currency IN \\([^)]*'KWD'[^)]*\\) THEN 0\\.1 /", $usd );
        $this->assertMatchesRegularExpression( "/WHEN e\\.currency IN \\([^)]*'CLF'[^)]*\\) THEN 0\\.01 /", $usd );
        $this->assertStringEndsWith( 'ELSE 1 END)', $usd, 'EUR and every other two-place code' );

        $jpy = Currency::asReportedSql( 'e.revenue', 'e.currency', 'JPY' );

        $this->assertMatchesRegularExpression( "/WHEN e\\.currency IN \\([^)]*'JPY'[^)]*\\) THEN 1 /", $jpy );
        $this->assertMatchesRegularExpression( "/WHEN e\\.currency IN \\([^)]*'KWD'[^)]*\\) THEN 0\\.001 /", $jpy );
        $this->assertStringEndsWith( 'ELSE 0.01 END)', $jpy );

        $kwd = Currency::asReportedSql( 'e.revenue', 'e.currency', 'KWD' );

        $this->assertMatchesRegularExpression( "/WHEN e\\.currency IN \\([^)]*'JPY'[^)]*\\) THEN 1000 /", $kwd );
        $this->assertStringEndsWith( 'ELSE 10 END)', $kwd );
    }
}
