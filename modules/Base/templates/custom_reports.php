<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * The custom report roster: every report and funnel the reader may see, in one
 * list with a Type column.
 *
 * Ownership decides what is LISTED, not what may be opened. An admin sees every
 * report; everyone else sees their own and the shared ones. A report reached by
 * its URL renders for anyone who may view reports, which is what makes these
 * shareable.
 */
$owa_reports   = (array) $view->get('custom_reports');
$owa_count     = (int) $view->get('roster_count');
$owa_kinds     = (array) ( $view->get('new_kinds') ?: array() );
$owa_author    = (bool) $view->get('may_author');
$owa_mine      = (bool) $view->get('roster_mine');
$owa_favs      = (bool) $view->get('roster_favorites');
$owa_me        = (string) $view->get('current_user_id');
$owa_sort      = (string) $view->get('roster_sort');
$owa_desc      = (bool) $view->get('roster_desc');
$owa_active    = $owa_favs ? 'favorites' : ( $owa_mine ? 'mine' : 'all' );

/*
 * What every link back to this roster carries, so sorting keeps the filter,
 * filtering keeps nothing it should not, and a star keeps both. add_state on
 * each, or propertyId is dropped and the Property the report links open for is
 * lost.
 */
$owa_state = array(
    'rosterSort'      => $owa_sort,
    'rosterDesc'      => $owa_desc ? '1' : '0',
    'rosterMine'      => $owa_mine ? '1' : '0',
    'rosterFavorites' => $owa_favs ? '1' : '0',
);

$owa_typeLabels = array( 'report' => 'Report' );
$owa_typeIcons  = array( 'report' => 'fas fa-th-large' );

foreach ( $owa_kinds ?: \OWA\Module\Base\Controller\CustomReports::newKinds() as $owa_key => $owa_kind ) {
    $owa_typeLabels[ $owa_key ] = $owa_kind['label'];
    $owa_typeIcons[ $owa_key ]  = $owa_kind['icon'];
}
?>

<div class="owa_reportSectionContent owa_roster">

<?php
    /*
     * Whose reports this is showing: three states of ONE control. They are
     * alternatives -- "my favorites" and "my reports" are different questions.
     *
     * ALWAYS DRAWN, including when the list it produced is empty, or narrowing
     * to "Favorites" with nothing starred would remove the only way back.
     */
    $owa_filterLink = function ( $which ) use ( $view ) {
        return $view->makeLink( array(
            'do'              => 'base.customReports',
            'rosterMine'      => $which === 'mine' ? '1' : '0',
            'rosterFavorites' => $which === 'favorites' ? '1' : '0',
        ), true );
    };
?>
<div class="owa_rosterBar">
    <div class="owa_rosterFilter" role="group" aria-label="Show">
        <?php foreach ( array( 'all' => 'All', 'mine' => 'Just mine', 'favorites' => 'Favorites' ) as $owa_which => $owa_label ): ?>
        <a class="<?php echo $owa_active === $owa_which ? 'owa_rosterFilterActive' : ''; ?>"
           <?php echo $owa_active === $owa_which ? 'aria-current="true"' : ''; ?>
           href="<?php echo $owa_filterLink( $owa_which ); ?>"><?php
            if ( $owa_which === 'favorites' ): ?><i class="fas fa-star" aria-hidden="true"></i><?php endif;
            $view->out( $owa_label ); ?></a>
        <?php endforeach; ?>
    </div>
    <span class="owa_rosterCount"><?php $view->out( $owa_count === 1 ? '1 report' : $owa_count . ' reports' ); ?></span>
</div>

<?php if ( $owa_reports ): ?>

<table class="owa_customReportRoster">
    <thead>
        <tr>
            <th class="owa_rosterStarCell"><span class="owa_srOnly">Favorite</span></th>
            <?php
                /*
                 * Sortable headings, server-side and on the URL, so the order
                 * survives a reload and travels with a link. Each links to
                 * sorting by itself; the active one links to the opposite
                 * direction, and carries the arrow.
                 */
                $owa_heading = function ( $key, $label ) use ( $view, $owa_sort, $owa_desc, $owa_state ) {

                    $active = ( $owa_sort === $key );

                    // Clicking the active column reverses it; clicking another
                    // starts that column in its own natural direction.
                    $next = $active ? ! $owa_desc : ( $key === 'updated' );

                    $url = $view->makeLink( array_merge( $owa_state, array(
                        'do'         => 'base.customReports',
                        'rosterSort' => $key,
                        'rosterDesc' => $next ? '1' : '0',
                    ) ), true );

                    printf(
                        '<th class="%s"%s><a href="%s">%s%s</a></th>',
                        $active ? 'owa_sorted' : '',
                        $active ? ' aria-sort="' . ( $owa_desc ? 'descending' : 'ascending' ) . '"' : '',
                        $url,
                        $active
                            ? '<i class="fas ' . ( $owa_desc ? 'fa-arrow-down' : 'fa-arrow-up' )
                              . ' owa_sortIndicator" aria-hidden="true"></i>'
                            : '',
                        htmlspecialchars( $label, ENT_QUOTES )
                    );
                };

                $owa_heading( 'name', 'Report' );
                $owa_heading( 'type', 'Type' );
                $owa_heading( 'author', 'Created by' );
                $owa_heading( 'updated', 'Last updated' );
            ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $owa_reports as $owa_report ): ?>
        <?php
            $owa_starred = ! empty( $owa_report['is_favorite'] );
            $owa_kind    = (string) ( $owa_report['kind'] ?? 'report' );
            // add_nonce: the toggle writes, and demands one.
            $owa_starUrl = $view->makeLink( array_merge( $owa_state, array(
                'do'             => 'base.customReportMarkFavorite',
                'customReportId' => $owa_report['id'],
                'returnTo'       => 'roster',
            ) ), true, '', false, true );
        ?>
        <tr>
            <td class="owa_rosterStarCell">
                <a class="owa_rosterStar<?php echo $owa_starred ? ' is-on' : ''; ?>"
                   href="<?php $view->out( $owa_starUrl, false ); ?>"
                   aria-label="<?php echo $owa_starred ? 'Remove from favorites' : 'Add to favorites'; ?>"
                   title="<?php echo $owa_starred ? 'Remove from favorites' : 'Add to favorites'; ?>"><i
                   class="<?php echo $owa_starred ? 'fas' : 'far'; ?> fa-star" aria-hidden="true"></i></a>
            </td>
            <td class="owa_rosterName">
                <a href="<?php echo $view->makeLink( array(
                    'do'       => 'base.report',
                    'reportId' => 'custom-' . $owa_report['id'],
                ), true ); ?>"><?php $view->out( $owa_report['name'] ); ?></a>
                <?php if ( ! empty( $owa_report['is_shared'] ) ): ?>
                    <span class="owa_rosterTag" title="In everyone's list">Shared</span>
                <?php endif; ?>
                <?php if ( ! empty( $owa_report['invalid'] ) ): ?>
                    <span class="owa_rosterTag is-warn"
                          title="<?php $view->out( $owa_report['invalid'] ); ?>">Can't be drawn</span>
                <?php endif; ?>
            </td>
            <td class="owa_rosterType">
                <i class="<?php $view->out( $owa_typeIcons[ $owa_kind ] ?? '' ); ?>" aria-hidden="true"></i><?php
                $view->out( $owa_typeLabels[ $owa_kind ] ?? $owa_kind ); ?>
            </td>
            <td>
                <?php
                    $owa_author = (string) $owa_report['user_id'];
                    $view->out( $owa_author !== '' && $owa_author === $owa_me ? 'you' : $owa_author );
                ?>
            </td>
            <td>
                <?php $owa_when = (int) $owa_report['last_updated_timestamp']; ?>
                <?php $view->out( $owa_when ? date( 'M j, Y, g:i A', $owa_when ) : '' ); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p class="owa_rosterNote">Favorites sort to the top and appear in the column on the left. A shared report is in everyone's list; any report opens for anyone with its link.</p>

<?php else: ?>
    <p class="owa_rosterEmpty">
    <?php if ( $owa_active !== 'all' ): ?>
        <?php
            /*
             * EMPTY BECAUSE OF THE FILTER, which is a different thing from
             * having none at all: "none yet" to someone with several, just none
             * starred, reads as data loss.
             */
        ?>
        <?php $view->out( $owa_active === 'mine' ? 'No reports of your own.' : 'No favorites yet. Star a report to add it here.' ); ?>
    <?php elseif ( $owa_author ): ?>
        No custom reports yet.
        <a class="owa_newCustomReport"
           href="<?php echo $view->makeLink( array( 'do' => 'base.customReportEdit' ), true ); ?>">Make one</a>.
    <?php else: ?>
        No custom reports have been shared with you yet.
    <?php endif; ?>
    </p>
<?php endif; ?>

</div>

<?php if ( $owa_author && $owa_kinds ): ?>
<?php
/*
 * WHAT TO MAKE, asked before a builder opens: the kind decides what the builder
 * then asks for. The same tiles as the widget builder's type chooser, and the
 * same class names, so the two galleries read as one kind of question.
 *
 * Each tile is a link, so picking one navigates to its builder; with no
 * JavaScript the modal never opens and New opens the report builder.
 */
?>
<div id="customReportNewDialog" class="owa_typeDialog" style="display:none;">
    <ul class="owa_typeChoices owa_typeChoicesTwo">
    <?php foreach ( $owa_kinds as $owa_key => $owa_kind ): ?>
        <li>
            <a class="owa_typeChoice" data-type="<?php $view->out( $owa_key ); ?>"
               href="<?php echo $view->makeLink( $owa_kind['params'], true ); ?>">
                <span class="owa_typeChoiceArt" aria-hidden="true">
                    <i class="owa_typeChoiceIcon <?php $view->out( $owa_kind['icon'] ); ?>"></i>
                </span>
                <span class="owa_typeChoiceText">
                    <span class="owa_typeChoiceName"><?php $view->out( $owa_kind['label'] ); ?></span>
                    <span class="owa_typeChoiceHint"><?php $view->out( $owa_kind['hint'] ); ?></span>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<script>
jQuery( function () {

    var dialog = jQuery( '#customReportNewDialog' );

    if ( ! dialog.length ) {
        return;
    }

    dialog.dialog( {
        autoOpen: false,
        modal: true,
        width: Math.min( 640, jQuery( window ).width() - 32 ),
        title: 'What do you want to make?',
        // Kept inside .owa: every rule styling what is inside it is scoped
        // `.owa .owa_...`, and jQuery UI would otherwise lift it to <body>.
        appendTo: '.owa',
        dialogClass: 'owa_typeDialogFrame'
    } );

    jQuery( document ).on( 'click', '.owa_newCustomReport', function ( e ) {

        e.preventDefault();

        dialog.dialog( 'open' );
    } );
} );
</script>
<?php endif; ?>
