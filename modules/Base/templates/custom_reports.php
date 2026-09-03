<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * The custom report roster: every report the reader may see listed.
 *
 * Ownership decides what is LISTED, not what may be opened. An admin sees every
 * report; everyone else sees the ones they created. A report reached by its URL
 * renders for anyone who may view reports, which is what makes these shareable.
 */
$owa_reports   = (array) $view->get('custom_reports');
$owa_sees_all  = (bool) $view->get('sees_all');
$owa_author    = (bool) $view->get('may_author');
$owa_me        = (string) $view->get('current_user_id');
?>

<?php if ( $owa_reports ): ?>

<div class="owa_reportSectionContent">
<table class="management owa_customReportRoster">
    <thead>
        <tr>
            <?php
                /*
                 * Sortable headings.
                 *
                 * Each links to sorting BY ITSELF; the one already active links
                 * to the opposite direction, which is what makes a second click
                 * reverse rather than do nothing. The arrow marks which column
                 * the order is actually by -- without it a sorted list and an
                 * unsorted one look identical.
                 *
                 * Sorting is server-side and on the URL, so the order survives
                 * a reload and travels with a link.
                 */
                $owa_sort = (string) $view->get('roster_sort');
                $owa_desc = (bool) $view->get('roster_desc');

                $owa_heading = function ( $key, $label ) use ( $view, $owa_sort, $owa_desc ) {

                    $active = ( $owa_sort === $key );

                    // Clicking the active column reverses it; clicking another
                    // starts that column in its own natural direction.
                    $next = $active ? ! $owa_desc : ( $key === 'updated' );

                    $url = $view->makeLink( array(
                        'do'         => 'base.customReports',
                        'rosterSort' => $key,
                        'rosterDesc' => $next ? '1' : '0',
                    ) );

                    printf(
                        '<th class="%s"><a href="%s">%s</a>%s</th>',
                        $active ? 'owa_sorted' : '',
                        $url,
                        htmlspecialchars( $label, ENT_QUOTES ),
                        $active
                            ? '<i class="fa ' . ( $owa_desc ? 'fa-caret-down' : 'fa-caret-up' )
                              . ' owa_sortIndicator"></i>'
                            : ''
                    );
                };
            ?>
            <?php $owa_heading( 'name', 'Report' ); ?>
            <?php $owa_heading( 'author', 'Created by' ); ?>
            <?php $owa_heading( 'updated', 'Last updated' ); ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $owa_reports as $owa_report ):
        /*
         * A reader may edit their own report; a reader who may see everyone's
         * may edit everyone's. Asked per row rather than once, because the
         * roster of an admin mixes both.
         */
        $owa_mine = $owa_sees_all || ( (string) $owa_report['user_id'] === $owa_me && $owa_me !== '' );
    ?>
        <tr>
            <td class="data_cell">
                <a href="<?php echo $view->makeLink( array(
                    'do'       => 'base.report',
                    'reportId' => 'custom-' . $owa_report['id'],
                ), true ); ?>"><?php $view->out( $owa_report['name'] ); ?></a>
            </td>

            <td class="data_cell"><?php $view->out( $owa_report['user_id'] ); ?></td>

            <td class="data_cell">
                <?php $owa_when = (int) $owa_report['last_updated_timestamp']; ?>
                <?php $view->out( $owa_when ? date( 'M j, Y g:i a', $owa_when ) : '' ); ?>
            </td>

            <td class="data_cell owa_rosterAction">
                <?php if ( $owa_author && $owa_mine ): ?>
                <a href="<?php echo $view->makeLink( array(
                    'do' => 'base.customReportEdit',
                    'customReportId' => $owa_report['id'],
                ) ); ?>">Edit</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php else: ?>
<div class="owa_reportSectionContent">
    <?php
    /*
     * This roster serves both kinds. A visualization is not a custom report --
     * it computes rather than configures, and they are listed separately for
     * exactly that reason -- so the empty state has to say which one is empty,
     * and point at the right builder.
     */
    $owa_isViz  = ( $view->roster_type ?? '' ) === 'visualization';
    $owa_noun   = $owa_isViz ? 'visualizations' : 'custom reports';
    $owa_builder = $owa_isViz ? 'base.visualizationEdit' : 'base.customReportEdit';
    ?>
    <?php if ( $owa_author ): ?>
        No <?php $view->out( $owa_noun );?> yet.
        <a href="<?php echo $view->makeLink( array( 'do' => $owa_builder ) ); ?>">Build one</a>.
    <?php else: ?>
        <?php
            /*
             * Said differently for a reader who cannot author one: "build one"
             * would point at a screen they are not allowed to open.
             */
        ?>
        No <?php $view->out( $owa_noun );?> have been shared with you yet.
    <?php endif; ?>
</div>
<?php endif; ?>
