<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/tools/wiki/generate.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\AppPasswords;
use OWA\Module\Base\Classes\Cube\Dimensions;
use OWA\Module\Base\Classes\DatabaseRequirement;
use OWA\Module\Base\Classes\EnvironmentCheck;
use OWA\Module\Base\Entity\CustomDimension;

/**
 * The generated sections for the requirements, application-password and
 * custom-dimension pages list every entry of the constant or registry they are
 * read from, so a new entry cannot be left off the page.
 *
 * Needs no database: everything here reads constants and checks of the
 * server's environment.
 */
final class WikiDocsDataAdminTest extends TestCase
{
    private static function section( string $name ): string
    {
        return owa_wiki_generate( $name );
    }

    public function testEveryEnvironmentCheckIsInTheTableWithWhatPasses(): void
    {
        $md     = self::section( 'environment-checks' );
        $checks = EnvironmentCheck::all( \OWA\Core\Env::root() . '/owa-config.php' );

        $this->assertNotEmpty( $checks );

        foreach ( $checks as $check ) {

            $this->assertNotSame( '', trim( $check['requires'] ), "{$check['name']} does not say what passes" );
            $this->assertStringContainsString( '| **' . $check['name'] . '** | ' . owa_wiki_cell( $check['requires'] ) . ' |', $md,
                "{$check['name']} is not in the table" );
        }

        $this->assertStringContainsString( EnvironmentCheck::MIN_PHP_VERSION, $md );
    }

    /** A check's 'requires' is the same whether it passes or fails. */
    public function testWhatPassesDoesNotDependOnTheOutcome(): void
    {
        $this->assertSame( 'x', EnvironmentCheck::check( 'n', 'v', true, 'fix it', 'x' )['requires'] );
        $this->assertSame( 'x', EnvironmentCheck::check( 'n', 'v', false, 'fix it', 'x' )['requires'] );
    }

    public function testEveryRequiredExtensionIsInTheTable(): void
    {
        $md = self::section( 'php-extensions' );

        $this->assertNotEmpty( EnvironmentCheck::REQUIRED_EXTENSIONS );

        foreach ( EnvironmentCheck::REQUIRED_EXTENSIONS as $extension => $what_for ) {
            $this->assertStringContainsString( "| `$extension` | " . owa_wiki_cell( $what_for ) . ' |', $md, "$extension is not in the table" );
        }
    }

    public function testTheDatabaseFloorsAreTheEnforcedOnes(): void
    {
        $md = self::section( 'database-servers' );

        $this->assertMatchesRegularExpression( '/^\| MySQL\b.* \| ' . preg_quote( DatabaseRequirement::MYSQL_MIN, '/' ) . ' \|$/m', $md );
        $this->assertMatchesRegularExpression( '/^\| MariaDB \| ' . preg_quote( preg_replace( '/\.0$/', '', DatabaseRequirement::MARIADB_MIN ), '/' ) . ' \|$/m', $md );

        // The trimmed MariaDB floor is the one problemFor() holds a server to.
        $this->assertNull( DatabaseRequirement::problemFor( DatabaseRequirement::MARIADB_MIN . '-MariaDB' ) );
        $this->assertNull( DatabaseRequirement::problemFor( DatabaseRequirement::MYSQL_MIN ) );
    }

    public function testEveryAppPasswordAccessLevelIsInTheTable(): void
    {
        $md = self::section( 'app-password-access' );

        $this->assertCount( 2, AppPasswords::scopes(), 'a new scope needs its own Allows text in the section' );

        foreach ( AppPasswords::scopes() as $scope => $label ) {
            $this->assertMatchesRegularExpression( '/^\| \*\*' . preg_quote( $label, '/' ) . '\*\* \| `' . preg_quote( $scope, '/' ) . '` \| .+ \|$/m', $md,
                "$scope is not in the table" );
        }

        preg_match( '/^\| \*\*[^|]+\*\* \| `' . AppPasswords::SCOPE_READ . '` \| (.+) \|$/m', $md, $m );

        foreach ( AppPasswords::READ_METHODS as $method ) {
            $this->assertStringContainsString( "`$method`", $m[1] ?? '', "read only allows $method, and the table does not say so" );
        }
    }

    /** Auth reads the methods from the constant the section documents. */
    public function testAuthReadsTheReadOnlyMethodsFromTheConstant(): void
    {
        $source = (string) file_get_contents( \OWA\Core\Env::root() . '/Core/Auth.php' );

        $this->assertStringContainsString( 'AppPasswords::READ_METHODS', $source );
        $this->assertStringNotContainsString( "array( 'GET', 'HEAD' )", $source );
    }

    public function testTheCustomDimensionLimitsAreTheEnforcedOnes(): void
    {
        $md = self::section( 'custom-dimension-limits' );

        $this->assertStringContainsString( '`' . substr( Dimensions::KEY_PATTERN, 1, -1 ) . '`', $md );
        $this->assertMatchesRegularExpression( '/^\| Dimensions per Property \| ' . Dimensions::MAX_PER_PROPERTY . '\b/m', $md );
        $this->assertMatchesRegularExpression( '/^\| A `' . CustomDimension::TYPE_STRING . '` value \| up to ' . Dimensions::DIMENSION_LENGTH . ' characters/m', $md );
        $this->assertMatchesRegularExpression( '/^\| Custom properties kept on one event, per scope \| '
            . \OWA\Module\Base\Handler\EventRawHandlers::MAX_CUSTOM_PROPERTIES . '\b/m', $md );
        $this->assertStringContainsString( '`' . CustomDimension::PREFIX . '<key>`', $md );
        $this->assertStringContainsString( '`' . CustomDimension::PREFIX . '<key>' . CustomDimension::SET_TS_SUFFIX . '`', $md );

        // The length the table states is the longest key the pattern takes.
        preg_match( '/up to (\d+) characters \|$/m', $md, $m );
        $longest = (int) ( $m[1] ?? 0 );

        $this->assertGreaterThan( 0, $longest );
        $this->assertMatchesRegularExpression( Dimensions::KEY_PATTERN, 'a' . str_repeat( 'b', $longest - 1 ) );
        $this->assertDoesNotMatchRegularExpression( Dimensions::KEY_PATTERN, 'a' . str_repeat( 'b', $longest ) );
    }
}
