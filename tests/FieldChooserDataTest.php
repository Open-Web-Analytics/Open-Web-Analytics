<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\FieldGroups;
use OWA\Module\Base\Controller\CustomReportEdit;

/**
 * What the metric and dimension choosers are given: each field's description,
 * group, the group's name and its type, in the report editor's lists and in a
 * result set's related dimensions and metrics. Nothing here needs a database:
 * with no Property, the lists are the modules' vocabulary.
 */
final class FieldChooserDataTest extends TestCase
{
    const KEYS = [ 'name', 'label', 'description', 'group', 'group_label', 'type' ];

    /** @return array<string, array{0: list<array<string, string>>}> */
    public static function editorLists(): array
    {
        return [
            'metrics'    => [ CustomReportEdit::metricChoices() ],
            'dimensions' => [ CustomReportEdit::dimensionChoices( null ) ],
        ];
    }

    /** @dataProvider editorLists */
    public function testTheEditorsChoicesCarryWhatTheChooserShows( array $choices ): void
    {
        $this->assertNotEmpty( $choices );

        foreach ( $choices as $choice ) {

            $this->assertSame( self::KEYS, array_keys( $choice ), 'and nothing of the registry beyond it' );

            foreach ( [ 'description', 'group', 'group_label', 'type' ] as $key ) {
                $this->assertNotSame( '', $choice[ $key ], $choice['name'] . ' has no ' . $key );
            }
        }
    }

    /** @dataProvider editorLists */
    public function testEveryBuiltInGroupHasAName( array $choices ): void
    {
        foreach ( $choices as $choice ) {
            $this->assertArrayHasKey( $choice['group'], FieldGroups::LABELS, 'shown as ' . $choice['group_label'] );
        }
    }

    public function testADimensionsTypeIsItsDataTypeAndAMetricsItsMetricType(): void
    {
        $dims    = array_column( CustomReportEdit::dimensionChoices( null ), null, 'name' );
        $metrics = array_column( CustomReportEdit::metricChoices(), null, 'name' );

        $this->assertSame( [ 'boolean', 'traffic source', 'Traffic Source' ],
            [ $dims['isOutbound']['type'], $dims['attributedSource']['group'], $dims['attributedSource']['group_label'] ] );
        $this->assertSame( 'event', $dims['isOutbound']['group'] );
        $this->assertSame( [ 'distinct_count', 'Site Usage' ], [ $metrics['sessions']['type'], $metrics['sessions']['group'] ] );
    }

    public function testTheGridsRelatedDimensionsAndMetricsCarryThemToo(): void
    {
        $rsm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'resultSetManager' );

        $dims = [];

        foreach ( $rsm->relatedDimensions() as $family => $list ) {
            foreach ( $list as $d ) {
                $this->assertSame( [ 'name', 'label', 'description', 'group_label', 'data_type' ], array_keys( $d ) );
                $this->assertSame( FieldGroups::label( $family ), $d['group_label'] );
                $dims[ $d['name'] ] = $d;
            }
        }

        $this->assertSame( 'boolean', $dims['isOutbound']['data_type'] );
        $this->assertSame( 'Whether the click went to a host other than the page it was on.', $dims['isOutbound']['description'] );

        foreach ( $rsm->relatedMetrics() as $group => $list ) {
            foreach ( $list as $m ) {
                $this->assertSame( FieldGroups::label( $group ), $m['group_label'], $m['name'] );
            }
        }
    }

    public function testAGroupNameIsTranslatedAndAnUnknownOneIsItsKey(): void
    {
        \OWA\Core\Strings::useTranslations( [ 'Traffic Source' => 'Provenance' ] );

        try {
            $this->assertSame( 'Provenance', FieldGroups::label( 'traffic source' ) );
            $this->assertSame( 'Loyalty points', FieldGroups::label( 'loyalty points' ), 'a module\'s own group' );
        } finally {
            \OWA\Core\Strings::useTranslations( null );
        }
    }
}
