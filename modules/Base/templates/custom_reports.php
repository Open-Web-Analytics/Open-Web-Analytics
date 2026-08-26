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

<div class="owa_reportControls">

    <span class="owa_reportControl">
        <span class="label"><?php $view->out( count( $owa_reports ) ); ?>
            <?php $view->out( count( $owa_reports ) === 1 ? 'report' : 'reports' ); ?></span>
    </span>

    <?php if ( $owa_author ): ?>
    <span class="owa_reportControl owa_reportControlRight">
        <a class="owa_button" href="<?php echo $view->makeLink( array(
            'do' => 'base.customReportEdit',
        ) ); ?>">New Custom Report</a>
    </span>
    <?php endif; ?>

    <div style="clear:both;"></div>
</div>

<?php if ( $owa_reports ): ?>

<div class="owa_reportSectionContent">
<table class="management">
    <thead>
        <tr>
            <th>Report</th>
            <th>Created by</th>
            <th>Last updated</th>
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

            <td class="data_cell">
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
    <?php if ( $owa_author ): ?>
        No custom reports yet.
        <a href="<?php echo $view->makeLink( array( 'do' => 'base.customReportEdit' ) ); ?>">Build one</a>.
    <?php else: ?>
        <?php
            /*
             * Said differently for a reader who cannot author one: "build one"
             * would point at a screen they are not allowed to open.
             */
        ?>
        No custom reports have been shared with you yet.
    <?php endif; ?>
</div>
<?php endif; ?>
