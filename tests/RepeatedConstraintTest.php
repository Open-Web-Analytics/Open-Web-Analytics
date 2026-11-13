<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A dimension filtered twice in one request is filtered twice.
 *
 * Constraints were kept by name, so in `pagePath=@/blog,pagePath!@/blog/tag`
 * the second replaced the first: the query ran with only "not a tag page" and
 * answered for the whole site, with no error. Filter pills add a second filter
 * on a dimension freely, a saved report keeps both, and a widget's filter is
 * joined to its report's.
 */
final class RepeatedConstraintTest extends TestCase
{
    private function constraints( string $string ): array
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setConstraints( $rsm->constraintsStringToArray( $string ) );

        return array( $rsm, array_values( (array) $rsm->getConstraints() ) );
    }

    public function testBothFiltersOnOneDimensionAreKept(): void
    {
        list( , $all ) = $this->constraints( 'pagePath=@/blog,pagePath!@/blog/tag' );

        $this->assertSame(
            array( array( 'pagePath', '=@', '/blog' ), array( 'pagePath', '!@', '/blog/tag' ) ),
            array_map( fn ( $c ) => array( $c['name'], $c['operator'], $c['value'] ), $all )
        );
    }

    public function testTheFirstIsTheOneFoundByName(): void
    {
        list( $rsm ) = $this->constraints( 'pagePath=@/blog,pagePath!@/blog/tag' );

        $this->assertSame( '/blog', $rsm->getConstraint( 'pagePath' )['value'] );
    }

    public function testOtherDimensionsAreUnaffected(): void
    {
        list( , $all ) = $this->constraints( 'pagePath=@/blog,sessionChannel==Direct,pagePath!@/blog/tag' );

        $this->assertSame( array( 'pagePath', 'sessionChannel', 'pagePath' ), array_column( $all, 'name' ) );
    }

    /**
     * Setting a name outside a request list still replaces it: the Profile a
     * query is narrowed to is set again, after the request's constraints, by
     * setSiteId().
     */
    public function testSettingANameAgainStillReplacesIt(): void
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;
        $rsm->setConstraint( 'siteId', 'a' );
        $rsm->setConstraint( 'siteId', 'b' );

        $this->assertCount( 1, $rsm->getConstraints() );
        $this->assertSame( 'b', $rsm->getConstraint( 'siteId' )['value'] );
    }
}
