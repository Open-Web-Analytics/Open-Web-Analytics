<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The navigation: the rail of sections, and beside it the column of the
 * current section's pages. Report screens (report.php) and settings screens
 * (options_hierarchy.php) both draw it.
 *
 *   $owa_rail   Template::navRail()
 *   $owa_column array( 'title' => ..., 'groups' => array( array( 'heading', 'items' ) ) ),
 *               or null for a section with no pages under it (Dashboard,
 *               Realtime), which then has the full width
 *
 * Hovering a section that is not current shows its pages as a flyout, so a
 * report in another section is one click away. The chevron at the top of the
 * column hides it, and the choice is remembered (owa.reportnav.js).
 *
 * On a narrow screen all of it is inside the slide-out (owa_reportNavRail):
 * no flyouts, and the column always shows.
 */
$owa_rail   = $owa_rail ?? array();
$owa_column = $owa_column ?? null;
?>
<div class="owa_navShell<?php echo $owa_column ? ' has-column' : '';?>" id="owa_navShell">
    <nav class="owa_rail" aria-label="Sections">
        <ul class="owa_railList">
        <?php foreach ( $owa_rail as $owa_section ):?>
            <li class="owa_railEntry<?php echo $owa_section['foot'] ? ' is-foot' : '';?>">
                <a class="owa_railItem<?php echo $owa_section['current'] ? ' is-current' : '';?>"
                   id="owa_railItem_<?php $view->out( preg_replace( '/[^A-Za-z0-9_-]/', '', $owa_section['id'] ) );?>"
                   <?php echo $owa_section['current'] ? 'aria-current="page"' : '';?>
                   href="<?php $view->safeHref( $owa_section['href'] );?>"><i class="owa_railIcon <?php $view->out( $owa_section['icon'] );?>" aria-hidden="true"></i><span class="owa_railLabel"><?php $view->out( $owa_section['label'] );?></span></a>
                <?php if ( ! $owa_section['current'] && $owa_section['items'] ):?>
                <div class="owa_railFlyout">
                    <div class="owa_railFlyoutTitle"><?php $view->out( $owa_section['label'] );?></div>
                    <ul>
                    <?php foreach ( $owa_section['items'] as $owa_item ):?>
                        <li><a class="owa_navColumnItem" href="<?php $view->safeHref( $owa_item['href'] );?>"><?php $view->out( $owa_item['label'] );?></a></li>
                    <?php endforeach;?>
                    </ul>
                </div>
                <?php endif;?>
            </li>
        <?php endforeach;?>
        </ul>
    </nav>
    <?php if ( $owa_column ):?>
    <nav class="owa_navColumn" id="owa_navColumn" aria-label="<?php $view->out( $owa_column['title'] );?>">
        <div class="owa_navColumnHead">
            <span class="owa_navColumnTitle"><?php $view->out( $owa_column['title'] );?></span>
            <button type="button" class="owa_navColumnToggle" aria-controls="owa_navColumnBody" aria-expanded="true"
                    aria-label="Hide this column" title="Hide this column"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
        </div>
        <div class="owa_navColumnBody" id="owa_navColumnBody">
        <?php foreach ( $owa_column['groups'] as $owa_group ):?>
            <?php if ( $owa_group['heading'] !== '' ):?>
            <div class="owa_navColumnHeading"><?php $view->out( $owa_group['heading'] );?></div>
            <?php endif;?>
            <ul>
            <?php foreach ( $owa_group['items'] as $owa_item ):?>
                <li><a class="owa_navColumnItem<?php echo $owa_item['current'] ? ' is-current' : '';?>"
                       <?php echo $owa_item['current'] ? 'aria-current="page"' : '';?>
                       href="<?php $view->safeHref( $owa_item['href'] );?>"><?php $view->out( $owa_item['label'] );?></a></li>
            <?php endforeach;?>
            </ul>
        <?php endforeach;?>
        </div>
    </nav>
    <script>
    // Before the first paint, so a hidden column does not show and then go.
    try {
        if ( window.localStorage.getItem( 'owa_navColumnHidden' ) === '1' ) {
            document.getElementById( 'owa_navShell' ).classList.add( 'is-collapsed' );
        }
    } catch ( e ) { /* no storage: the column shows */ }
    </script>
    <?php endif;?>
</div>
