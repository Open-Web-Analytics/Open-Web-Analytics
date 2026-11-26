<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The funnel builder's server side: a funnel's own filter, and the preview
 * that counts steps not yet saved.
 */
final class FunnelBuilderTest extends TestCase
{
    private function call( object $o, string $method, array $args = [] )
    {
        $m = new ReflectionMethod( $o, $method );

        return $m->invokeArgs( $o, $args );
    }

    private function setPrivate( object $o, string $class, string $name, $value ): void
    {
        $p = new ReflectionProperty( $class, $name );
        $p->setValue( $o, $value );
    }

    /**
     * The funnel's own filter narrows it under the reader's: both go into the
     * segment, and either alone is just itself.
     */
    public function testTheSavedFilterAndTheReadersAreBothApplied(): void
    {
        $segment = function ( string $saved, string $reader ) {

            $funnel = new \OWA\Module\Base\Controller\VisualizationFunnel(
                [ 'constraints' => $reader, 'period' => 'last_thirty_days' ] );

            $this->setPrivate( $funnel, \OWA\Module\Base\Controller\VisualizationFunnel::class,
                'savedConstraints', $saved );

            return $this->call( $funnel, 'segment' )->getConstraints();
        };

        $this->assertSame( 'pagePath=@/blog/,sessionMedium==organic',
            $segment( 'pagePath=@/blog/', 'sessionMedium==organic' ) );
        $this->assertSame( 'pagePath=@/blog/', $segment( 'pagePath=@/blog/', '' ) );
        $this->assertSame( 'sessionMedium==organic', $segment( '', 'sessionMedium==organic' ) );
        $this->assertSame( '', $segment( '', '' ) );
    }

    /**
     * A funnel opened with no period on its URL -- where a save lands -- is
     * counted over the report's default period, which its header names. The
     * segment was given no period at all, so a filtered funnel failed with a
     * 500 and an unfiltered one counted all time.
     */
    public function testWithNoPeriodTheSegmentUsesTheReportsOwn(): void
    {
        $funnel = new \OWA\Module\Base\Controller\VisualizationFunnel( [ 'constraints' => 'pagePath=@/' ] );

        // What pre() does on a request with no period: the default one.
        $funnel->period = \OWA\Core\CoreAPI::makeTimePeriod( null, [] );

        $bounds = $this->call( $funnel, 'segment' )->bounds();

        $this->assertNotNull( $bounds, 'the segment has no period' );
        $this->assertSame( $funnel->getPeriod()->startDate->getYyyymmdd(), $bounds['start'] );
        $this->assertSame( $funnel->getPeriod()->endDate->getYyyymmdd(), $bounds['end'] );
    }

    private static function page( string $path, string $name = '' ): array
    {
        return [ 'name' => $name, 'event' => 'page_view', 'match' => 'all', 'conditions' => [
            [ 'property' => 'page_path', 'operator' => 'exact', 'value' => $path ] ] ];
    }

    /**
     * The preview's funnel is the steps and filter it was sent, made into a
     * definition the way a save makes one: blank rows dropped, a step named
     * for its page or its event, a row of an old kind not counted, and no more
     * than a funnel may have.
     */
    public function testThePreviewCountsTheStepsItWasSent(): void
    {
        $sent = [
            self::page( '/blog/' ),
            self::page( '', 'Left blank' ),
            [ 'kind' => 'path', 'path' => '/old', 'name' => 'A path step' ],
            self::page( '/pricing', 'Pricing' ),
            [ 'name' => '', 'event' => 'newsletter_signup', 'match' => 'any', 'conditions' => [] ],
        ];

        $preview = new \OWA\Module\Base\Controller\VisualizationPreview( [
            'steps'        => json_encode( $sent ),
            'funnelFilter' => 'pagePath=@/blog/',
        ] );

        $visualization = $this->call( $preview, 'visualization' );

        $this->assertSame( [
            [ 'name' => '/blog/', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all',
                'conditions' => [ [ 'property' => 'page_path', 'operator' => 'exact', 'value' => '/blog/' ] ],
                'step_number' => 1 ],
            [ 'name' => 'Pricing', 'kind' => 'event', 'event' => 'page_view', 'match' => 'all',
                'conditions' => [ [ 'property' => 'page_path', 'operator' => 'exact', 'value' => '/pricing' ] ],
                'step_number' => 2 ],
            [ 'name' => 'newsletter_signup', 'kind' => 'event', 'event' => 'newsletter_signup', 'match' => 'any',
                'conditions' => [], 'step_number' => 3 ],
        ], $visualization['definition']['steps'] );

        $this->assertSame( 'pagePath=@/blog/', $visualization['definition']['constraints'] );

        $many = new \OWA\Module\Base\Controller\VisualizationPreview( [
            'steps' => json_encode( array_fill( 0, 15, self::page( '/x' ) ) ),
        ] );

        $this->assertCount( \OWA\Module\Base\Controller\VisualizationSave::MAX_STEPS,
            $this->call( $many, 'visualization' )['definition']['steps'] );
    }

    /**
     * The preview runs the caller's own steps against the cube, so it asks for
     * what saving them does; a step with more conditions than a step takes is
     * refused before anything is counted.
     */
    public function testThePreviewAsksForEditReportsAndRefusesTooManyConditions(): void
    {
        $this->assertSame( 'edit_reports',
            ( new \OWA\Module\Base\Controller\VisualizationPreview( [] ) )->getRequiredCapability() );

        $conditions = array_fill( 0, \OWA\Module\Base\Classes\FunnelStep::MAX_CONDITIONS + 1,
            [ 'property' => 'page_path', 'operator' => 'exact', 'value' => '/x' ] );

        $preview = new \OWA\Module\Base\Controller\VisualizationPreview( [
            'steps' => json_encode( [ [ 'event' => 'page_view', 'match' => 'all', 'conditions' => $conditions ] ] ),
        ] );
        $preview->action();

        $this->assertSame( 'Step 1: a step takes at most 10 conditions.', $preview->data['ajax_error'] ?? null );
        $this->assertArrayNotHasKey( 'funnel', $preview->data, 'nothing was counted' );
    }

    /** Anything but a list of steps is no steps, not an error. */
    public function testThePreviewIgnoresStepsThatAreNotAList(): void
    {
        foreach ( [ '', 'not json', '"a string"', '{"kind":"path"}', '[1,2]' ] as $steps ) {

            $preview = new \OWA\Module\Base\Controller\VisualizationPreview( [ 'steps' => $steps ] );

            $this->assertSame( [], $this->call( $preview, 'visualization' )['definition']['steps'], $steps );
        }
    }

    /** A funnel says what its own filter is, in words. */
    public function testASavedFilterReadsAsWords(): void
    {
        $words = \OWA\Module\Base\Classes\CustomReports::constraintsInWords(
            'pagePath=@/blog/,sessionCampaign!=(not set),pagePath!=/x' );

        $this->assertSame( [
            'Page Path contains /blog/',
            $words[1],
            'Page Path is not /x',
        ], $words );

        $this->assertStringEndsWith( ' has a value', $words[1] );
        $this->assertSame( [], \OWA\Module\Base\Classes\CustomReports::constraintsInWords( '' ) );
    }

    /** Every operator reads as its pill does, translated, and a value reads as it was typed. */
    public function testEveryOperatorReadsAsItsPillAndAValueAsTyped(): void
    {
        $this->assertSame( [
            'Page Path does not contain /blog/',
            'Page Path matches ^/a',
            'Page Path is /a,b',
            'Page Path at least 5%',
        ], \OWA\Module\Base\Classes\CustomReports::constraintsInWords(
            'pagePath!@/blog/,pagePath=~^/a,pagePath==/a%2Cb,pagePath>=5%25' ) );

        \OWA\Core\Strings::useTranslations( [ 'does not contain' => 'ne contient pas' ] );

        try {
            $this->assertSame( [ 'Page Path ne contient pas /x' ],
                \OWA\Module\Base\Classes\CustomReports::constraintsInWords( 'pagePath!@/x' ) );
        } finally {
            \OWA\Core\Strings::useTranslations( null );
        }

        $operators = array_keys( \OWA\Module\Base\Classes\ResultSetManager::CONSTRAINT_OPERATORS );
        $this->assertSame( [], array_diff( $operators, array_keys( \OWA\Module\Base\Classes\CustomReports::CONSTRAINT_WORDS ) ),
            'an operator the parser reads with no words' );
    }
}
