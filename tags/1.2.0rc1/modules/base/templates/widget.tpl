<? include('widget_dom.tpl');?>

<div id="<?=$widget;?>" class="owa_widget-container" style="width:<? if ($params['width']): echo($params['width']); else: echo('auto'); endif;?>;">
	
	<div id="<?=$widget;?>_widget-header" class="owa_widget-header">
		<table style="width:100%">
			<TR>
				<TD>
					<span class="owa_widget-title"><?=$title;?></span>
				</TD>
				<TD style="text-align:right;">
					<div id="">
					<a class="owa_widget-collapsetoggle" href="#<?=$widget;?>_widget-header">Minimize</a>
					 |
					<a class="owa_widget-close" href="#<?=$widget;?>_widget-header">Close</a>
				</TD>
			</TR>
		</table>
	</div>
	
	<div class="owa_widget-innercontainer">	
		<div id="<?=$widget;?>_widget-status" class="owa_widget-status">
			<img src="<?=$this->makeImageLink("loading.gif");?>" border="0" align="ABSMIDDLE"> Loading...
		</div> 
	
		<div id="<?=$widget;?>_widget-content" class="owa_widget-content" style="width:<? $params['width'];?>;"><?=$subview;?></div>
		
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
