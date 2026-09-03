<?php
namespace OWA\Module\Base\Controller;

/**
 * The visualizations roster.
 *
 * A visualization is a custom report that COMPUTES rather than configures -- a
 * funnel counts ordered stages over the event stream, which no arrangement of
 * metrics and dimensions expresses. They share a table and every screen around
 * it with reports, because ownership, access control, editable titles and
 * delete are identical.
 *
 * Listed separately on purpose. A roster mixing "Pages by source" with
 * "Checkout funnel" teaches the reader they are the same thing and offers the
 * same controls on both, and a visualization does not have them.
 */
class Visualizations extends CustomReports {

    protected function rosterType() {

        return \OWA\Module\Base\Entity\CustomReport::TYPE_VISUALIZATION;
    }

    function success() {

        parent::success();

        $this->set( 'title', 'Visualizations' );

        if ( ! empty( $this->data['may_author'] ) ) {

            $this->set( 'title_actions', array(
                array(
                    'url'   => \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' )
                                   ->makeLink( array( 'do' => 'base.visualizationEdit' ) ),
                    'label' => 'New Visualization',
                    'icon'  => 'fa-plus',
                ),
            ) );
        }
    }
}
