<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A constraint with an operator the builder does not allow matches nothing,
 * wherever it sits in the clause, and the clause stays valid SQL.
 *
 * It was skipped after the AND for it had been written, so last or alone it
 * left "WHERE a = ? AND " or a bare "WHERE ".
 */
final class DisallowedOperatorClauseTest extends TestCase
{
    /** @param list<array{name: string, operator: string, value: mixed}> $constraints */
    private function clause( array $constraints, string $type = 'WHERE' ): string
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $m  = new \ReflectionMethod( $db, '_makeConstraintClause' );

        return preg_replace( '/\s+/', ' ', trim( (string) $m->invoke( $db, $type, $constraints ) ) );
    }

    /** @return array<string, array{list<array{name: string, operator: string, value: mixed}>, string}> */
    public static function placements(): array
    {
        $ok  = [ 'name' => 'a', 'operator' => '==', 'value' => '1' ];
        $bad = [ 'name' => 'b', 'operator' => 'OR 1=1 --', 'value' => '2' ];

        return [
            'alone'  => [ [ $bad ],            'WHERE 1 = 0' ],
            'last'   => [ [ $ok, $bad ],       'WHERE a = ? AND 1 = 0' ],
            'first'  => [ [ $bad, $ok ],       'WHERE 1 = 0 AND a = ?' ],
            'middle' => [ [ $ok, $bad, $ok ],  'WHERE a = ? AND 1 = 0 AND a = ?' ],
        ];
    }

    /**
     * @dataProvider placements
     * @param list<array{name: string, operator: string, value: mixed}> $constraints
     */
    public function testADisallowedOperatorMatchesNothing( array $constraints, string $expected ): void
    {
        $this->assertSame( $expected, $this->clause( $constraints ) );
    }

    public function testTheOperatorTextNeverReachesTheSql(): void
    {
        $this->assertStringNotContainsString( '1=1',
            $this->clause( [ [ 'name' => 'b', 'operator' => 'OR 1=1 --', 'value' => '2' ] ] ) );
    }
}
