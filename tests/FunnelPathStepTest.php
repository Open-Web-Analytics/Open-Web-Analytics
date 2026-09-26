<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Controller\VisualizationFunnel;

/**
 * "this page", as a condition on the cube.
 *
 * A funnel step's stored path is what the author typed, and it used to be
 * compared to owa_document.uri -- which was the path with `?query` appended when
 * there was one. The cube splits those: page_path never contains a `?`, and
 * page_query holds the rest. It is also CANONICALISED, collapsing the Profile's
 * default page and the trailing slash.
 *
 * So the same stored string means something different against the new column,
 * and both differences are corrected on the read side: rewriting the stored step
 * would change what the author asked for.
 */
final class FunnelPathStepTest extends TestCase
{
    private function predicate( $path, array $params = array() )
    {
        $controller = new VisualizationFunnel( $params );

        $m = new ReflectionMethod( $controller, 'pathPredicate' );
        $m->setAccessible( true );

        return $m->invoke( $controller, $path, VisualizationFunnel::ALIAS );
    }

    /** A plain path is one bound comparison on page_path. */
    public function testAPlainPathComparesPagePath(): void
    {
        $out = $this->predicate( '/thanks' );

        $this->assertSame( 'e.page_path = ?', $out['sql'] );
        $this->assertSame( array( '/thanks' ), $out['params'] );

        // Author input reaching a query is bound, never inlined.
        $this->assertStringNotContainsString( '/thanks', $out['sql'] );
    }

    /**
     * A TRAILING SLASH IS COLLAPSED, because the stored path is.
     *
     * V2Event::canonicalPath strips it, so a step typed `/store/` would never
     * equal the stored `/store` -- a step that silently counts nobody, which on a
     * funnel reads as a conversion problem rather than a bug.
     */
    public function testATrailingSlashIsCollapsedTheWayTheRowWas(): void
    {
        $this->assertSame( array( '/store' ), $this->predicate( '/store/' )['params'] );
    }

    /** The root is '/' and stays '/': stripping it would leave the empty string. */
    public function testTheRootIsLeftAlone(): void
    {
        $this->assertSame( array( '/' ), $this->predicate( '/' )['params'] );
    }

    /**
     * A `?` IN THE TYPED PATH IS SPLIT ACROSS THE TWO COLUMNS.
     *
     * v1 compared the whole uri, so `/checkout?step=2` was a legitimate step and
     * the builder accepted it. On the cube no page_path contains a `?`, so such a
     * step would match nothing at all, for ever, without saying so. Neither
     * install here has one -- measured -- but the value survives in any
     * definition that does.
     */
    public function testAQueryStringIsMatchedAgainstPageQuery(): void
    {
        $out = $this->predicate( '/checkout?step=2' );

        $this->assertSame( '( e.page_path = ? AND e.page_query = ? )', $out['sql'] );
        $this->assertSame( array( '/checkout', 'step=2' ), $out['params'] );
    }

    /** A trailing `?` with nothing after it is no query at all. */
    public function testAnEmptyQueryIsNotAConditionOnPageQuery(): void
    {
        $out = $this->predicate( '/checkout?' );

        $this->assertSame( 'e.page_path = ?', $out['sql'] );
        $this->assertSame( array( '/checkout' ), $out['params'] );
    }

    /**
     * THE DEFAULT PAGE IS COLLAPSED WITH THE PROFILE'S OWN SETTING.
     *
     * Same function and same setting the row was written with, read for the
     * Profile the funnel is drawn for -- so two Profiles with different default
     * pages each get their own answer, rather than one global guess.
     */
    public function testTheProfilesDefaultPageIsCollapsed(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the default page is a per-Profile setting' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id' );

        $siteId = '';

        foreach ( (array) $db->getAllRows() as $row ) {
            $siteId = (string) $row['site_id'];
            break;
        }

        if ( $siteId === '' ) {
            $this->markTestSkipped( 'needs a Profile' );
        }

        $before = \OWA\Core\CoreAPI::getSetting( 'base', 'default_page', 'profile', $siteId );

        \OWA\Core\CoreAPI::setSetting( 'base', 'default_page', 'index.php', 'profile', $siteId );

        try {

            $out = $this->predicate( '/store/index.php', array( 'siteId' => $siteId ) );

            $this->assertSame( array( '/store' ), $out['params'],
                'The default page was not collapsed, so this step cannot match the '
                . 'path the row actually stored.' );

        } finally {

            \OWA\Core\CoreAPI::setSetting( 'base', 'default_page',
                (string) $before, 'profile', $siteId );
        }
    }
}
