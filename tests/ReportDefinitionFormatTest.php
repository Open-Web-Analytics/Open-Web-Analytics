<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What a report definition may say, and what it means.
 *
 * The equivalence test proves the 53 shipped definitions reproduce their
 * controllers. These pin the rules those definitions are written in, which is
 * the part a new report -- or a user-built one later -- depends on.
 *
 * The format deliberately has no expression language. A placeholder substitutes
 * a value and nothing else; the one transformation a parameter can need is
 * declared on the parameter. That is what keeps a definition data rather than
 * something that has to be evaluated.
 */
final class ReportDefinitionFormatTest extends TestCase
{
    /** Render a definition and return the declared bag. */
    private function declared( array $definition, array $params = array() ): array
    {
        $controller = new \OWA\Core\ConfiguredReport( $params );
        $controller->setDefinition( $definition );
        $controller->action();

        return (array) $controller->data;
    }

    private function base( array $extra = array() ): array
    {
        return array_merge( array(
            'title'   => 'A Report',
            'subview' => 'base.reportDimension',
        ), $extra );
    }

    public function testAPlaceholderTakesItsValueFromTheRequest(): void
    {
        $d = $this->declared(
            $this->base( array(
                'title'       => 'Host Detail: ',
                'titleSuffix' => '{hostName}',
                'params'      => array( 'hostName' => array() ),
            ) ),
            array( 'hostName' => 'example.com' )
        );

        $this->assertSame( 'Host Detail: ', $d['title'] );
        $this->assertSame( 'example.com', $d['titleSuffix'] );
    }

    /** Several placeholders, and one used twice. */
    public function testPlaceholdersComposeInOneString(): void
    {
        $d = $this->declared(
            $this->base( array(
                'titleSuffix' => '{a}: {b} ({a})',
                'params'      => array( 'a' => array(), 'b' => array() ),
            ) ),
            array( 'a' => 'group', 'b' => 'name' )
        );

        $this->assertSame( 'group: name (group)', $d['titleSuffix'] );
    }

    /**
     * A placeholder works wherever a value is authored, not only in the title.
     * ReportBrowserDetail put its parameter inside dimension_properties.
     */
    public function testAPlaceholderReachesNestedSettings(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'   => array( 'browserType' => array() ),
                'settings' => array(
                    'dimension_properties' => array( 'browser_family' => '{browserType}' ),
                ),
            ) ),
            array( 'browserType' => 'Firefox' )
        );

        $this->assertSame( array( 'browser_family' => 'Firefox' ), $d['dimension_properties'] );
    }

    /** Non-strings survive untouched, so resultsPerPage stays an integer. */
    public function testInterpolationDoesNotChangeTypes(): void
    {
        $d = $this->declared(
            $this->base( array( 'settings' => array( 'resultsPerPage' => 30 ) ) ) );

        $this->assertSame( 30, $d['resultsPerPage'] );
    }

    /**
     * Three reports store their dimension lowercased, so the value has to be
     * lowercased before it is constrained on -- otherwise "Google" and "google"
     * are different ads.
     */
    public function testADeclaredParameterCanBeLowercased(): void
    {
        $d = $this->declared(
            $this->base( array(
                'titleSuffix' => '{campaign}',
                'params'      => array( 'campaign' => array( 'lowercase' => true ) ),
                'settings'    => array( 'constraints' => array(
                    array( 'dimension' => 'campaign', 'fromParam' => 'campaign' ) ) ),
            ) ),
            array( 'campaign' => 'SpringSALE' )
        );

        $this->assertSame( 'springsale', $d['titleSuffix'],
            'the normalisation applies to the value, so the title shows what was matched' );
        $this->assertSame( 'campaign==springsale', $d['constraints'] );
    }

    /** ...and a parameter without it keeps its case. */
    public function testAParameterIsNotLowercasedByDefault(): void
    {
        $d = $this->declared(
            $this->base( array(
                'titleSuffix' => '{hostName}',
                'params'      => array( 'hostName' => array() ),
            ) ),
            array( 'hostName' => 'Example.COM' )
        );

        $this->assertSame( 'Example.COM', $d['titleSuffix'] );
    }

    /**
     * The reason constraints are structured rather than a string with
     * placeholders: the two kinds of value are encoded differently.
     */
    public function testARequestValueIsEncodedAndALiteralIsNot(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'   => array( 'referralWebSite' => array() ),
                'settings' => array( 'constraints' => array(
                    array( 'dimension' => 'medium', 'value' => 'organic-search' ),
                    array( 'dimension' => 'referralWebSite', 'fromParam' => 'referralWebSite' ),
                ) ),
            ) ),
            array( 'referralWebSite' => 'a b&c' )
        );

        $this->assertSame( 'medium==organic-search,referralWebSite==a+b%26c', $d['constraints'],
            'a value from the request must be encoded; a literal must be left alone' );
    }

    public function testAnOperatorOtherThanEqualsIsHonoured(): void
    {
        $d = $this->declared(
            $this->base( array( 'settings' => array( 'constraints' => array(
                array( 'dimension' => 'ad', 'operator' => '!=', 'value' => 'null' ) ) ) ) ) );

        $this->assertSame( 'ad!=null', $d['constraints'] );
    }

    /** A plain string is still a constraint, which is what most reports use. */
    public function testAStringConstraintIsUsedAsIs(): void
    {
        $d = $this->declared(
            $this->base( array( 'settings' => array( 'constraints' => 'medium==referral' ) ) ) );

        $this->assertSame( 'medium==referral', $d['constraints'] );
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAnUnusableDefinitionIsRefused( array $definition, string $because ): void
    {
        $error = \OWA\Core\ConfiguredReport::getDefinitionError( $definition );

        $this->assertNotSame( '', $error, 'this definition should not be accepted' );
        $this->assertStringContainsString( $because, $error );
    }

    public static function refusedProvider(): array
    {
        $base = array( 'title' => 'A Report', 'subview' => 'base.reportDimension' );

        return array(
            'params not an object' => array(
                $base + array( 'params' => 'hostName' ), '"params" must be an object' ),

            'constraint with no dimension' => array(
                $base + array( 'settings' => array( 'constraints' => array( array( 'value' => 'x' ) ) ) ),
                'needs a "dimension"' ),

            'constraint with no value' => array(
                $base + array( 'settings' => array( 'constraints' => array( array( 'dimension' => 'ad' ) ) ) ),
                'needs either a "value" or a "fromParam"' ),

            /*
             * The one worth refusing loudest. An undeclared parameter reads as
             * empty, so the constraint becomes `hostName==` -- which matches
             * nothing and looks like the report has no data rather than like a
             * typo in its definition.
             */
            'constraint on an undeclared parameter' => array(
                $base + array( 'settings' => array( 'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hosName' ) ) ) ),
                'undeclared parameter' ),
        );
    }

    /** A well-formed parameterised definition is not caught by any of that. */
    public function testAWellFormedParameterisedDefinitionIsAccepted(): void
    {
        $this->assertSame( '', \OWA\Core\ConfiguredReport::getDefinitionError( array(
            'title'       => 'Host Detail: ',
            'titleSuffix' => '{hostName}',
            'subview'     => 'base.reportDimensionDetail',
            'params'      => array( 'hostName' => array( 'lowercase' => false ) ),
            'settings'    => array(
                'metrics'     => 'visits',
                'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hostName' ) ),
            ),
        ) ) );
    }

    /**
     * A missing request parameter must not produce a constraint that silently
     * matches everything.
     */
    public function testAnAbsentParameterStillConstrains(): void
    {
        $d = $this->declared(
            $this->base( array(
                'params'   => array( 'hostName' => array() ),
                'settings' => array( 'constraints' => array(
                    array( 'dimension' => 'hostName', 'fromParam' => 'hostName' ) ) ),
            ) ),
            array()
        );

        $this->assertSame( 'hostName==', $d['constraints'],
            'an absent parameter constrains on empty -- which returns nothing, rather '
            . 'than dropping the constraint and returning every row' );
    }
}
