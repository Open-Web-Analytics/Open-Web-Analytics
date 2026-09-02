<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The site control: what is being reported on, and the navigation for changing it.
 *
 * It sits above the left nav because it scopes everything in that nav -- every
 * report below it is a report OF this Profile. The old control was a select in
 * the content column, which put the thing every report depends on beside the
 * reports rather than above them.
 *
 * Collapsed it answers "where am I": Organization, Property, then the Profile
 * and its tracker id. The id is shown because it is what someone opens this to
 * find -- it is the value that goes in a tag.
 *
 * Expanded it is three columns, one per tier, filled left to right. Column 1
 * anticipates several Organizations; there is one today and it is not a list
 * yet, but the column is where it will go.
 */
$owa_hierarchy    = $view->site_hierarchy ?: array( 'organization' => array(), 'properties' => array() );
$owa_current_site = $view->params['siteId'] ?? '';

$owa_current = array( 'property' => '', 'profile' => '', 'site_id' => $owa_current_site );

foreach ( $owa_hierarchy['properties'] as $owa_p ) {
    foreach ( $owa_p['profiles'] as $owa_prof ) {
        if ( $owa_prof['site_id'] === $owa_current_site ) {
            $owa_current['property'] = $owa_p['name'];
            $owa_current['profile']  = $owa_prof['name'];
        }
    }
}
?>
<div id="owa_siteControl" class="owa_siteControl">

    <div id="owa_siteControlSummary" class="owa_siteControlSummary" role="button" tabindex="0"
         aria-expanded="false" aria-controls="owa_siteControlPanel">
        <div class="owa_siteControlOrg"><?php $view->out( $owa_hierarchy['organization']['name'] ?? '' );?></div>
        <div class="owa_siteControlProperty"><?php $view->out( $owa_current['property'] );?></div>
        <div class="owa_siteControlProfile">
            <?php $view->out( $owa_current['profile'] );?>
            <span class="owa_siteControlId"><?php $view->out( $owa_current['site_id'] );?></span>
        </div>
        <i class="fa fa-caret-down owa_siteControlCaret" aria-hidden="true"></i>
    </div>

    <div id="owa_siteControlPanel" class="owa_siteControlPanel" hidden>

        <div class="owa_siteControlColumn owa_siteControlOrgs">
            <div class="owa_siteControlColumnHead">Organization</div>
            <ul>
                <li class="owa_siteControlItem is-selected">
                    <span class="owa_siteControlItemName"><?php $view->out( $owa_hierarchy['organization']['name'] ?? '' );?></span>
                    <?php if ( $view->getCurrentUser()->isCapable('edit_settings') ):?>
                    <a class="owa_siteControlEdit" href="<?php echo $view->makeLink( array( 'do' => 'base.organizationProfile' ) );?>">edit</a>
                    <?php endif;?>
                </li>
            </ul>
        </div>

        <div class="owa_siteControlColumn owa_siteControlProperties">
            <div class="owa_siteControlColumnHead">Properties<?php if ( $view->getCurrentUser()->isCapable('edit_sites') ):?><a class="owa_siteControlAdd" href="<?php echo $view->makeLink( array( 'do' => 'base.propertyProfile' ) );?>">add new</a><?php endif;?></div>
            <ul>
            <?php foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ):?>
                <li class="owa_siteControlItem<?php echo $owa_p['name'] === $owa_current['property'] ? ' is-selected' : '';?>"
                    data-property-index="<?php echo (int) $owa_i;?>">
                    <span class="owa_siteControlItemName">
                        <?php $view->out( $owa_p['name'] );?>
                        <?php if ( $owa_p['domain'] ):?>
                        <span class="owa_siteControlDomain"><?php $view->out( $owa_p['domain'] );?></span>
                        <?php endif;?>
                    </span>
                    <?php if ( $owa_p['id'] && $view->getCurrentUser()->isCapable('edit_sites') ):?>
                    <a class="owa_siteControlEdit" href="<?php echo $view->makeLink( array( 'do' => 'base.propertyProfile', 'propertyId' => $owa_p['id'] ) );?>">edit</a>
                    <?php endif;?>
                </li>
            <?php endforeach;?>
            </ul>
        </div>

        <div class="owa_siteControlColumn owa_siteControlProfiles">
            <div class="owa_siteControlColumnHead">Observation Profiles<?php if ( $view->getCurrentUser()->isCapable('edit_sites') ):?><a class="owa_siteControlAdd" href="<?php echo $view->makeLink( array( 'do' => 'base.sitesProfile' ) );?>">add new</a><?php endif;?></div>
            <?php foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ):?>
            <ul class="owa_siteControlProfileList" data-property-index="<?php echo (int) $owa_i;?>"
                <?php echo $owa_p['name'] === $owa_current['property'] ? '' : 'hidden';?>>
                <?php foreach ( $owa_p['profiles'] as $owa_prof ):?>
                <li class="owa_siteControlItem<?php echo $owa_prof['site_id'] === $owa_current_site ? ' is-selected' : '';?>">
                    <a class="owa_siteControlSelect" href="<?php echo $view->makeLink( array( 'siteId' => $owa_prof['site_id'] ), true );?>">
                        <span class="owa_siteControlItemName"><?php $view->out( $owa_prof['name'] );?></span>
                        <span class="owa_siteControlId"><?php $view->out( $owa_prof['site_id'] );?></span>
                    </a>
                    <?php
                        /*
                         * Goal events are per Profile, which is why they are
                         * reached from here rather than from an entry in the
                         * settings nav -- that entry had no way to say WHICH
                         * Profile's it meant.
                         */
                    ?>
                    <?php if ( $view->getCurrentUser()->isCapable('edit_settings') ):?>
                    <a class="owa_siteControlEdit" href="<?php echo $view->makeLink( array( 'do' => 'base.goalEvents', 'siteId' => $owa_prof['site_id'] ) );?>">goal events</a>
                    <?php endif;?>
                    <?php if ( $view->getCurrentUser()->isCapable('edit_sites') ):?>
                    <a class="owa_siteControlEdit" href="<?php echo $view->makeLink( array( 'do' => 'base.sitesProfile', 'siteId' => $owa_prof['site_id'], 'edit' => true ) );?>">edit</a>
                    <?php endif;?>
                </li>
                <?php endforeach;?>
            </ul>
            <?php endforeach;?>
        </div>
    </div>
</div>
