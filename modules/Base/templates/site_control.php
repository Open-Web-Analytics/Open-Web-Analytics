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

$owa_current = array( 'index' => null, 'property' => '', 'property_id' => '', 'profile' => '', 'site_id' => $owa_current_site );

foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ) {
    foreach ( $owa_p['profiles'] as $owa_prof ) {
        if ( $owa_prof['site_id'] === $owa_current_site ) {
            $owa_current['index']       = $owa_i;
            $owa_current['property']    = $owa_p['name'];
            $owa_current['property_id'] = (string) $owa_p['id'];
            $owa_current['profile']     = $owa_prof['name'];
        }
    }
}

/*
 * A Property named by the screen wins over the current Profile's.
 *
 * A Property with no Profiles has no site to be found through, so the
 * screens about it (its Details, the form adding its first Profile) would
 * otherwise show whichever Property the fallback site belongs to. The
 * Profile line is cleared when that site is not one of its Profiles.
 */
$owa_context_property = (string) ( $view->params['propertyId'] ?? '' );

if ( $owa_context_property !== '' && $owa_context_property !== $owa_current['property_id'] ) {
    foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ) {
        if ( (string) $owa_p['id'] === $owa_context_property ) {
            $owa_current = array( 'index' => $owa_i, 'property' => $owa_p['name'], 'property_id' => $owa_context_property,
                                  'profile' => '', 'site_id' => '' );
        }
    }
}

/*
 * "add new" in the Profiles column adds to the Property selected beside it.
 * Each Property row carries its own link; the script swaps it in when a row
 * is chosen.
 */
$owa_add_profile_href = function ( $propertyId ) use ( $view ) {
    return $view->makeLink( $propertyId !== ''
        ? array( 'do' => 'base.sitesProfile', 'propertyId' => $propertyId )
        : array( 'do' => 'base.sitesProfile' ) );
};
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
                <li class="owa_siteControlItem<?php echo $owa_i === $owa_current['index'] ? ' is-selected' : '';?>"
                    data-property-index="<?php echo (int) $owa_i;?>"
                    data-add-profile-href="<?php $view->safeHref( $owa_add_profile_href( (string) $owa_p['id'] ) );?>">
                    <span class="owa_siteControlItemName">
                        <?php $view->out( $owa_p['name'] );?>
                        <?php if ( $owa_p['domain'] ):?>
                        <span class="owa_siteControlDomain"><?php $view->out( $owa_p['domain'] );?></span>
                        <?php endif;?>
                    </span>
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

        <div class="owa_siteControlColumn owa_siteControlProfiles">
            <div class="owa_siteControlColumnHead">Observation Profiles<?php foreach ( \OWA\Core\Navigation::links( 'nav_site_control', array(
                array( 'id' => 'add', 'label' => 'add new', 'capability' => 'edit_sites',
                       'href' => $owa_add_profile_href( $owa_current['property_id'] ) ),
            ), 'profiles', $owa_current['property_id'] ) as $owa_action ):?><a class="owa_siteControlAdd owa_siteControlAddProfile" href="<?php $view->safeHref( $owa_action['href'] );?>"><?php $view->out( $owa_action['label'] );?></a><?php endforeach;?></div>
            <?php foreach ( $owa_hierarchy['properties'] as $owa_i => $owa_p ):?>
            <ul class="owa_siteControlProfileList" data-property-index="<?php echo (int) $owa_i;?>"
                <?php echo $owa_i === $owa_current['index'] ? '' : 'hidden';?>>
                <?php foreach ( $owa_p['profiles'] as $owa_prof ):?>
                <li class="owa_siteControlItem<?php echo $owa_prof['site_id'] === $owa_current_site ? ' is-selected' : '';?>">
                    <?php
                        /*
                         * NAMES ITS DESTINATION, and that is not decoration.
                         *
                         * This was makeLink( array( 'siteId' => ... ) ) with no
                         * `do`, which produced index.php?siteId=X. That URL
                         * reaches the default action, and the default action
                         * reads `site_id` -- the underscored spelling -- not
                         * `siteId`. So the chosen Profile was dropped on the
                         * floor and the fallback picked the first site the user
                         * may see: every Profile in the column led to the same
                         * one, which is a site switcher that does not switch.
                         *
                         * It already LANDED on the dashboard, by falling
                         * through to the default; saying so explicitly changes
                         * nothing about where you end up and everything about
                         * which Profile you end up on.
                         */
                    ?>
                    <a class="owa_siteControlSelect" href="<?php echo $view->makeLink( array(
                        'do'       => 'base.report',
                        'reportId' => 'dashboard',
                        'siteId'   => $owa_prof['site_id'],
                    ), true );?>">
                        <span class="owa_siteControlItemName"><?php $view->out( $owa_prof['name'] );?></span>
                        <span class="owa_siteControlId"><?php $view->out( $owa_prof['site_id'] );?></span>
                    </a>
                    <?php
                        /*
                         * NO GOAL EVENTS LINK HERE. It used to sit on this row,
                         * with a comment saying goal events are per Profile.
                         * They are not -- owa_goal_event is keyed on
                         * property_id, and the settings nav lists Goal Events
                         * under the PROPERTY tier beside Property Access.
                         *
                         * So this row offered a property-scoped screen from a
                         * Profile, implying each Profile had its own goals. One
                         * "edit" is enough: it opens the settings screen, whose
                         * left-hand nav carries Goal Events at the tier that
                         * actually owns them.
                         */
                    ?>
                    <?php foreach ( \OWA\Core\Navigation::links( 'nav_site_control', array(
                        array( 'id' => 'edit', 'label' => 'edit', 'capability' => 'edit_sites',
                               'href' => $view->makeLink( array( 'do' => 'base.sitesProfile', 'siteId' => $owa_prof['site_id'], 'edit' => true ) ) ),
                    ), 'profile', (string) $owa_prof['site_id'] ) as $owa_action ):?>
                    <a class="owa_siteControlEdit" href="<?php $view->safeHref( $owa_action['href'] );?>"><?php $view->out( $owa_action['label'] );?></a>
                    <?php endforeach;?>
                </li>
                <?php endforeach;?>
            </ul>
            <?php endforeach;?>
        </div>
    </div>
</div>
