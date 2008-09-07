<style>

.widget-container {border:1px solid #cccccc; width:100%;}
.widget-header {color: white;background-color:orange; padding:5px;text-align:left;}
.widget-title {font-size:20px;text-align:right;font-weight:bold;}
.widget-title a {color: white;}
.widget-title-controls {font-size:14px;text-align:right;}
.widget-controls {text-align:right;padding:10px}
.widget-content {padding:10px;}

</style>
 

<script>

//var state = new Object;

OWA.items['<?=$widget;?>'] = new OWA.widget();
OWA.items['<?=$widget;?>'].properties = <?=$this->makeJson($params);?>;
OWA.items['<?=$widget;?>'].properties.do = "<?=$do;?>";
OWA.items['<?=$widget;?>'].current_view = "<?=$format;?>";
OWA.items['<?=$widget;?>'].dom_id = "<?=$widget;?>";

</script>

<div id="<?=$widget;?>" class="widget-container">
	
	<div id="<?=$widget;?>_widget-header" class="widget-header">
		<table style="width:100%;">
			<TR>
				<TD>
					<span class="widget-title"><?=$title;?></span>
				</TD>
				<TD style="text-align:right;">
					<a class="widget-title-controls" href="">Close</a>
				</TD>
			</TR>
		</table>

	</div>

	<div id="<?=$widget;?>_widget-status" class="widget-status">LOADING</div> 

	<div id="<?=$widget;?>_widget-content" class="widget-content"><?=$subview;?></div>
	
	<div id="<?=$widget;?>_widget-controls" class="widget-controls">
		<a class="widget-control" href="#<?=$widget;?>_widget-header" name="graph">Graph</a> | 
		<a class="widget-control" href="#<?=$widget;?>_widget-header" name="table">Table</a> | 
		<a class="widget-control" href="#<?=$widget;?>_widget-header" name="sparkline">Sparkline</a>
	</div>

	
</div>
