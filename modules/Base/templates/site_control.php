<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The site control: what is being reported on, and the navigation for changing it.
 *
 * It sits above the left nav because it scopes everything in that nav -- every
 * report below it is a report OF this Property. The old control was a select in
 * the content column, which put the thing every report depends on beside the
 * reports rather than above them.
 *
 * It stops at the Property. A report reads the Property's cube, which holds
 * every Profile beneath it; a report broken out by Profile uses the Profile
 * dimension. A Profile's own screens are reached from the Property's
 * Observation Profiles roster in settings.
 *
 * Expanded it is two columns, one per tier. Column 1 anticipates several
 * Organizations; there is one today and it is not a list yet, but the column is
 * where it will go.
 */
$owa_hierarchy = $view->site_hierarchy ?: array( 'organization' => array(), 'properties' => array() );

/*
 * The Property in context: the one the screen names, or else the one the
 * screen's Profile belongs to.
 */
$owa_context_property = (string) ( $view->params['propertyId'] ?? '' );
$owa_context_site     = (string) ( $view->params['siteId'] ?? '' );

$owa_current = array( 'index' => null, 'property' => '', 'domain' => '' );

foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ) {

    $owa_match = $owa_context_property !== ''
        ? (string) $owa_p['id'] === $owa_context_property
        : in_array( $owa_context_site, array_column( $owa_p['profiles'], 'site_id' ), true );

    if ( $owa_match && $owa_context_site . $owa_context_property !== '' ) {

        $owa_current = array( 'index' => $owa_i, 'property' => $owa_p['name'], 'domain' => $owa_p['domain'] );
    }
}
?>
<div id="owa_siteControl" class="owa_siteControl">

    <div id="owa_siteControlSummary" class="owa_siteControlSummary" role="button" tabindex="0"
         aria-expanded="false" aria-controls="owa_siteControlPanel">
        <div class="owa_siteControlOrg"><?php $view->out( $owa_hierarchy['organization']['name'] ?? '' );?></div>
        <div class="owa_siteControlProperty">
            <?php $view->out( $owa_current['property'] );?>
            <?php if ( $owa_current['domain'] ):?>
            <span class="owa_siteControlDomain"><?php $view->out( $owa_current['domain'] );?></span>
            <?php endif;?>
        </div>
        <i class="fa fa-caret-down owa_siteControlCaret" aria-hidden="true"></i>
    </div>

    <div id="owa_siteControlPanel" class="owa_siteControlPanel" hidden>

        <div class="owa_siteControlColumn owa_siteControlOrgs">
            <div class="owa_siteControlColumnHead">Organization</div>
            <ul>
                <li class="owa_siteControlItem is-selected">
                    <span class="owa_siteControlItemName"><?php $view->out( $owa_hierarchy['organization']['name'] ?? '' );?></span>
                    <?php foreach ( \OWA\Core\Navigation::links( 'nav_site_control', array(
                        array( 'id' => 'edit', 'label' => 'edit', 'capability' => 'edit_settings',
                               'href' => $view->makeLink( array( 'do' => 'base.organizationProfile' ) ) ),
                    ), 'organization', '' ) as $owa_action ):?>
                    <a class="owa_siteControlEdit" href="<?php $view->safeHref( $owa_action['href'] );?>"><?php $view->out( $owa_action['label'] );?></a>
                    <?php endforeach;?>
                </li>
            </ul>
        </div>

        <div class="owa_siteControlColumn owa_siteControlProperties">
            <div class="owa_siteControlColumnHead">Properties<?php foreach ( \OWA\Core\Navigation::links( 'nav_site_control', array(
                array( 'id' => 'add', 'label' => 'add new', 'capability' => 'edit_sites',
                       'href' => $view->makeLink( array( 'do' => 'base.propertyProfile' ) ) ),
            ), 'properties', '' ) as $owa_action ):?><a class="owa_siteControlAdd" href="<?php $view->safeHref( $owa_action['href'] );?>"><?php $view->out( $owa_action['label'] );?></a><?php endforeach;?></div>
            <ul>
            <?php foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ):?>
                <li class="owa_siteControlItem<?php echo $owa_i === $owa_current['index'] ? ' is-selected' : '';?>">
                    <a class="owa_siteControlSelect" href="<?php echo $view->makeLink( array(
                        'do'         => 'base.report',
                        'reportId'   => 'dashboard',
                        'propertyId' => $owa_p['id'],
                    ), true );?>">
                        <span class="owa_siteControlItemName"><?php $view->out( $owa_p['name'] );?></span>
                        <?php if ( $owa_p['domain'] ):?>
                        <span class="owa_siteControlDomain"><?php $view->out( $owa_p['domain'] );?></span>
                        <?php endif;?>
                    </a>
                    <?php if ( $owa_p['id'] ):?>
                    <?php foreach ( \OWA\Core\Navigation::links( 'nav_site_control', array(
                        array( 'id' => 'edit', 'label' => 'edit', 'capability' => 'edit_sites',
                               'href' => $view->makeLink( array( 'do' => 'base.propertyProfile', 'propertyId' => $owa_p['id'] ) ) ),
                    ), 'property', (string) $owa_p['id'] ) as $owa_action ):?>
                    <a class="owa_siteControlEdit" href="<?php $view->safeHref( $owa_action['href'] );?>"><?php $view->out( $owa_action['label'] );?></a>
                    <?php endforeach;?>
                    <?php endif;?>
                </li>
            <?php endforeach;?>
            </ul>
        </div>

    </div>
</div>
