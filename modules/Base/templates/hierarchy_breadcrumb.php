<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The tile's contents on one line, above the page heading.
 *
 * The tile says where you are, but it is in the other column and a settings
 * screen is where being sure matters most -- these forms change one Property or
 * one Profile, and the id is the only thing that tells two similarly named
 * Profiles apart. Repeating it here means the answer is on the same line of
 * sight as the thing being edited.
 *
 * Grey and small: it is context, not the title.
 *
 * It stops at the tier the SCREEN is about. Organization Details is about an
 * Organization, so naming a Property and a Profile under it would describe a
 * scope the form does not touch -- and on Property Access, which edits grants
 * covering every Profile, trailing one Profile's id would say the opposite of
 * what the screen does.
 */
$owa_h = $view->site_hierarchy ?: array( 'organization' => array(), 'properties' => array() );
$owa_current_site = $view->params['siteId'] ?? '';

$owa_crumbs = array();

if ( ! empty( $owa_h['organization']['name'] ) ) {
    $owa_crumbs[] = array( 'label' => $owa_h['organization']['name'], 'note' => '' );
}

$owa_tier = (int) ( $view->hierarchy_tier ?: 3 );

foreach ( $owa_h['properties'] as $owa_p ) {
    foreach ( $owa_p['profiles'] as $owa_prof ) {

        if ( $owa_prof['site_id'] !== $owa_current_site ) {
            continue;
        }

        if ( $owa_tier >= 2 ) {
            $owa_crumbs[] = array( 'label' => $owa_p['name'], 'note' => $owa_p['domain'] );
        }

        if ( $owa_tier >= 3 ) {
            $owa_crumbs[] = array( 'label' => $owa_prof['name'], 'note' => $owa_prof['site_id'] );
        }
    }
}
?>
<?php if ( $owa_crumbs ):?>
<div class="owa_hierarchyCrumb">
    <?php foreach ( $owa_crumbs as $owa_i => $owa_crumb ):?><?php if ( $owa_i ):?><span class="owa_hierarchyCrumbSep">&rsaquo;</span><?php endif;?><span class="owa_hierarchyCrumbItem"><?php $view->out( $owa_crumb['label'] );?><?php if ( $owa_crumb['note'] ):?> <span class="owa_hierarchyCrumbNote">(<?php $view->out( $owa_crumb['note'] );?>)</span><?php endif;?></span><?php endforeach;?>
</div>
<?php endif;?>
