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

        /*
         * The kinds, for the modal that asks which one. Passed rather than read
         * from the constant in the template so the roster template stays a
         * template -- and so the reports roster, which shares it, is handed
         * nothing and renders no modal at all.
         */
        $this->set( 'visualization_types',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES );

        if ( ! empty( $this->data['may_author'] ) ) {

            $this->set( 'title_actions', array(
                array(
                    'url'   => \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' )
                                   ->makeLink( array( 'do' => 'base.visualizationEdit' ) ),
                    'label' => 'New Visualization',
                    'icon'  => 'fa-plus',
                    /*
                     * Hooked by the roster template, which asks WHICH KIND in a
                     * modal before opening the builder -- the same order the
                     * widget builder puts the question in, because the kind
                     * decides what the builder then asks for.
                     *
                     * Still a real link. With no JavaScript it opens the
                     * builder on the default kind rather than doing nothing.
                     */
                    'class' => 'owa_newVisualization',
                ),
            ) );
        }
    }
}
