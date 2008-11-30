<? include('widget_dom.tpl');?>

<div id="<?=$widget;?>" class="owa_widget-container" style="width:<? if ($params['width']): echo($params['width'].'px'); else: echo('auto'); endif;?>;">
	
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
	
	<div class="owa_widget-innercontainer">	
		<div id="<?=$widget;?>_widget-status" class="owa_widget-status">
			<img src="<?=$this->makeImageLink("loading.gif");?>" border="0" align="ABSMIDDLE"> Loading...
		</div> 
	
		<div id="<?=$widget;?>_widget-content" class="owa_widget-content" style="height:<? //$params['height'];?>px;"><?=$subview;?></div>
		
		<div id="<?=$widget;?>_widget-pagination" class="owa_widget-pagination"></div>
		
	</div>
	
</div>
