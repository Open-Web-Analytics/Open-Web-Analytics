<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The Observation Profiles of one Property, each leading to its own screens.
 */
?>
<div class="panel_headline">Observation Profiles</div>
<div id="panel">
<div class="owa_panelIntro">An Observation Profile is one way of observing this Property
&mdash; a website or an app &mdash; and carries its own tracking id. Reports show every
Profile of the Property together; add the Profile ID dimension to a report to break it
out by Profile.</div>

<fieldset>

    <legend>
        Observation Profiles <span class="legend_link">(<a href="<?php echo $view->makeLink( array(
            'do' => 'base.sitesProfile', 'propertyId' => $view->propertyId ) );?>">Add New Observation Profile</a>)</span>
    </legend>

<?php if ( empty( $view->profiles ) ):?>

    <div class="owa_emptyState">
        <p>This Property has no Observation Profiles, so it is not collecting anything.</p>
    </div>

<?php else:?>

    <table class="management owa_propertyProfiles">
        <thead>
            <tr>
                <th>Name</th>
                <th>Tracking ID</th>
                <th>Observes</th>
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
                    <?php $view->out( $owa_is_app ? 'App' : 'Website' );?>
                    <span class="owa_siteControlDomain"><?php $view->out( $owa_is_app
                        ? ( $owa_prof['app_id'] ?? '' ) : ( $owa_prof['domain'] ?? '' ) );?></span>
                </td>
                <td class="owa_propertyProfilesActions">
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesProfile',
                        'siteId' => $owa_prof['site_id'], 'edit' => true ) );?>">Details</a>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.profileSettings',
                        'siteId' => $owa_prof['site_id'] ) );?>">Observation Settings</a>
                    <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesInvocation',
                        'siteId' => $owa_prof['site_id'] ) );?>">Tracking Tag</a>
                </td>
            </tr>
        <?php endforeach;?>
        </tbody>
    </table>

<?php endif;?>

</fieldset>
</div>
