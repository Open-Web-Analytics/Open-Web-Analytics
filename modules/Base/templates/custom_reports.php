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
/*
 * This roster serves both kinds. A visualization is not a custom report -- it
 * computes rather than configures, and they are listed separately for exactly
 * that reason -- so the empty state has to say which one is empty, the builder
 * link has to point at the right screen, and only one of the two asks which
 * KIND before it opens.
 */
$owa_isViz     = ( $view->get('roster_type') ?: '' ) === 'visualization';
$owa_vizTypes  = (array) ( $view->get('visualization_types') ?: array() );
$owa_builder   = $owa_isViz ? 'base.visualizationEdit' : 'base.customReportEdit';
/*
 * Whether this reader may author one, which is only asked to decide what the
 * empty state says and whether to offer a builder at all. WHO OWNS a row is no
 * longer asked here: editing is the pencil on the thing itself, so the roster
 * has nothing to gate per row. The controller still decides what is LISTED.
 */
$owa_author    = (bool) $view->get('may_author');
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

                $owa_heading = function ( $key, $label ) use ( $view, $owa_sort, $owa_desc, $owa_isViz ) {

                    $active = ( $owa_sort === $key );

                    // Clicking the active column reverses it; clicking another
                    // starts that column in its own natural direction.
                    $next = $active ? ! $owa_desc : ( $key === 'updated' );

                    $url = $view->makeLink( array(
                        // Back to THIS roster: sorting the visualizations list
                        // through base.customReports would land the reader on
                        // the reports list instead.
                        'do'         => $owa_isViz ? 'base.visualizations' : 'base.customReports',
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
            <?php
                /*
                 * The first column is named for what it lists. This roster
                 * serves both kinds, and heading a list of funnels "Report" is
                 * the exact confusion the separate list exists to avoid.
                 */
            ?>
            <?php $owa_heading( 'name', $owa_isViz ? 'Visualization' : 'Report' ); ?>
            <?php $owa_heading( 'author', 'Created by' ); ?>
            <?php $owa_heading( 'updated', 'Last updated' ); ?>
            <?php
                /*
                 * No edit column.
                 *
                 * Editing is the pencil beside the title on the thing itself --
                 * see Report::withEditAction() -- which is where it acts on
                 * something the reader is looking at. A second way in from the
                 * roster made the row carry two links to two different screens
                 * and an empty cell for everyone who may not edit.
                 */
            ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $owa_reports as $owa_report ): ?>
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
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php else: ?>
<div class="owa_reportSectionContent">
    <?php $owa_noun = $owa_isViz ? 'visualizations' : 'custom reports'; ?>
    <?php if ( $owa_author ): ?>
        No <?php $view->out( $owa_noun );?> yet.
        <?php /* Hooked by the same modal as the New button; see below. */ ?>
        <a class="<?php echo $owa_isViz ? 'owa_newVisualization' : '';?>"
           href="<?php echo $view->makeLink( array( 'do' => $owa_builder ) ); ?>">Build one</a>.
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

<?php if ( $owa_isViz && $owa_author && $owa_vizTypes ): ?>
<?php
/*
 * WHICH KIND, asked before the builder opens.
 *
 * The same question, in the same shape, as the widget builder's type chooser --
 * a gallery of tiles rather than a dropdown, because the kind decides what the
 * builder then asks for and is therefore the one choice worth showing rather
 * than listing. It reuses that chooser's markup and class names on purpose:
 * two galleries that look different would say the choices differ in kind.
 *
 * There is only a funnel today, and it is still asked. A single hardcoded kind
 * would not need naming -- asking is what makes the second one cost nothing but
 * a row in VISUALIZATION_TYPES.
 */
?>
<div id="visualizationTypeDialog" class="owa_typeDialog" style="display:none;">
    <ul class="owa_typeChoices">
    <?php foreach ( $owa_vizTypes as $owa_key => $owa_label ): ?>
        <li>
            <?php
                /*
                 * An <a> with a real href, not a button.
                 *
                 * Picking a kind NAVIGATES -- the builder is its own screen --
                 * so the tile is a link, and with no JavaScript at all the
                 * modal never opens and these are never reached. That is why
                 * the New button keeps its own href.
                 */
            ?>
            <a class="owa_typeChoice" data-type="<?php $view->out( $owa_key ); ?>"
               href="<?php echo $view->makeLink( array(
                   'do'                => 'base.visualizationEdit',
                   'visualizationType' => $owa_key,
               ) ); ?>">
                <span class="owa_typeChoiceArt" aria-hidden="true">
                    <i class="owa_typeChoiceIcon <?php $view->out(
                        \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_ICONS[ $owa_key ] ?? '' ); ?>"></i>
                </span>
                <span class="owa_typeChoiceText">
                    <span class="owa_typeChoiceName"><?php $view->out( $owa_label ); ?></span>
                    <span class="owa_typeChoiceHint"><?php $view->out(
                        \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_HINTS[ $owa_key ] ?? '' ); ?></span>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<script type="text/javascript">
jQuery( function () {

    var dialog = jQuery( '#visualizationTypeDialog' );

    if ( ! dialog.length ) {
        return;
    }

    dialog.dialog( {
        autoOpen: false,
        modal: true,
        width: Math.min( 820, jQuery( window ).width() - 40 ),
        title: 'Add a visualization',
        /*
         * KEPT INSIDE .owa. jQuery UI lifts a dialog to <body> by default, and
         * every rule styling what is inside it is written `.owa .owa_...` like
         * the rest of the reporting stylesheet -- so a lifted dialog renders
         * with browser defaults and the gallery collapses to a bare list.
         */
        appendTo: '.owa',
        // The frame is built OUTSIDE this element and carries the titlebar, so
        // the chrome cannot be styled through #visualizationTypeDialog alone.
        dialogClass: 'owa_typeDialogFrame'
    } );

    jQuery( document ).on( 'click', '.owa_newVisualization', function ( e ) {

        e.preventDefault();

        dialog.dialog( 'open' );
    } );
} );
</script>
<?php endif; ?>
