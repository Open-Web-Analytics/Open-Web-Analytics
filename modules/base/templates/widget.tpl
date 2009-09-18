<?php include('widget_dom.tpl');?>

<div id="<?php echo $widget;?>" class="owa_widget-container" style="width:<?php if ($params['width']): echo($params['width']); else: echo(''); endif;?>;">
	
	<div id="<?php echo $widget;?>_widget-header" class="owa_widget-header">
		<table style="width:100%">
			<TR>
				<TD>
					<span class="owa_widget-title"><?php echo $title;?></span>
				</TD>
				<TD style="text-align:right;">
					<div id="">
					<a class="owa_widget-collapsetoggle" href="#<?php echo $widget;?>_widget-header">Minimize</a>
					 |
					<a class="owa_widget-close" href="#<?php echo $widget;?>_widget-header">Close</a>
				</TD>
			</TR>
		</table>
	</div>
	
	<div class="owa_widget-innercontainer">	
		<div id="<?php echo $widget;?>_widget-status" class="owa_widget-status">
			<img src="<?php echo $this->makeImageLink("base/i/loading.gif");?>" border="0" align="ABSMIDDLE"> Loading...
		</div> 
	
		<div id="<?php echo $widget;?>_widget-content" class="owa_widget-content" style="width:<?php echo $params['width'];?>100%;"><?php echo $subview;?></div>
		
		<div id="<?php echo $widget;?>_widget-pagination" class="owa_widget-pagination"></div>
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
	</div>
	
</div>
