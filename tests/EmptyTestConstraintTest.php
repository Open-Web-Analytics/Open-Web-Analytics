<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\ResultSetManager;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * `==(not set)` and `!=(not set)` are the empty test.
 *
 * "(not set)" is the label for a dimension holding NULL or '' -- it is never
 * stored -- so these two select on emptiness instead of comparing against the
 * label. `!=` in particular must not be null-tolerant here: a negation is
 * widened with `OR col IS NULL`, which would keep every row "is set" is asked
 * to drop.
 */
final class EmptyTestConstraintTest extends TestCase
{
    private function clause( $operator, $name = 'campaign', $type = 'WHERE' )
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $m = new \ReflectionMethod( $db, '_makeConstraintClause' );
        $m->setAccessible( true );

        return preg_replace( '/\s+/', ' ', trim( $m->invoke( $db, $type,
            array( array( 'name' => $name, 'operator' => $operator, 'value' => '(not set)' ) ) ) ) );
    }

    public function testEmptyMatchesNullAndTheEmptyString(): void
    {
        $this->assertSame( "WHERE ( campaign IS NULL OR campaign = '' )", $this->clause( 'empty' ) );
    }

    public function testNotEmptyExcludesBothAndIsNotWidened(): void
    {
        $this->assertSame( "WHERE ( campaign IS NOT NULL AND campaign <> '' )", $this->clause( 'notempty' ) );
    }

    /** The value is ignored: nothing is bound, so the placeholder count stays right. */
    public function testTheEmptyTestBindsNothing(): void
    {
        $this->assertStringNotContainsString( '?', $this->clause( 'notempty' ) );
        $this->assertStringNotContainsString( 'not set', $this->clause( 'notempty' ) );
    }

    /**
     * @dataProvider mappings
     */
    public function testTheNotSetLabelMapsToTheEmptyTest( string $operator, string $value, string $expected ): void
    {
        $this->assertSame( $expected, ResultSetManager::emptyTestFor(
            array( 'name' => 'sessionCampaign', 'operator' => $operator, 'value' => $value ) ) );
    }

    public function testARangeValueIsNotTheEmptyTest(): void
    {
        // Every report's date range is a BETWEEN constraint holding an array;
        // casting it to a string raised "Array to string conversion".
        $this->assertSame( '', ResultSetManager::emptyTestFor( array(
            'name' => 'yyyymmdd', 'operator' => 'BETWEEN',
            'value' => array( 'start' => 20260101, 'end' => 20260131 ) ) ) );
    }

    public static function mappings(): array
    {
        return array(
            'equals'              => array( '==', '(not set)', 'empty' ),
            'not equals'          => array( '!=', '(not set)', 'notempty' ),
            'other operator'      => array( '=@', '(not set)', '' ),
            'ordinary value'      => array( '!=', 'spring-sale', '' ),
            'the literal "null"'  => array( '!=', 'null', '' ),
        );
    }

    /** End to end against the server: which rows each form keeps. */
    public function testTheRowsEachFormKeeps(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the server decides what NULL comparisons return' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $rows = '(SELECT "tagged" AS tag, "spring-sale" AS campaign'
              . ' UNION ALL SELECT "null", NULL'
              . ' UNION ALL SELECT "blank", "") t';

        $tags = function ( $operator ) use ( $db, $rows ) {
            $m = new \ReflectionMethod( $db, '_makeConstraintClause' );
            $m->setAccessible( true );
            $where = $m->invoke( $db, 'WHERE', array( array(
                'name' => 't.campaign', 'operator' => $operator, 'value' => '(not set)' ) ) );
            $tags = array_column( (array) $db->get_results( "SELECT t.tag FROM $rows $where" ), 'tag' );
            sort( $tags );
            return $tags;
        };

        $this->assertSame( array( 'tagged' ), $tags( 'notempty' ) );
        $this->assertSame( array( 'blank', 'null' ), $tags( 'empty' ) );
    }

    /** A metric has no "(not set)"; asking is an error, not a silent no-op. */
    public function testAMetricCannotBeNotSet(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the metric registry loads modules' );
        }

        $rsm = new ResultSetManager;
        $rsm->applyConstraint( array( 'name' => 'sessions', 'operator' => '!=', 'value' => '(not set)' ),
            \OWA\Core\CoreAPI::dbSingleton() );

        $p = new \ReflectionProperty( $rsm, 'errors' );
        $p->setAccessible( true );

        $this->assertNotEmpty( (array) $p->getValue( $rsm ) );
    }
}
