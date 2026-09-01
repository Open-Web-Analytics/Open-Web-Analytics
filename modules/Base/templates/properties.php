<?php /** @var \OWA\Core\ViewScope $view */ ?>
<P>A Property is a website or app. The Observation Profiles beneath it are the
ways it is being watched &mdash; each Profile carries its own tracking id, so a
site tracked two ways has one Property and two Profiles.</P>

<?php foreach ( $view->properties as $property ):?>
<div class="owa_reportSectionContent" style="min-width:500px;">
    <TABLE width="" border="0" class="management">
        <tbody>
            <TR>
                <td valign="top" style="min-width:300px;">
                    <span style="font-size:14px; font-weight:bold;"><?php $view->out( $property['name'] );?></span><BR>
                    <?php if ( $property['domain'] ):?>
                    <span class="externalUrl"><?php $view->out( $property['domain'] );?></span><BR>
                    <?php endif;?>
                    <?php if ( $property['description'] ):?>
                    <span class="info_text"><?php $view->out( $property['description'] );?></span><BR>
                    <?php endif;?>

                    <BR>
                    <form method="POST" action="<?php echo $view->makeLink( array( 'do' => 'base.propertyEdit' ) );?>">
                        <input type="hidden" name="<?php echo $view->getNs();?>propertyId" value="<?php $view->out( $property['id'] );?>">
                        <?php echo $view->createNonceFormField( 'base.propertyEdit' );?>
                        <span class="inline_h3">Name</span>
                        <input class="owa_largeFormField" type="text" size="30"
                               name="<?php echo $view->getNs();?>name" value="<?php $view->out( $property['name'] );?>">
                        <input type="submit" value="Rename">
                    </form>

                    <div style="margin-top:10px;">
                    <?php if ( $property['profiles'] ):?>
                        <span class="inline_h3">Observation Profiles</span>
                        <ul>
                        <?php foreach ( $property['profiles'] as $profile ):?>
                            <li>
                                <a href="<?php echo $view->makeLink( array( 'do' => 'base.report', 'reportId' => 'dashboard', 'siteId' => $profile->get('site_id') ), true );?>"><?php $view->out( $profile->get('name') );?></a>
                                &mdash; <span class="info_text"><?php $view->out( $profile->get('site_id') );?></span>
                                | <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesProfile', 'siteId' => $profile->get('site_id'), 'edit' => true ) );?>">Edit</a>
                                | <a href="<?php echo $view->makeLink( array( 'do' => 'base.sitesInvocation', 'siteId' => $profile->get('site_id') ) );?>">Tracking Code</a>
                            </li>
                        <?php endforeach;?>
                        </ul>
                    <?php else:?>
                        <span class="info_text">No Observation Profiles yet &mdash; nothing is being tracked for this Property.</span>
                    <?php endif;?>
                    </div>
                </td>
            </TR>
        </tbody>
    </TABLE>
</div>
<?php endforeach;?>

<?php if ( $view->unassigned_profiles ):?>
<div class="owa_reportSectionContent" style="min-width:500px;">
    <span style="font-size:14px; font-weight:bold;">Unassigned Profiles</span><BR>
    <span class="info_text">These are being tracked but belong to no Property. They still
    collect data; they simply have nothing to group them under.</span>
    <ul>
    <?php foreach ( $view->unassigned_profiles as $profile ):?>
        <li>
            <a href="<?php echo $view->makeLink( array( 'do' => 'base.report', 'reportId' => 'dashboard', 'siteId' => $profile->get('site_id') ), true );?>"><?php $view->out( $profile->get('name') );?></a>
            &mdash; <span class="info_text"><?php $view->out( $profile->get('domain') );?></span>
        </li>
    <?php endforeach;?>
    </ul>
</div>
<?php endif;?>
