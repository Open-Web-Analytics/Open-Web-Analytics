<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The funnel builder's server side: a funnel's own filter, and the preview
 * that counts steps not yet saved.
 */
final class FunnelBuilderTest extends TestCase
{
    private function call( object $o, string $method, array $args = array() )
    {
        $m = new ReflectionMethod( $o, $method );
        $m->setAccessible( true );

        return $m->invokeArgs( $o, $args );
    }

    private function setPrivate( object $o, string $class, string $name, $value ): void
    {
        $p = new ReflectionProperty( $class, $name );
        $p->setAccessible( true );
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
                array( 'constraints' => $reader, 'period' => 'last_thirty_days' ) );

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
        $funnel = new \OWA\Module\Base\Controller\VisualizationFunnel( array( 'constraints' => 'pagePath=@/' ) );

        // What pre() does on a request with no period: the default one.
        $funnel->setTimePeriod( \OWA\Core\CoreAPI::makeTimePeriod( null, array() ) );

        $bounds = $this->call( $funnel, 'segment' )->bounds();

        $this->assertNotNull( $bounds, 'the segment has no period' );
        $this->assertSame( $funnel->getPeriod()->startDate->getYyyymmdd(), $bounds['start'] );
        $this->assertSame( $funnel->getPeriod()->endDate->getYyyymmdd(), $bounds['end'] );
    }

    /**
     * The preview's funnel is the steps and filter it was sent, made into a
     * definition the way a save makes one: blank rows dropped, a step named
     * for its page, one of path or goal event kept, and no more than a funnel
     * may have.
     */
    public function testThePreviewCountsTheStepsItWasSent(): void
    {
        $sent = array(
            array( 'kind' => 'path', 'path' => '/blog/', 'name' => '' ),
            array( 'kind' => 'path', 'path' => '', 'name' => 'Left blank' ),
            array( 'kind' => 'path', 'path' => '/pricing', 'name' => 'Pricing', 'goal_event_id' => '9' ),
        );

        $preview = new \OWA\Module\Base\Controller\VisualizationPreview( array(
            'steps'        => json_encode( $sent ),
            'funnelFilter' => 'pagePath=@/blog/',
        ) );

        $visualization = $this->call( $preview, 'visualization' );

        $this->assertSame( array(
            array( 'name' => '/blog/', 'path' => '/blog/', 'step_number' => 1 ),
            array( 'name' => 'Pricing', 'path' => '/pricing', 'step_number' => 2 ),
        ), $visualization['definition']['steps'] );

        $this->assertSame( 'pagePath=@/blog/', $visualization['definition']['constraints'] );

        $many = new \OWA\Module\Base\Controller\VisualizationPreview( array(
            'steps' => json_encode( array_fill( 0, 15, array( 'kind' => 'path', 'path' => '/x' ) ) ),
        ) );

        $this->assertCount( \OWA\Module\Base\Controller\VisualizationSave::MAX_STEPS,
            $this->call( $many, 'visualization' )['definition']['steps'] );
    }

    /** Anything but a list of steps is no steps, not an error. */
    public function testThePreviewIgnoresStepsThatAreNotAList(): void
    {
        foreach ( array( '', 'not json', '"a string"', '{"kind":"path"}', '[1,2]' ) as $steps ) {

            $preview = new \OWA\Module\Base\Controller\VisualizationPreview( array( 'steps' => $steps ) );

            $this->assertSame( array(), $this->call( $preview, 'visualization' )['definition']['steps'], $steps );
        }
    }

    /** A funnel says what its own filter is, in words. */
    public function testASavedFilterReadsAsWords(): void
    {
        $words = \OWA\Module\Base\Classes\CustomReports::constraintsInWords(
            'pagePath=@/blog/,sessionCampaign!=(not set),pagePath!=/x' );

        $this->assertSame( array(
            'Page Path contains /blog/',
            $words[1],
            'Page Path is not /x',
        ), $words );

        $this->assertStringEndsWith( ' is set', $words[1] );
        $this->assertSame( array(), \OWA\Module\Base\Classes\CustomReports::constraintsInWords( '' ) );
    }
}
