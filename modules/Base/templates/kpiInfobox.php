<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if ( $view->get( 'link' ) ): ?>
<a class="kpiInfoboxLink" href="<?php $view->out($view->get('link'));?>">
<?php endif;?>

<div id="<?php $view->out( str_replace(' ', '', (string) $view->get( 'label' ) ) );?>_kpibox" class="owa_metricInfobox kpiInfobox <?php $view->out($view->get('class'));?>">

    <p class="owa_metricInfoboxLabel"><?php $view->out( $view->get( 'label' ) ); ?></p>
    <p class="owa_metricInfoboxLargeNumber"><?php $view->out( $view->get( 'number' ), false ); ?></p>
</div>

<?php if ( $view->get( 'link' ) ): ?>
</a>
<?php endif;?>