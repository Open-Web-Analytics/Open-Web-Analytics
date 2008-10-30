<script>

OWA.items['<?=$widget;?>'] = new OWA.widget();
OWA.items['<?=$widget;?>'].properties = <?=$this->makeJson($params);?>;
OWA.items['<?=$widget;?>'].properties.do = "<?=$do;?>";
OWA.items['<?=$widget;?>'].current_view = "<?=$format;?>";
OWA.items['<?=$widget;?>'].dom_id = "<?=$widget;?>";
OWA.items['<?=$widget;?>'].page_num = "<?=$pagination['page_num'];?>1";
OWA.items['<?=$widget;?>'].max_page_num = "<?=$pagination['max_page_num'];?>";
OWA.items['<?=$widget;?>'].max_page_num = "<?=$pagination['more_pages'];?>";

</script>

<div id="<?=$widget;?>" class="owa_widget-container" style="width:<? if ($params['width']): echo($params['width'].'px;'); else: echo('auto;'); endif;?>;">
	
	<div id="<?=$widget;?>_widget-header" class="owa_widget-header">
		<table style="width:100%">
			<TR>
				<TD>
					<span class="owa_widget-title"><?=$title;?></span>
				</TD>
				<TD style="text-align:right;">
					<a class="owa_widget-toggle" href="#<?=$widget;?>_widget-header">Minimize</a> |
					<a class="owa_widget-close" href="#<?=$widget;?>_widget-header">Close</a>
				</TD>
			</TR>
		</table>
	</div>
	
	<div class="owa_widget-innercontainer">	
		<div id="<?=$widget;?>_widget-status" class="owa_widget-status">
			<img src="<?=$this->makeImageLink("loading.gif");?>" border="0" align="ABSMIDDLE"> Loading...
		</div> 
	
		<div id="<?=$widget;?>_widget-content" class="owa_widget-content" style="height:<? //$params['height'];?>px;"><?=$subview;?></div>
		
		<div id="<?=$widget;?>_widget-pagination" class="owa_widget-pagination"></div>
		<? if($widget_views): ?>
		<div id="<?=$widget;?>_widget-controls" class="owa_widget-controls">
			<?php if ($widget_views_count > 1): ?>
			<span>Views: </span>
			<? foreach ($widget_views as $k => $v): ?>
			<a class="owa_widget-control" href="#<?=$widget;?>_widget-header" name="<?=$k;?>"><?=$v;?></a> / 
			<? endforeach;?>
			<?php endif;?>
		</div>
		<? endif; ?>
	</div>
	
</div>
