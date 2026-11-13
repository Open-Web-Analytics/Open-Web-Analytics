<?php

use PHPUnit\Framework\TestCase;

/**
 * The updates whose down() restores the schema but not the data say what they
 * do not restore, and a rollback says it before it starts.
 */
final class UpdateRollbackNotesTest extends TestCase
{
    const LOSSY = array( 48, 49, 52, 53, 54, 56, 68, 75 );

    public function testEachLossyRollbackSaysWhatItDoesNotRestore(): void
    {
        foreach ( self::LOSSY as $v ) {

            $class = sprintf( '\\OWA\\Module\\Base\\Update\\Update%03d', $v );
            $vars  = get_class_vars( ltrim( $class, '\\' ) );

            $this->assertNotSame( '', (string) ( $vars['down_does_not_restore'] ?? '' ),
                sprintf( 'Update%03d deletes or merges data in up(); its down() must say what it leaves out.', $v ) );
        }
    }

    public function testAnExactInverseSaysNothing(): void
    {
        $vars = get_class_vars( \OWA\Module\Base\Update\Update074::class );

        $this->assertSame( '', $vars['down_does_not_restore'] );
    }

    public function testTheRollbackSaysItBeforeRunningDown(): void
    {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/Core/Update.php' );

        $this->assertLessThan( strpos( $src, '$ret = $this->down();' ),
            strpos( $src, 'restores the schema but not' ),
            'the warning comes before down() runs' );
    }
}
