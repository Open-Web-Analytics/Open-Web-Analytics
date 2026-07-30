<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="<?php echo $view->dom_id;?>Container" style="width:; margin:0px; padding:0px;height:<?php echo $view->height;?>;">
    <div id="<?php echo $view->dom_id;?>"></div>
</div>

<script>
OWA.items['<?php echo $view->dom_id;?>'] = new OWA.chart();
OWA.items['<?php echo $view->dom_id;?>'].setDomId('<?php echo $view->dom_id;?>');
OWA.items['<?php echo $view->dom_id;?>'].setData(<?php echo $view->data;?>);
OWA.items['<?php echo $view->dom_id;?>'].config.ofc_version = '<?php echo OWA_OFC_VERSION;?>';

OWA.items['<?php echo $view->dom_id;?>'].render();
jQuery("#<?php echo $view->dom_id;?>").addClass('owa_ofcChart');
</script>