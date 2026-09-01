<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The hierarchy's settings nav, under the site control.
 *
 * Grouped by tier so each screen has a heading that says which thing it is
 * about -- the reason these are separate screens rather than one page with a
 * form per section.
 *
 * The current screen is marked by its action, not by its URL, so a link
 * carrying different params still reads as current.
 */
$owa_current_do = $view->params['do'] ?? '';
?>
<div class="owa_hierarchyNav">
<?php foreach ( (array) $view->hierarchy_nav as $owa_group => $owa_items ):?>
    <div class="owa_hierarchyNavGroup">
        <div class="owa_hierarchyNavHead"><?php $view->out( $owa_group );?></div>
        <ul>
        <?php foreach ( $owa_items as $owa_item ):?>
            <?php if ( ! $owa_item['capability'] || $view->getCurrentUser()->isCapable( $owa_item['capability'] ) ):?>
            <li class="<?php echo $owa_current_do === $owa_item['do'] ? 'is-current' : '';?>">
                <a href="<?php echo $view->makeLink( array_merge( array( 'do' => $owa_item['do'] ), $owa_item['params'] ) );?>"><?php $view->out( $owa_item['label'] );?></a>
            </li>
            <?php endif;?>
        <?php endforeach;?>
        </ul>
    </div>
<?php endforeach;?>
</div>
