<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!-- Sparkline data for '<?php $view->out( $view->dom_id );?>' -->
<span id="<?php $view->out( $view->dom_id );?>"><?php echo $view->data;?></span>

<script>
/* Sparkline DOM configuration for '<?php $view->out( $view->dom_id );?>' */
OWA.items['<?php $view->out( $view->dom_id );?>'] = new OWA.sparkline();
OWA.items['<?php $view->out( $view->dom_id );?>'].setDomId('<?php $view->out( $view->dom_id );?>');
OWA.items['<?php $view->out( $view->dom_id );?>'].setWidth('<?php echo $view->width;?>');
OWA.items['<?php $view->out( $view->dom_id );?>'].setHeight('<?php echo $view->height;?>');
OWA.items['<?php $view->out( $view->dom_id );?>'].render();
</script>