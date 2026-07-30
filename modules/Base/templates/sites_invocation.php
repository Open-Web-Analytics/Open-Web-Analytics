<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline">Tracking Tag</div>
<div id="panel">

<P>The Domain for this web site is: <span class=""><B><?php $view->out( $view->site->get('domain') );?></B></P>
<P>The Site ID for this web site is: <span class=""><B><?php $view->out( $view->site_id ); ?></B></P>

<?php include('invocation.php');?>
</div>
