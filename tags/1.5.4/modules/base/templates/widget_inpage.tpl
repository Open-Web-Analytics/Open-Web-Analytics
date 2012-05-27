<?php include('widget_dom.tpl');?>

<div id="<?php echo $widget;?>" class="owa_widget-container" style="width:<?php if ($params['width']): echo($params['width'].'px'); else: echo('auto'); endif;?>;">
	
	<?php if($widget_views): ?>
		<div id="<?php echo $widget;?>_widget-controls" class="owa_widget-controls">
			<?php if ($widget_views_count > 1): ?>
			<span>Views: </span>
			<?php foreach ($widget_views as $k => $v): ?>
			<a class="owa_widget-control" href="#<?php echo $widget;?>_widget-header" name="<?php echo $k;?>"><?php echo $v;?></a> / 
			<?php endforeach;?>
			<?php endif;?>
		</div>
	<?php endif; ?>
	
	<div class="owa_widget-innercontainer">	
		<div id="<?php echo $widget;?>_widget-status" class="owa_widget-status">
			<img src="<?php echo $this->makeImageLink("base/i/loading.gif");?>" border="0" align="ABSMIDDLE"> Loading...
		</div> 
	
		<div id="<?php echo $widget;?>_widget-content" class="owa_widget-content" style="height:<?php //$params['height'];?>px;"><?php echo $subview;?></div>
		
		<div id="<?php echo $widget;?>_widget-pagination" class="owa_widget-pagination"></div>
		
	</div>
	
</div>
