<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * file_name and file_extension become columns; the other nine params do not.
 *
 * A params key is unreportable in v2 until a site registers it as a CUSTOM
 * dimension -- Classes\Cube\Dimensions is the whole path from collected to
 * queryable -- so a downloads report cost every install one of its 20
 * registration slots for a value OWA set itself.
 *
 * THE NON-PROMOTION IS THE OTHER HALF, and is asserted here too. The element,
 * form and commerce params stay in the bag because most installs will never group
 * by them, and a column is width on every row of every Property. Measured on
 * MySQL 8.4: promoting nine of them took the custom-dimension ceiling from 62 to
 * 37. Without a test, that is a decision that erodes one column at a time.
 *
 * NOTHING HERE TESTS A CLEANUP OF CUSTOM REGISTRATIONS. An earlier version did:
 * a registration for `file_name` would read params.file_name, which nothing
 * writes any more. Unreachable -- custom dimensions are a v2 feature and the
 * branch has not shipped, so no install can carry one for either key.
 */
final class Update054Test extends TestCase
{
    public function testTheTwoPromotedPropertiesAreColumnsAndNotParams(): void
    {
        $columns = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();

        foreach ( \OWA\Module\Base\Update\Update054::PROMOTED as $name ) {

            $this->assertContains( $name, $columns,
                "$name must be a column, or a downloads report needs a custom dimension." );

            $this->assertSame( $name, Helpers::columnFor( $name ),
                "the registry must send $name to a column of its own name" );
        }

        // And out of the bag: two authorities for one value is the drift this
        // whole registry exists to prevent.
        $params = Helpers::paramsForEvent( 'file_download' );

        $this->assertNotContains( 'file_name', $params );
        $this->assertNotContains( 'file_extension', $params );
    }

    /**
     * The ones NOT promoted are still params, and still have no column.
     *
     * search_term left this list: it is a column now, because a site-search report
     * is a question every install with a search box asks. The element, form and
     * commerce params stay.
     *
     * ct_line_items could not be a column whatever the row budget: it is a nested
     * array, so no scalar column could hold it whatever the row budget.
     */
    public function testTheOtherParamsWereLeftAlone(): void
    {
        $columns = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();

        $kept = array(
            'dom_element_class' => 'click',
            'dom_element_name'  => 'click',
            'dom_element_text'  => 'click',
            'form_id'           => 'form_start',
            'form_name'         => 'form_start',
            'form_destination'  => 'form_start',
            'form_length'       => 'form_start',
            'first_field_id'    => 'form_start',
            'first_field_type'  => 'form_start',
            'form_submit_text'  => 'form_submit',
            'ct_order_source'   => 'purchase',
            'ct_gateway'        => 'purchase',
            'ct_line_items'     => 'purchase',
        );

        foreach ( $kept as $property => $event ) {

            $this->assertSame( '', Helpers::columnFor( $property ),
                "$property is a param by decision, not an oversight -- see Update054." );

            $this->assertNotContains( $property, $columns );

            $this->assertContains( Helpers::paramFor( $property ),
                Helpers::paramsForEvent( $event ),
                "$property must still reach params on $event" );
        }
    }

}
