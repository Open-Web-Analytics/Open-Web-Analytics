<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * How a widget's declared size becomes a place in the grid.
 *
 * The clamp is the reason this class exists. A span wider than the grid does
 * not overflow and is not truncated -- CSS grid adds implicit columns to fit
 * it, so the grid itself gets wider and every other widget shrinks to match.
 * The report that looks wrong is not the one with the bad number in it, which
 * is the worst possible way for a layout bug to present.
 */
final class ReportGridTest extends TestCase
{
    private const CSS = OWA_DIR . 'modules/Base/css/owa.report.css';

    /**
     * The stylesheet with its comments removed.
     *
     * Every check below is about what the CSS DECLARES. Scanning the raw file
     * conflates that with what it discusses: the comment explaining why
     * grid-auto-flow: dense is not used contains the exact string that check
     * looks for, and the "must contain" checks would equally have been
     * satisfied by a comment mentioning the property and no rule implementing
     * it -- which is a test that passes because of its own documentation.
     */
    private function declarations(): string
    {
        return (string) preg_replace( '#/\*.*?\*/#s', '',
            (string) file_get_contents( self::CSS ) );
    }

    public function testAWidgetWithNoSpanIsFullWidth(): void
    {
        $this->assertSame( \OWA\Core\ReportGrid::COLUMNS,
            \OWA\Core\ReportGrid::colspan( array() ),
            'most report widgets are full width, so that is what saying nothing means' );
    }

    public function testADeclaredSpanIsKept(): void
    {
        $this->assertSame( 6, \OWA\Core\ReportGrid::colspan( array( 'colspan' => 6 ) ) );
        $this->assertSame( 1, \OWA\Core\ReportGrid::colspan( array( 'colspan' => 1 ) ) );
        $this->assertSame( 12, \OWA\Core\ReportGrid::colspan( array( 'colspan' => 12 ) ) );
    }

    /**
     * The defect this guards. Left alone, `span 16` in a 12-column grid makes
     * the grid 16 columns wide.
     */
    public function testAnOverWideSpanIsClampedRatherThanWideningTheGrid(): void
    {
        $this->assertSame( 12, \OWA\Core\ReportGrid::colspan( array( 'colspan' => 16 ) ),
            'a span wider than the grid must become full width, not widen the grid' );

        $this->assertTrue( \OWA\Core\ReportGrid::isOverWide( array( 'colspan' => 16 ) ),
            'and it must still be reportable as a mistake, not silently accepted' );

        $this->assertFalse( \OWA\Core\ReportGrid::isOverWide( array( 'colspan' => 12 ) ) );
        $this->assertFalse( \OWA\Core\ReportGrid::isOverWide( array() ) );
    }

    /**
     * Zero would become `span 0`, which browsers treat as `span 1` -- a widget
     * one cell wide, which looks like a styling bug rather than a bad value.
     *
     * @dataProvider uselessSpanProvider
     */
    public function testANonsensicalSpanFallsBackToFullWidth( $value ): void
    {
        $this->assertSame( 12, \OWA\Core\ReportGrid::colspan( array( 'colspan' => $value ) ) );
    }

    public static function uselessSpanProvider(): array
    {
        return array( 'zero' => array( 0 ), 'negative' => array( -3 ), 'empty' => array( '' ) );
    }

    /**
     * A grid-card is a quarter of the row without saying so.
     *
     * The width is part of what the type IS -- a card shows one metric against
     * one dimension and draws none of the explorer controls a full-width table
     * has room for. Carried by the type rather than written into every card
     * definition, so a card cannot be authored at a width that would make it a
     * table with the controls missing.
     */
    public function testAGridCardIsAQuarterWideByDefault(): void
    {
        $this->assertSame( 3,
            \OWA\Core\ReportGrid::colspan( array( 'type' => 'grid-card' ) ) );

        $this->assertSame( 3,
            \OWA\Core\ReportGrid::defaultColspan( array( 'type' => 'grid-card' ) ) );
    }

    public function testEveryOtherTypeStillDefaultsToFullWidth(): void
    {
        foreach ( array( 'grid', 'pie', 'trend', 'metric-boxes', '', 'something-new' ) as $type ) {

            $this->assertSame( \OWA\Core\ReportGrid::COLUMNS,
                \OWA\Core\ReportGrid::colspan( array( 'type' => $type ) ),
                "a $type widget that names no span should be full width" );
        }
    }

    /**
     * The fallback for a nonsensical span is the TYPE's default, not 12.
     *
     * A card with `colspan: 0` recovering to full width would be the one
     * outcome the type exists to prevent -- a card's controls are drawn on the
     * assumption it is narrow.
     */
    public function testANonsensicalSpanOnACardFallsBackToTheCardWidth(): void
    {
        foreach ( array( 0, -3, '' ) as $value ) {

            $this->assertSame( 3, \OWA\Core\ReportGrid::colspan(
                array( 'type' => 'grid-card', 'colspan' => $value ) ) );
        }
    }

    public function testACardMaySayItIsWiderAndIsBelieved(): void
    {
        // The default is what a type MEANS, not a cap. A definition that names
        // a width still gets it -- clamped like any other.
        $this->assertSame( 6, \OWA\Core\ReportGrid::colspan(
            array( 'type' => 'grid-card', 'colspan' => 6 ) ) );
    }

    public function testRowspanDefaultsToOneAndIsBounded(): void
    {
        $this->assertSame( 1, \OWA\Core\ReportGrid::rowspan( array() ) );
        $this->assertSame( 1, \OWA\Core\ReportGrid::rowspan( array( 'rowspan' => 0 ) ) );
        $this->assertSame( 3, \OWA\Core\ReportGrid::rowspan( array( 'rowspan' => 3 ) ) );
        $this->assertSame( \OWA\Core\ReportGrid::MAX_ROWSPAN,
            \OWA\Core\ReportGrid::rowspan( array( 'rowspan' => 99 ) ) );
    }

    public function testTheClassAttributeCarriesTheSizes(): void
    {
        $this->assertSame( 'owa_reportGridItem owa_span-12',
            \OWA\Core\ReportGrid::classesFor( array() ) );

        $this->assertSame( 'owa_reportGridItem owa_span-6',
            \OWA\Core\ReportGrid::classesFor( array( 'colspan' => 6 ) ) );

        $this->assertSame( 'owa_reportGridItem owa_span-4 owa_rowspan-2',
            \OWA\Core\ReportGrid::classesFor( array( 'colspan' => 4, 'rowspan' => 2 ) ) );
    }

    /**
     * A rowspan of 1 adds no class, because `grid-row: span 1` is the default
     * and a class that does nothing still has to be read by whoever debugs it.
     */
    public function testASingleRowAddsNoClass(): void
    {
        $this->assertStringNotContainsString( 'rowspan',
            \OWA\Core\ReportGrid::classesFor( array( 'rowspan' => 1 ) ) );
    }

    /**
     * Every class this class can emit must exist in the stylesheet.
     *
     * The failure otherwise is silent: an undefined .owa_span-7 leaves the
     * widget at its default width with nothing anywhere saying why.
     */
    public function testTheStylesheetDefinesEverySpanClass(): void
    {
        $css = $this->declarations();

        $missing = array();

        foreach ( \OWA\Core\ReportGrid::spanClasses() as $class ) {

            if ( strpos( $css, '.' . $class . ' ' ) === false
                 && strpos( $css, '.' . $class . ',' ) === false ) {

                $missing[] = $class;
            }
        }

        $this->assertSame( array(), $missing,
            'these classes can be emitted but are not styled: ' . implode( ', ', $missing ) );
    }

    /** ...and the check above is capable of noticing an absence. */
    public function testTheStylesheetCheckCanFail(): void
    {
        $css = $this->declarations();

        $this->assertStringNotContainsString( '.owa_span-13', $css,
            'a class beyond the column count must NOT be styled, or the check above '
            . 'would pass for a grid of any width' );
    }

    /**
     * The grid track minimum has to be 0.
     *
     * A bare `1fr` will not shrink below its content, so one wide grid or chart
     * pushes the whole report past its container and the page scrolls
     * sideways -- which is the bug this whole layout is meant to avoid.
     */
    public function testTracksCanShrinkBelowTheirContent(): void
    {
        $css = $this->declarations();

        $this->assertStringContainsString( 'repeat(12, minmax(0, 1fr))', $css,
            'grid tracks need a zero minimum or wide content widens the whole report' );

        $this->assertStringContainsString( 'min-width: 0', $css,
            'and the item needs it too, for the same reason' );
    }

    /**
     * Widgets nest, so the layout has to respond to the space a widget has
     * rather than to the viewport. Only a container query can see that.
     */
    public function testTheGridRespondsToItsContainerNotTheViewport(): void
    {
        $css = $this->declarations();

        $this->assertStringContainsString( 'container-type: inline-size', $css );
        $this->assertStringContainsString( '@container owa-report', $css );
    }

    /**
     * dense backfills gaps by reordering, which would present a report's
     * widgets in an order nobody wrote.
     */
    public function testTheGridDoesNotReorderWidgets(): void
    {
        $css = $this->declarations();

        $this->assertStringNotContainsString( 'grid-auto-flow: dense', $css,
            'dense packing reorders widgets to fill gaps; a report is a sequence' );
    }

    /**
     * The comment stripper has to actually strip, or every check above is
     * reading the file it thinks it is not reading.
     */
    public function testCommentsAreExcludedFromTheDeclarations(): void
    {
        $raw = (string) file_get_contents( self::CSS );

        $this->assertStringContainsString( 'grid-auto-flow: dense', $raw,
            'the stylesheet should still EXPLAIN why dense is not used' );

        $this->assertStringNotContainsString( 'grid-auto-flow: dense', $this->declarations(),
            '...and the stripper must remove that explanation before the checks read it' );
    }
}
