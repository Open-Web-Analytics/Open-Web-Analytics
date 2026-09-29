<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline">Tracking Tag</div>
<div id="panel">

<P>The Domain for this web site is: <span class=""><B><?php $view->out( $view->site->get('domain') );?></B></P>
<P>The Site ID for this web site is: <span class=""><B><?php $view->out( $view->site_id ); ?></B></P>

<div class="owa_panelIntro">
<?php if ( $view->last_event ): ?>
    <b>Last event received:</b> <?php $view->out( \OWA\Module\Base\Classes\JobStatus::readable( $view->last_event ) ); ?>.
<?php else: ?>
    <b>No events received yet.</b> Once the tag below is on your pages and one of them is visited,
    the first event shows here.
<?php endif; ?>
</div>

<?php include('invocation.php');?>
</div>
