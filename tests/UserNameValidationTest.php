<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A user name may not contain any of the user_id_illegal_chars setting, in any
 * position (Validation\UserName), and Sanitize::cleanUserId() removes every
 * one of them, wherever it is.
 *
 * No database: both read only the setting.
 */
final class UserNameValidationTest extends TestCase
{
    private function fails( $value ): bool
    {
        $v = new \OWA\Core\Validation\UserName();
        $v->setValues( $value );
        $v->validate();

        return (bool) $v->hasError;
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'first character, a quote'       => [ '\'alice' ],
            'first character, a space'       => [ ' alice' ],
            'first character, a parenthesis' => [ '(alice' ],
            'middle'                         => [ 'al;ice' ],
            'last'                           => [ 'alice|' ],
            'only the character'             => [ '"' ],
        ];
    }

    /** @dataProvider refused */
    public function testAnIllegalCharacterAnywhereIsRefused( string $name ): void
    {
        $this->assertTrue( $this->fails( $name ) );
    }

    public function testAPlainNameIsAccepted(): void
    {
        $this->assertFalse( $this->fails( 'alice.example-1' ) );
        $this->assertFalse( $this->fails( 'alice@example.com' ) );
    }

    public function testNoNameIsLeftToRequired(): void
    {
        $this->assertFalse( $this->fails( null ) );
    }

    public function testANameThatIsNotAStringIsRefused(): void
    {
        $this->assertTrue( $this->fails( [ 'alice' ] ) );
    }

    public function testTheMessage(): void
    {
        $v = new \OWA\Core\Validation\UserName();
        $v->setValues( '(alice' );
        $v->validate();

        $this->assertSame( 'The user name contains characters that are not allowed.', $v->getErrorMsg() );
    }

    public function testCleaningRemovesACharacterInTheFirstPosition(): void
    {
        $this->assertSame( 'alice', \OWA\Module\Base\Classes\Sanitize::cleanUserId( '\'alice' ) );
        $this->assertSame( 'alice', \OWA\Module\Base\Classes\Sanitize::cleanUserId( '(al)ice' ) );
        $this->assertSame( 'bob', \OWA\Module\Base\Classes\Sanitize::cleanUserId( 'bob' ) );
    }
}
