<script>
/* Widget DOM configuration for <?=$widget;?> */

OWA.items['<?=$widget;?>'] = new OWA.widget();
OWA.items['<?=$widget;?>'].properties = <?=$this->makeJson($params);?>;
OWA.items['<?=$widget;?>'].properties.action = "<?=$do;?>";
OWA.items['<?=$widget;?>'].current_view = "<?=$format;?>";
OWA.items['<?=$widget;?>'].dom_id = "<?=$widget;?>";
OWA.items['<?=$widget;?>'].page_num = "<?=$pagination['page_num'];?>1";
OWA.items['<?=$widget;?>'].max_page_num = "<?=$pagination['max_page_num'];?>";
OWA.items['<?=$widget;?>'].max_page_num = "<?=$pagination['more_pages'];?>";
</script>