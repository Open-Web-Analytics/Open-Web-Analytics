<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The Observation Profiles of one Property, each leading to its own screens.
 */
?>
<h1 class="panel_headline"><?php $view->out( \OWA\Core\CoreAPI::t( 'Observation Profiles' ) );?></h1>
<div id="panel">
<div class="owa_panelIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'Each Observation Profile collects data for this Property under its own tracking id. Reports combine all of the Property\'s Profiles; add the Profile Name dimension to a report to see them separately.' ) );?></div>

<fieldset>

    <legend>
        <?php $view->out( \OWA\Core\CoreAPI::t( 'Observation Profiles' ) );?> <span class="legend_link">(<a href="<?php echo $view->makeLink( array(
            'do' => 'base.sitesProfile', 'propertyId' => $view->propertyId ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Add New Observation Profile' ) );?></a>)</span>
    </legend>

<?php if ( empty( $view->profiles ) ):?>

    <div class="owa_emptyState">
        <p><?php $view->out( \OWA\Core\CoreAPI::t( 'This Property has no Observation Profiles, so it is not collecting anything.' ) );?></p>
    </div>

<?php else:?>

    <table class="management owa_propertyProfiles">
        <thead>
            <tr>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) );?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracking ID' ) );?></th>
                <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Observes' ) );?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $view->profiles as $owa_prof ):?>
            <?php $owa_is_app = ( $owa_prof['stream_type'] ?? '' ) === \OWA\Module\Base\Entity\Site::STREAM_APP;?>
            <tr>
                <td>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesProfile',
                        'siteId' => $owa_prof['site_id'], 'edit' => true ) );?>"><?php $view->out( $owa_prof['name'] );?></a>
                </td>
                <td><code><?php $view->out( $owa_prof['site_id'] );?></code></td>
                <td>
                    <?php $view->out( $owa_is_app ? \OWA\Core\CoreAPI::t( 'App' ) : \OWA\Core\CoreAPI::t( 'Website' ) );?>
                    <span class="owa_siteControlDomain"><?php $view->out( $owa_is_app
                        ? ( $owa_prof['app_id'] ?? '' ) : ( $owa_prof['domain'] ?? '' ) );?></span>
                </td>
                <td class="owa_propertyProfilesActions">
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesProfile',
                        'siteId' => $owa_prof['site_id'], 'edit' => true ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Details' ) );?></a>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.profileSettings',
                        'siteId' => $owa_prof['site_id'] ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Observation Settings' ) );?></a>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesInvocation',
                        'siteId' => $owa_prof['site_id'] ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracking Tag' ) );?></a>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>

<?php endif;?>

</fieldset>
</div>
