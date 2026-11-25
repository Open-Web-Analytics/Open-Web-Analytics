<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\ResultSetManager;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A filter on a Yes/No dimension (isOutbound, isGoalEvent) in the words its
 * cells show. The flags hold 1 for true and 0 or NULL for false; compared as
 * written, "Yes" against the integer column is cast to 0 and the filter
 * selected the false rows.
 */
final class BooleanConstraintTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: array{0: string, 1: int}}> */
    public static function accepted(): array
    {
        return [
            'is Yes'           => [ '==', 'Yes',   [ '==', 1 ] ],
            'is yes, any case' => [ '==', ' YES ', [ '==', 1 ] ],
            'is true'          => [ '==', 'true',  [ '==', 1 ] ],
            'is 1'             => [ '==', '1',     [ '==', 1 ] ],
            'a bare ='         => [ '=',  'Yes',   [ '==', 1 ] ],
            // Not 1: 0 and NULL both, as != is widened to NULL.
            'is No'            => [ '==', 'No',    [ '!=', 1 ] ],
            'is 0'             => [ '==', '0',     [ '!=', 1 ] ],
            'is false'         => [ '==', 'false', [ '!=', 1 ] ],
            'is not Yes'       => [ '!=', 'Yes',   [ '!=', 1 ] ],
            'is not No'        => [ '!=', 'No',    [ '==', 1 ] ],
        ];
    }

    /**
     * @dataProvider accepted
     * @param array{0: string, 1: int} $as
     */
    public function testTheShownWordsAreTheFlag( string $operator, string $value, array $as ): void
    {
        $this->assertSame( $as, ResultSetManager::booleanConstraint( $operator, $value ) );
    }

    public function testAnythingElseIsRefusedNamingTheDimension(): void
    {
        $this->assertSame( 'Outbound Click is Yes or No; "Maybe" is neither.',
            sprintf( ResultSetManager::booleanConstraint( '==', 'Maybe' ), 'Outbound Click' ) );
        $this->assertSame( 'Outbound Click is Yes or No; "100%" is neither.',
            sprintf( ResultSetManager::booleanConstraint( '==', '100%' ), 'Outbound Click' ), 'a % in the value is the value\'s' );

        foreach ( [ '=@', '>', '<', '=~' ] as $op ) {
            $this->assertSame( 'Outbound Click is Yes or No, so it can only be filtered with is or is not.',
                sprintf( ResultSetManager::booleanConstraint( $op, 'Yes' ), 'Outbound Click' ), $op );
        }
    }

    /** The page's own words for Yes and No are read too. */
    public function testTheTranslatedWordsAreRead(): void
    {
        \OWA\Core\Strings::useTranslations( [ 'Yes' => 'Oui', 'No' => 'Non' ] );

        try {
            $this->assertSame( [ '==', 1 ], ResultSetManager::booleanConstraint( '==', 'oui' ) );
            $this->assertSame( [ '!=', 1 ], ResultSetManager::booleanConstraint( '==', 'Non' ) );
            $this->assertSame( [ '==', 1 ], ResultSetManager::booleanConstraint( '==', 'Yes' ), 'and the English' );
        } finally {
            \OWA\Core\Strings::useTranslations( null );
        }
    }
}
