<script>
/* Widget DOM configuration for <?php echo $widget;?> */

OWA.items['<?php echo $widget;?>'] = new OWA.widget();
OWA.items['<?php echo $widget;?>'].properties = <?php echo $this->makeJson($params);?>;
OWA.items['<?php echo $widget;?>'].properties.action = "<?php echo $do;?>";
OWA.items['<?php echo $widget;?>'].current_view = "<?php echo $format;?>";
OWA.items['<?php echo $widget;?>'].dom_id = "<?php echo $widget;?>";
OWA.items['<?php echo $widget;?>'].page_num = "<?php //echo $pagination['page_num'];?>1";
OWA.items['<?php echo $widget;?>'].max_page_num = "<?php //echo $pagination['max_page_num'];?>";
OWA.items['<?php echo $widget;?>'].max_page_num = "<?php //$echo pagination['more_pages'];?>";

</script>