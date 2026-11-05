<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\FunnelStep;
use OWA\Module\Base\Controller\VisualizationFunnel;

/**
 * A funnel step as the funnel counts and draws it: an event, and conditions on
 * what it carries (Classes\FunnelStep).
 *
 * A page is a page_view with a page_path condition, compared the way the row
 * was stored: the typed path is canonicalised on the READ side with the
 * Profile's default page (V2Event::canonicalPath()), so `/store/` matches the
 * stored `/store`. The stored step keeps what the author typed.
 */
final class FunnelEventStepTest extends TestCase
{
    private function predicate( array $step, array $params = array() )
    {
        $controller = new VisualizationFunnel( $params );

        $m = new ReflectionMethod( $controller, 'stepPredicate' );
        $m->setAccessible( true );

        return array( $m->invoke( $controller, $step, 1, VisualizationFunnel::ALIAS ), $controller );
    }

    private static function page( string $path, string $query = '' ): array
    {
        $conditions = array( array( 'property' => 'page_path', 'operator' => 'exact', 'value' => $path ) );

        if ( $query !== '' ) {
            $conditions[] = array( 'property' => 'page_query', 'operator' => 'exact', 'value' => $query );
        }

        return array( 'name' => $path, 'kind' => 'event', 'event' => 'page_view', 'match' => 'all',
            'conditions' => $conditions, 'step_number' => 1 );
    }

    /** A page step is one bound comparison on page_path, on page views only. */
    public function testAPageStepComparesPagePathOnPageViews(): void
    {
        list( $out ) = $this->predicate( self::page( '/thanks' ) );

        $this->assertSame( '( e.event_type = ? AND ( ( e.page_path IS NOT NULL AND e.page_path = ? ) ) )', $out['sql'] );
        $this->assertSame( array( 'page_view', '/thanks' ), $out['params'] );
        $this->assertStringNotContainsString( '/thanks', $out['sql'] );
    }

    /** A trailing slash is collapsed, because the stored path is; the root stays '/'. */
    public function testAPagePathIsCanonicalisedTheWayTheRowWas(): void
    {
        list( $out ) = $this->predicate( self::page( '/store/' ) );
        $this->assertSame( array( 'page_view', '/store' ), $out['params'] );

        list( $root ) = $this->predicate( self::page( '/' ) );
        $this->assertSame( array( 'page_view', '/' ), $root['params'] );
    }

    /** A query string is its own condition, on page_query. */
    public function testAQueryIsMatchedAgainstPageQuery(): void
    {
        list( $out ) = $this->predicate( self::page( '/checkout', 'step=2' ) );

        $this->assertStringContainsString( 'e.page_query = ?', $out['sql'] );
        $this->assertSame( array( 'page_view', '/checkout', 'step=2' ), $out['params'] );
    }

    /**
     * The default page is collapsed with the default_page setting, as the row's
     * was. Set for this request only, and read with no Property, which is the
     * installation's value.
     */
    public function testTheDefaultPageIsCollapsed(): void
    {
        $before = \OWA\Core\CoreAPI::getSetting( 'base', 'default_page' );

        \OWA\Core\CoreAPI::setSetting( 'base', 'default_page', 'index.php' );

        try {
            list( $out ) = $this->predicate( self::page( '/store/index.php' ) );

            $this->assertSame( array( 'page_view', '/store' ), $out['params'],
                'The default page was not collapsed, so this step cannot match the path the row stored.' );

        } finally {
            \OWA\Core\CoreAPI::setSetting( 'base', 'default_page', $before );
        }
    }

    /** A site's own event, and conditions on another event, compile as they say. */
    public function testAnEventStepCompiles(): void
    {
        list( $out ) = $this->predicate( array( 'kind' => 'event', 'event' => 'click', 'match' => 'any',
            'conditions' => array(
                array( 'property' => 'element_id', 'operator' => 'exact', 'value' => 'buy' ),
                array( 'property' => 'element_id', 'operator' => 'exact', 'value' => 'buy-now' ),
            ) ) );

        $this->assertStringContainsString( ' OR ', $out['sql'] );
        $this->assertSame( array( 'click', 'buy', 'buy-now' ), $out['params'] );

        list( $custom ) = $this->predicate( array( 'kind' => 'event', 'event' => 'newsletter_signup',
            'conditions' => array() ) );

        $this->assertSame( '( e.event_type = ? )', $custom['sql'] );
    }

    /**
     * A step that is not an event step -- a goal event step Update079 left
     * because its definition was gone, or a path step -- is REFUSED by number,
     * not drawn as an empty stage.
     */
    public function testAStepThatIsNotAnEventStepIsRefused(): void
    {
        foreach ( array(
            array( 'name' => 'Signup', 'goal_event_definition_id' => '123', 'step_number' => 1 ),
            array( 'name' => '/a', 'path' => '/a', 'step_number' => 1 ),
            array( 'kind' => 'event', 'event' => '' ),
        ) as $step ) {

            list( $out, $controller ) = $this->predicate( $step );

            $this->assertNull( $out );
            $this->assertStringContainsString( 'Step 1 of this funnel has no event to match',
                (string) ( $controller->data['funnel_step_error'] ?? '' ) );
        }
    }

    /** A condition on something the vocabulary does not know is refused by name rather than dropped. */
    public function testAnUnknownPropertyIsRefusedByName(): void
    {
        list( $out, $controller ) = $this->predicate( array( 'kind' => 'event', 'event' => 'page_view',
            'conditions' => array( array( 'property' => 'cd_plan', 'operator' => 'exact', 'value' => 'gold' ) ) ) );

        $this->assertNull( $out );
        $this->assertStringContainsString( 'Step 1 tests cd_plan', (string) ( $controller->data['funnel_step_error'] ?? '' ) );
    }

    /** What a step matches, in words: a page step reads as its page, anything else as its event and conditions. */
    public function testAStepIsSummarisedForTheChart(): void
    {
        $this->assertSame( '/thanks', FunnelStep::summary( self::page( '/thanks' ) ) );
        $this->assertSame( '/checkout?step=2', FunnelStep::summary( self::page( '/checkout', 'step=2' ) ) );
        $this->assertSame( 'newsletter_signup', FunnelStep::summary( array( 'event' => 'newsletter_signup' ) ) );

        $this->assertSame( 'purchase where Revenue greater than 50 or Plan exactly matching gold', FunnelStep::summary(
            array( 'event' => 'purchase', 'match' => 'any', 'conditions' => array(
                array( 'property' => 'revenue', 'operator' => 'gt', 'value' => '50' ),
                array( 'property' => 'cd_plan', 'operator' => 'exact', 'value' => 'gold' ),
            ) ), array( 'cd_plan' => 'Plan' ) ) );

        // A page_view on something besides its path is not a page step.
        $this->assertStringStartsWith( 'page_view where', FunnelStep::summary( array( 'event' => 'page_view',
            'conditions' => array( array( 'property' => 'page_path', 'operator' => 'begins', 'value' => '/blog/' ) ) ) ) );
    }
}
