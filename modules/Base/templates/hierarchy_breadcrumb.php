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
$owa_current_site     = (string) ( $view->params['siteId'] ?? '' );
$owa_current_property = (string) ( $view->params['propertyId'] ?? '' );

$owa_crumbs = array();

/*
 * Tier 0 is install-wide -- Main Configuration, Modules -- so it names the
 * installation rather than an Organization. Saying "My Organization" above a
 * screen whose settings apply to every Organization would be wrong in exactly
 * the way the tiering is meant to prevent.
 *
 * The label is not always "Instance": base.myProfile is tier 0 for the same
 * reason -- it belongs to no Organization -- but is about the person, so it
 * supplies its own. See OptionsHierarchy, which defaults it.
 */
if ( (int) $view->hierarchy_tier === 0 ) {

    $owa_crumbs[] = array( 'label' => (string) $view->hierarchy_root_label, 'note' => '',
                           'group' => (string) $view->hierarchy_root_label );

} elseif ( ! empty( $owa_h['organization']['name'] ) ) {

    $owa_crumbs[] = array( 'label' => $owa_h['organization']['name'], 'note' => '',
                           'group' => 'Organization' );
}

$owa_tier = $view->hierarchy_tier === 0 ? 0 : (int) ( $view->hierarchy_tier ?: 3 );

/*
 * The Property the screen names, or else the one its Profile belongs to. The
 * Profile crumb only on a Profile's own screens.
 */
foreach ( $owa_h['properties'] as $owa_p ) {

    $owa_site_ids = array_column( $owa_p['profiles'], 'site_id' );

    $owa_is_current = $owa_current_property !== ''
        ? (string) $owa_p['id'] === $owa_current_property
        : $owa_current_site !== '' && in_array( $owa_current_site, $owa_site_ids, true );

    if ( ! $owa_is_current ) {
        continue;
    }

    if ( $owa_tier >= 2 ) {
        $owa_crumbs[] = array( 'label' => $owa_p['name'], 'note' => $owa_p['domain'],
                               'group' => 'Property' );
    }

    foreach ( $owa_p['profiles'] as $owa_prof ) {

        if ( $owa_tier >= 3 && $owa_prof['site_id'] === $owa_current_site ) {
            $owa_crumbs[] = array( 'label' => $owa_prof['name'], 'note' => $owa_prof['site_id'],
                                   'group' => 'Observation Profile' );
        }
    }
}

/*
 * Each crumb links to the first screen of its tier's nav group -- the same
 * links the nav draws, so a crumb never offers a screen the reader cannot
 * open. The tier-0 root label is that group's name ("Instance", "My
 * Preferences"). No link when that screen is the one being shown, or when the
 * screen supplied no nav.
 */
$owa_nav        = (array) $view->get( 'hierarchy_nav' );
$owa_current_do = $view->params['do'] ?? '';

foreach ( $owa_crumbs as $owa_i => $owa_crumb ) {

    $owa_crumbs[ $owa_i ]['href'] = '';

    foreach ( (array) ( $owa_nav[ $owa_crumb['group'] ] ?? array() ) as $owa_item ) {

        if ( $owa_item['capability'] && ! $view->getCurrentUser()->isCapable( $owa_item['capability'] ) ) {
            continue;
        }

        if ( $owa_item['do'] !== $owa_current_do ) {

            $owa_crumbs[ $owa_i ]['href'] = $view->makeLink(
                array_merge( array( 'do' => $owa_item['do'] ), $owa_item['params'] ) );
        }

        break;
    }
}
?>
<?php if ( $owa_crumbs ):?>
<div class="owa_hierarchyCrumb">
    <?php foreach ( $owa_crumbs as $owa_i => $owa_crumb ):?><?php if ( $owa_i ):?><span class="owa_hierarchyCrumbSep">&rsaquo;</span><?php endif;?><span class="owa_hierarchyCrumbItem"><?php if ( $owa_crumb['href'] ):?><a href="<?php echo $owa_crumb['href'];?>"><?php $view->out( $owa_crumb['label'] );?></a><?php else:?><?php $view->out( $owa_crumb['label'] );?><?php endif;?><?php if ( $owa_crumb['note'] ):?> <span class="owa_hierarchyCrumbNote">(<?php $view->out( $owa_crumb['note'] );?>)</span><?php endif;?></span><?php endforeach;?>
</div>
<?php endif;?>
